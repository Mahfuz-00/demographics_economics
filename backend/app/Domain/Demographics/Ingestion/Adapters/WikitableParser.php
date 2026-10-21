<?php

namespace App\Domain\Demographics\Ingestion\Adapters;

/**
 * Minimal, dependency-free parser for MediaWiki table markup.
 *
 * It exists because the vital-statistics tables are the reliable, structured part of
 * a "Demographics of ..." article, and because their shape varies: different columns,
 * different order, some with `rowspan` grouping, most with styling attributes and
 * footnotes inside cells. Parsing is therefore positional but header-driven — callers
 * map columns by header text, never by index.
 */
class WikitableParser
{
    /**
     * @return array<int, array{headers: array<int, string>, rows: array<int, array<int, string>>}>
     */
    public function parse(string $wikitext): array
    {
        $tables = [];

        foreach ($this->extractTables($wikitext) as $block) {
            $parsed = $this->parseBlock($block);

            if ($parsed !== null) {
                $tables[] = $parsed;
            }
        }

        return $tables;
    }

    /**
     * Returns the body of every top-level `{| ... |}` block, so a nested infobox or
     * table inside a cell cannot truncate the outer table.
     *
     * @return array<int, string>
     */
    private function extractTables(string $wikitext): array
    {
        $tables = [];
        $lines = preg_split('/\R/', $wikitext) ?: [];
        $depth = 0;
        $buffer = [];

        foreach ($lines as $line) {
            // Openings and closings are counted anywhere in the line, because a nested
            // table usually starts inside a data cell (`| {|`) rather than at the start
            // of a line. Counting only line-leading markers truncated the outer table.
            $opens = substr_count($line, '{|');
            $closes = substr_count($line, '|}');

            if ($depth === 0) {
                if ($opens === 0) {
                    continue;
                }

                $buffer = [$line];
                $depth = $opens - $closes;
            } else {
                $buffer[] = $line;
                $depth += $opens - $closes;
            }

            if ($depth <= 0) {
                $tables[] = implode("\n", $buffer);
                $buffer = [];
                $depth = 0;
            }
        }

        return $tables;
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}|null
     */
    private function parseBlock(string $block): ?array
    {
        $lines = preg_split('/\R/', $block) ?: [];
        $headers = [];
        $rows = [];
        $current = [];
        $carry = [];
        $mode = null;
        $nested = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // A table nested inside a cell is not part of this table's row structure.
            if ($nested > 0) {
                if (str_starts_with($trimmed, '{|')) {
                    $nested++;
                } elseif (str_starts_with($trimmed, '|}')) {
                    $nested--;
                }

                continue;
            }

            if (str_contains($trimmed, '{|') && ! str_starts_with($trimmed, '{|')) {
                $nested = 1;

                continue;
            }

            if ($trimmed === '' || str_starts_with($trimmed, '{|') || str_starts_with($trimmed, '|+') || str_starts_with($trimmed, '|}')) {
                continue;
            }

            if (str_starts_with($trimmed, '|-')) {
                if ($current !== []) {
                    $rows[] = $current;
                    $current = [];
                }
                $mode = null;

                continue;
            }

            if (str_starts_with($trimmed, '!')) {
                $mode = 'header';
                foreach (explode('!!', substr($trimmed, 1)) as $cell) {
                    $headers[] = $this->parseCell($cell)[0];
                }

                continue;
            }

            if (str_starts_with($trimmed, '|')) {
                if ($current === [] && $carry !== []) {
                    $current = $this->applyCarry($carry);
                }

                $mode = 'data';

                foreach (explode('||', substr($trimmed, 1)) as $cell) {
                    [$value, $rowspan] = $this->parseCell($cell);
                    $column = count($current);
                    $current[$column] = $value;

                    if ($rowspan > 1) {
                        $carry[$column] = ['remaining' => $rowspan - 1, 'value' => $value];
                    }
                }

                continue;
            }

            // A line with no marker continues the previous cell (common in infoboxes
            // and in wrapped header cells).
            if ($mode === 'data' && $current !== []) {
                $last = count($current) - 1;
                $current[$last] = trim($current[$last].' '.$trimmed);
            }
        }

        if ($current !== []) {
            $rows[] = $current;
        }

        if ($headers === [] || $rows === []) {
            return null;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<int, array{remaining: int, value: string}>  $carry
     * @return array<int, string>
     */
    private function applyCarry(array &$carry): array
    {
        $cells = [];

        foreach ($carry as $column => $span) {
            $cells[$column] = $span['value'];
            $span['remaining']--;

            if ($span['remaining'] <= 0) {
                unset($carry[$column]);
            } else {
                $carry[$column] = $span;
            }
        }

        ksort($cells);

        return $cells;
    }

    /**
     * @return array{0: string, 1: int} value and rowspan
     */
    private function parseCell(string $raw): array
    {
        $rowspan = 1;

        if (preg_match('/rowspan\s*=\s*"?(\d+)"?/i', $raw, $matches)) {
            $rowspan = max(1, (int) $matches[1]);
        }

        return [$this->cleanCell($this->stripAttributes($raw)), $rowspan];
    }

    private function stripAttributes(string $cell): string
    {
        if (! str_contains($cell, '|')) {
            return $cell;
        }

        $parts = explode('|', $cell);
        $first = $parts[0];

        $looksLikeAttributes = (bool) preg_match(
            '/\b(style|class|align|scope|colspan|rowspan|data-sort-value|data-sort-type|width|height|title|bgcolor|id)\s*=/i',
            $first
        );

        if ($looksLikeAttributes) {
            array_shift($parts);
        }

        return implode('|', $parts);
    }

    private function cleanCell(string $cell): string
    {
        $text = preg_replace('/<ref[^>]*\/>/i', '', $cell) ?? $cell;
        $text = preg_replace('/<ref[^>]*>.*?<\/ref>/is', '', $text) ?? $text;
        $text = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;

        // Templates that wrap a value rather than annotate it. `{{formatnum:135000000}}`
        // is the number; dropping the template with the rest would silently lose it.
        $text = preg_replace(
            '/\{\{\s*(?:formatnum|formatnum:r|val|nts|number|nts:)\s*:?\s*([^{}|]+)\}\}/i',
            '$1',
            $text
        ) ?? $text;
        $text = preg_replace('/\{\{\s*formatnum\s*:\s*([^{}]+)\}\}/i', '$1', $text) ?? $text;

        // Templates hold content, not just annotations. `{{flagcountry|India}}` and
        // `{{nowrap|United States}}` carry the country name in their last positional
        // argument, and `{{nts|1234}}` carries a number; deleting the template whole
        // loses the value. Ones with no positional argument — `{{increase}}`,
        // `{{citation needed}}` — contribute nothing and leave a space behind, which is
        // the same result as deleting them.
        $text = preg_replace_callback(
            '/\{\{([^{}]*)\}\}/',
            function (array $matches): string {
                $parts = array_map('trim', explode('|', $matches[1]));
                $templateName = strtolower((string) array_shift($parts));

                $positional = array_values(array_filter(
                    $parts,
                    fn (string $part): bool => $part !== '' && ! str_contains($part, '=')
                ));

                if ($positional !== []) {
                    // `{{n+p|disp=table|139,866|454,385}}` renders a value *and* its share
                    // as two columns. The value is the first argument; taking the last
                    // reads the denominator — which on the wealth page is the world
                    // total, so every country came out with the global figure.
                    if ($templateName === 'n+p') {
                        return (string) reset($positional);
                    }

                    return (string) end($positional);
                }

                // A template can *be* the value rather than wrap one: on the debt list
                // pages the country cell is `{{AFG}}`, a bare template whose name is the
                // ISO code. Only an all-caps 2-3 letter name is taken that way, so
                // lowercase annotations like `{{increase}}` still contribute nothing.
                $name = trim($matches[1]);

                return preg_match('/^[A-Z]{2,3}$/', $name) === 1 ? $name : ' ';
            },
            $text
        ) ?? $text;
        $text = preg_replace('/\[\[(?:[^|\]]*\|)?([^\]]*)\]\]/', '$1', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = str_replace(['&nbsp;', '&#160;', '&minus;'], [' ', ' ', '-'], $text);

        // MediaWiki markup uses the typographic minus sign in tables.
        $text = str_replace(["\u{2212}", "\u{2013}"], '-', $text);

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
