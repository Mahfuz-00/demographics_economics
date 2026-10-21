<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns a name as written upstream (spreadsheet, Wikipedia, an API) into a
 * canonical lookup key.
 *
 * The manual spreadsheet is full of near-misses: footnote markers, dependency
 * prefixes such as "(UK)", embedded commentary such as
 * "Russia (Crimea in 2020 was 24,16,856)", inconsistent accents and outright
 * misspellings ("Czeck Republic", "Ethopia"). Normalising both sides before
 * lookup means those collapse onto one key instead of needing bespoke rules.
 */
class EntityNameNormalizer
{
    private const FOOTNOTE_MARKERS = ['*', '†', '‡', '§', '¶'];

    public static function normalize(?string $name): string
    {
        $name = (string) $name;

        // Undecodable bytes become a space so accented words still line up
        // ("Cote d?Ivoire" -> "Cote d Ivoire").
        $name = str_replace(["\u{FFFD}", '?'], ' ', $name);

        $name = str_replace(self::FOOTNOTE_MARKERS, '', $name);

        // Drop parenthetical and bracketed asides: "(UK)", "(France)",
        // "(Crimea in 2020 was 24,16,856)", "[2019]".
        $name = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $name) ?? $name;

        // Accented and unaccented spellings converge.
        $name = Str::ascii($name);

        // Apostrophes become separators so "Cote d'Ivoire" == "Cote d Ivoire".
        $name = str_replace(["'", '’', '‘', '`', '"'], ' ', $name);

        $name = preg_replace('/[.,;:]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-zA-Z0-9\s\-\/]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return mb_strtolower(trim($name));
    }

    /**
     * Header/column key. Unlike `normalize`, this keeps parenthetical qualifiers,
     * because the source has distinct columns named "By year" and
     * "By Year (Immigrants)" that must not collapse onto one another.
     */
    public static function columnKey(?string $label): string
    {
        $label = str_replace(["\u{FFFD}", "\u{00A0}", '*'], ' ', (string) $label);
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

        return mb_strtolower(trim($label));
    }

    /**
     * Reinterpret bytes that were decoded as UTF-8 but are really Windows-1252.
     * Only useful on raw file contents; a string that already lost bytes to the
     * UTF-8 replacement character cannot be repaired.
     */
    public static function toUtf8(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
