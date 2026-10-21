<?php

namespace App\Domain\Demographics\Import;

/**
 * Turns the raw text of a spreadsheet cell into something we are willing to store.
 *
 * The source data is irregular by nature: numbers carry thousands separators
 * (sometimes Indian-style, "1,00,000"), footnote markers ("2024*"), estimator
 * qualifiers ("est."), embedded reference years ("0.93/2025"), open-ended
 * comparisons ("<2008"), spreadsheet error tokens ("#REF!") and, in places,
 * nothing but prose. Everything here is conservative: when a cell cannot be read
 * as a single unambiguous value it is reported as an error so the importer can
 * quarantine it rather than invent a number.
 */
class ValueParser
{
    private const BROKEN_REFERENCE_TOKENS = ['#REF!', '#VALUE!', '#DIV/0!', '#N/A', '#NAME?', '#NULL!', '#NUM!'];

    private const MISSING_TOKENS = ['-', '--', 'n/a', 'na', 'nil', 'none', 'unknown', '..'];

    public function number(?string $raw): ParsedValue
    {
        $original = $raw;
        $text = $this->clean($raw);

        if ($text === '') {
            return ParsedValue::missing($original, 'not_collected');
        }

        if ($this->isBrokenReference($text)) {
            return ParsedValue::error('broken_reference', $original, 'Spreadsheet error token: '.$text);
        }

        if ($this->isMissingToken($text)) {
            return ParsedValue::missing($original, 'not_published');
        }

        $status = 'reported';
        $note = null;
        $isEstimate = false;

        if (preg_match('/\best(\.|imated|\.)?\b/i', $text)) {
            $status = 'estimated';
            $isEstimate = true;
            $note = 'Source marks this figure as an estimate.';
            $text = trim(preg_replace('/\best(\.|imated|\.)?\b/i', '', $text) ?? $text);
        }

        if (str_contains($text, '*')) {
            $status = $status === 'reported' ? 'preliminary' : $status;
            $note = trim(($note ?? '').' Source marks this figure with a footnote (*).') ?: null;
            $text = trim(str_replace('*', '', $text));
        }

        if ($text === '') {
            return ParsedValue::missing($original, 'not_published');
        }

        // Embedded reference year, e.g. "0.93/2025" or "1.40/1999".
        if (preg_match('#^(-?[\d.,]+)\s*/\s*(\d{4})$#', $text, $matches)) {
            $value = $this->toFloat($matches[1]);

            if ($value === null) {
                return ParsedValue::error('unparseable_value', $original, 'Could not read "'.$text.'" as a number.');
            }

            return ParsedValue::value($value, $original, (int) $matches[2], $status, $note, $isEstimate);
        }

        // Open-ended comparison, e.g. "<2008".
        if (preg_match('/^([<>]=?)\s*([\d.,]+)$/', $text, $matches)) {
            $value = $this->toFloat($matches[2]);

            if ($value === null) {
                return ParsedValue::error('unparseable_value', $original, 'Could not read "'.$text.'" as a number.');
            }

            return ParsedValue::value(
                $value,
                $original,
                null,
                $status,
                trim(($note ?? '').' Source reports this as "'.$matches[1].$matches[2].'" (open-ended).') ?: null,
                $isEstimate
            );
        }

        // A range such as "55-74" is not a single figure; refuse rather than pick an end.
        if (preg_match('/^\d+(\.\d+)?\s*[-–]\s*\d+(\.\d+)?$/', $text)) {
            return ParsedValue::error('value_is_range', $original, 'Cell holds a range ("'.$text.'"), not a single figure.');
        }

        if (! preg_match('/^-?[\d.,\s]+$/', $text)) {
            return ParsedValue::error('unparseable_value', $original, 'Cell contains non-numeric text ("'.$text.'").');
        }

        $value = $this->toFloat($text);

        if ($value === null) {
            return ParsedValue::error('unparseable_value', $original, 'Could not read "'.$text.'" as a number.');
        }

        return ParsedValue::value($value, $original, null, $status, $note, $isEstimate);
    }

    public function year(?string $raw): ParsedValue
    {
        $original = $raw;
        $text = $this->clean($raw);

        if ($text === '' || $this->isMissingToken($text)) {
            return ParsedValue::missing($original, 'not_collected');
        }

        if ($this->isBrokenReference($text)) {
            return ParsedValue::error('broken_reference', $original, 'Spreadsheet error token: '.$text);
        }

        $note = null;
        if (preg_match('/^([<>])\s*(\d{4})$/', $text, $matches)) {
            $note = 'Source reports this as "'.$matches[1].$matches[2].'" (open-ended).';
            $text = $matches[2];
        }

        if (! preg_match_all('/\b(1[5-9]\d{2}|20\d{2}|21\d{2})\b/', $text, $matches) || $matches[1] === []) {
            return ParsedValue::error('unparseable_year', $original, 'Could not read "'.$text.'" as a year.');
        }

        $years = array_map('intval', $matches[1]);
        $year = max($years);

        if (count($years) > 1) {
            $note = trim(($note ?? '').' Cell lists several years; the latest ('.$year.') was used.') ?: null;
        }

        return ParsedValue::value((float) $year, $original, $year, 'reported', $note);
    }

    public function text(?string $raw): ?string
    {
        $text = $this->clean($raw);

        return $text === '' ? null : $text;
    }

    private function clean(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        $text = str_replace(["\u{00A0}", "\u{2009}", "\u{202F}"], ' ', $raw);
        $text = str_replace("\u{FFFD}", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        // Tolerate a cell that still carries its CSV quoting, so the parser behaves
        // the same whether or not the reader stripped enclosures.
        if (strlen($text) >= 2 && str_starts_with($text, '"') && str_ends_with($text, '"')) {
            $text = trim(substr($text, 1, -1));
        }

        return $text;
    }

    private function isBrokenReference(string $text): bool
    {
        foreach (self::BROKEN_REFERENCE_TOKENS as $token) {
            if (stripos($text, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isMissingToken(string $text): bool
    {
        return in_array(mb_strtolower($text), self::MISSING_TOKENS, true);
    }

    private function toFloat(string $text): ?float
    {
        $normalized = str_replace([',', ' ', '%'], '', $text);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }
}
