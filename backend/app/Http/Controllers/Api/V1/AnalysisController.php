<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Entity;
use App\Models\Indicator;
use App\Models\Observation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-source comparison and correlation across the store.
 *
 * These answer the two questions a table of values cannot: how far apart do sources
 * disagree about the same country and year, and does one indicator move with another
 * across countries.
 *
 * On "national statistics vs UN estimate": the module's ranking is intended to support
 * exactly that, but neither the national-statistics offices nor the UN feed holds any
 * observations yet — the offices need a per-office adapter and UN WPP needs an API key.
 * Until then this compares whichever sources do hold data, in practice the World Bank
 * against the figures Wikipedia compiles from national offices. That is a real
 * comparison; it is not the one the ranking describes, and the response says so.
 */
class AnalysisController
{
    /**
     * Sources analytics must not read.
     *
     * The hand-maintained spreadsheet is a frozen compilation: its values are real, but
     * they are fixed at whatever vintage the document was compiled, and every one of them
     * carries an assumed period. An analysis built on them would present a past snapshot
     * as if it were current. Curated corrections are deliberately *not* excluded — those
     * are live editorial decisions, not a stale document.
     */
    private const EXCLUDED_SOURCES = ['manual_csv_v1'];

    /**
     * The observation query analytics run against: current rows, live sources only.
     */
    private function analyticsQuery(): Builder
    {
        return Observation::query()
            ->current()
            ->whereHas('source', fn ($query) => $query->whereNotIn('code', self::EXCLUDED_SOURCES));
    }

    /**
     * How far apart the sources are for one indicator, country by country.
     */
    public function sourceGap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'indicator' => ['required', 'string'],
            'year' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'include_derived' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $indicator = Indicator::query()->where('code', $validated['indicator'])->firstOrFail();

        // Derived values are calculated from the other sources, so counting them as an
        // independent opinion would manufacture agreement.
        $includeDerived = $request->boolean('include_derived', false);

        $base = $this->analyticsQuery()
            ->current()
            ->where('indicator_id', $indicator->id)
            ->whereNotNull('value')
            ->when(! $includeDerived, fn ($query) => $query->where('is_derived', false));

        $year = $validated['year'] ?? $this->busiestYear($indicator, $includeDerived) ?? (int) now()->year;

        $rows = (clone $base)
            ->where('period_year', $year)
            ->with('source:id,code,publisher_short,credibility_rank')
            ->get(['id', 'entity_id', 'source_id', 'value', 'is_derived']);

        $byEntity = [];

        foreach ($rows as $row) {
            $code = $row->source?->code ?? 'unknown';

            // Two rows from one source for a year are revisions; the newer one wins.
            $byEntity[$row->entity_id][$code] = [
                'value' => (float) $row->value,
                'rank' => $row->source?->credibility_rank ?? 99,
                'derived' => (bool) $row->is_derived,
            ];
        }

        $entities = Entity::query()
            ->whereIn('id', array_keys($byEntity))
            ->get(['id', 'name', 'iso_alpha3', 'slug', 'continent'])
            ->keyBy('id');

        $out = [];

        foreach ($byEntity as $entityId => $values) {
            $entity = $entities->get($entityId);

            if ($entity === null) {
                continue;
            }

            $numbers = array_map(fn (array $entry): float => $entry['value'], $values);
            $min = min($numbers);
            $max = max($numbers);

            // Percentage spread against the largest figure: a disagreement is only
            // meaningful relative to the size of the quantity.
            $spread = $max - $min;
            $pct = $max > 0 ? ($spread / $max) * 100 : ($spread > 0 ? 100.0 : 0.0);

            $out[] = [
                'entity' => [
                    'name' => $entity->name,
                    'iso_alpha3' => $entity->iso_alpha3,
                    'slug' => $entity->slug,
                    'continent' => $entity->continent,
                ],
                'values' => array_map(
                    fn (array $entry): array => ['value' => $entry['value'], 'derived' => $entry['derived']],
                    $values
                ),
                'spread' => round($spread, 4),
                'pct' => round($pct, 2),
                'sources' => count($values),
            ];
        }

        // Only entities where two or more sources actually disagree are interesting.
        $out = array_values(array_filter($out, fn (array $row): bool => $row['sources'] > 1));

        usort($out, fn (array $a, array $b): int => $b['pct'] <=> $a['pct']);

        $limit = $validated['limit'] ?? 200;

        return response()->json([
            'indicator' => [
                'code' => $indicator->code,
                'name' => $indicator->name,
                'unit' => $indicator->unit,
                'decimals' => $indicator->decimals,
            ],
            'year' => $year,
            'rows' => array_slice($out, 0, $limit),
            'meta' => [
                'comparable_entities' => count($out),
                'returned' => min(count($out), $limit),
                'median_pct' => $this->median(array_column($out, 'pct')),
                'max_pct' => $out === [] ? null : $out[0]['pct'],
                'derived_included' => $includeDerived,
                'sources_available' => $this->sourcesFor($indicator),
                'note' => 'Compares whichever sources hold a value for this indicator and year. National-statistics and UN observations are not ingested yet, so this is not the national-versus-international comparison the source ranking describes.',
            ],
        ]);
    }

    /**
     * Pearson correlation between two indicators across countries, in one year.
     *
     * Correlation is not causation and this does not pretend otherwise: it reports the
     * coefficient, the sample size and the points, so the reader can judge it.
     */
    public function correlation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'x' => ['required', 'string'],
            'y' => ['required', 'string'],
            'year' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'continent' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $x = Indicator::query()->where('code', $validated['x'])->firstOrFail();
        $y = Indicator::query()->where('code', $validated['y'])->firstOrFail();

        // Default to the most recent year where both series have values.
        $year = $validated['year'] ?? $this->latestSharedYear($x, $y);

        if ($year === null) {
            return response()->json([
                'x' => $this->indicatorPayload($x),
                'y' => $this->indicatorPayload($y),
                'year' => null,
                'n' => 0,
                'r' => null,
                'points' => [],
                'meta' => ['note' => 'The two indicators share no year with data for both.'],
            ]);
        }

        $xs = $this->servedValues($x, $year);
        $ys = $this->servedValues($y, $year);

        $shared = array_values(array_intersect(array_keys($xs), array_keys($ys)));

        $entities = Entity::query()
            ->whereIn('id', $shared)
            ->get(['id', 'name', 'iso_alpha3', 'slug', 'continent'])
            ->keyBy('id');

        $points = [];

        // Restricting to one continent answers "does this hold *within* a region" — the
        // pooled coefficient can be the opposite sign to the relationship inside a group.
        $onlyContinent = $validated['continent'] ?? null;

        foreach ($shared as $entityId) {
            $entity = $entities->get($entityId);

            if ($entity === null) {
                continue;
            }

            if ($onlyContinent !== null && $entity->continent !== $onlyContinent) {
                continue;
            }

            $points[] = [
                'entity' => [
                    'name' => $entity->name,
                    'iso_alpha3' => $entity->iso_alpha3,
                    'slug' => $entity->slug,
                    'continent' => $entity->continent,
                ],
                'x' => round($xs[$entityId], 4),
                'y' => round($ys[$entityId], 4),
            ];
        }

        $limit = $validated['limit'] ?? 1000;

        return response()->json([
            'x' => $this->indicatorPayload($x),
            'y' => $this->indicatorPayload($y),
            'year' => $year,
            'continent' => $onlyContinent,
            'n' => count($points),
            'r' => $this->pearson(array_column($points, 'x'), array_column($points, 'y')),
            'points' => array_slice($points, 0, $limit),
            'meta' => [
                'note' => 'Pearson correlation across countries for one year. Association only: no causal claim, and no adjustment for confounding.',
                'continents' => $this->continentBreakdown($points),
            ],
        ]);
    }

    /**
     * A country against its peers: same continent, similar value of a reference indicator.
     *
     * This is the "how is the economy doing for countries with similar fertility" question.
     * The peer group is deliberately narrow and stated, because a comparison group chosen
     * loosely can support any conclusion the reader already wants.
     */
    public function peers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity' => ['required', 'string'],
            'peer' => ['required', 'string'],
            'metric' => ['required', 'string'],
            'year' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'tolerance' => ['nullable', 'numeric', 'min:0'],
            'group_by' => ['nullable', 'string', 'in:both,continent,value'],
        ]);

        $entity = Entity::findByKey($validated['entity']);

        if ($entity === null) {
            return response()->json(['message' => 'Unknown entity.'], 404);
        }

        $peer = Indicator::query()->where('code', $validated['peer'])->firstOrFail();
        $metric = Indicator::query()->where('code', $validated['metric'])->firstOrFail();

        $year = $validated['year'] ?? $this->latestSharedYear($peer, $metric);

        if ($year === null) {
            return response()->json(['message' => 'The two indicators share no year with data.'], 422);
        }

        $peerValues = $this->servedValues($peer, $year);
        $metricValues = $this->servedValues($metric, $year);

        if (! isset($peerValues[$entity->id], $metricValues[$entity->id])) {
            return response()->json(['message' => 'This country has no value for one of the indicators in that year.'], 422);
        }

        $targetPeer = $peerValues[$entity->id];
        $tolerance = (float) ($validated['tolerance'] ?? max(0.5, abs($targetPeer) * 0.2));
        $groupBy = $validated['group_by'] ?? 'both';

        $candidates = Entity::query()
            ->whereIn('id', array_keys($metricValues))
            ->where('is_aggregate', false)
            ->get(['id', 'name', 'iso_alpha3', 'slug', 'continent'])
            ->keyBy('id');

        $members = [];

        foreach ($metricValues as $entityId => $metricValue) {
            if (! isset($peerValues[$entityId])) {
                continue;
            }

            $candidate = $candidates->get($entityId);

            if ($candidate === null) {
                continue;
            }

            $sameContinent = $candidate->continent !== null && $candidate->continent === $entity->continent;
            $closeInValue = abs($peerValues[$entityId] - $targetPeer) <= $tolerance;

            $include = match ($groupBy) {
                'continent' => $sameContinent,
                'value' => $closeInValue,
                default => $sameContinent && $closeInValue,
            };

            if (! $include) {
                continue;
            }

            $members[] = [
                'entity' => [
                    'name' => $candidate->name,
                    'iso_alpha3' => $candidate->iso_alpha3,
                    'slug' => $candidate->slug,
                    'continent' => $candidate->continent,
                ],
                'peer_value' => round($peerValues[$entityId], 4),
                'metric_value' => round($metricValue, 4),
                'is_target' => $entityId === $entity->id,
            ];
        }

        usort($members, fn (array $a, array $b): int => $b['metric_value'] <=> $a['metric_value']);

        $metricSet = array_column($members, 'metric_value');
        sort($metricSet);

        $below = count(array_filter($metricSet, fn (float $value): bool => $value < $metricValues[$entity->id]));

        return response()->json([
            'entity' => [
                'name' => $entity->name,
                'iso_alpha3' => $entity->iso_alpha3,
                'slug' => $entity->slug,
                'continent' => $entity->continent,
            ],
            'peer_indicator' => $this->indicatorPayload($peer),
            'metric_indicator' => $this->indicatorPayload($metric),
            'year' => $year,
            'group' => [
                'group_by' => $groupBy,
                'tolerance' => round($tolerance, 4),
                'n' => count($members),
                'target_peer_value' => round($targetPeer, 4),
                'target_metric_value' => round($metricValues[$entity->id], 4),
                'mean' => $metricSet === [] ? null : round(array_sum($metricSet) / count($metricSet), 4),
                'median' => $this->median($metricSet),
                'min' => $metricSet === [] ? null : $metricSet[0],
                'max' => $metricSet === [] ? null : $metricSet[count($metricSet) - 1],
                'target_percentile' => $metricSet === [] ? null : round(($below / count($metricSet)) * 100, 1),
            ],
            'members' => $members,
            'meta' => [
                'note' => 'The peer group is countries in the same continent whose reference value is within the stated tolerance. Small groups make a percentile unstable; read n before the ranking.',
            ],
        ]);
    }

    /**
     * Largest changes for one indicator between two years.
     *
     * Also a data check: a genuinely large move and a mis-read cell look identical in a
     * table, so anything beyond the threshold is flagged for review rather than trusted.
     */
    public function movers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'indicator' => ['required', 'string'],
            'from' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'to' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $indicator = Indicator::query()->where('code', $validated['indicator'])->firstOrFail();

        $to = $validated['to'] ?? $this->busiestYear($indicator, false) ?? (int) now()->year;
        $from = $validated['from'] ?? $to - 5;

        $start = $this->servedValues($indicator, $from);
        $end = $this->servedValues($indicator, $to);

        $entities = Entity::query()
            ->whereIn('id', array_intersect(array_keys($start), array_keys($end)))
            ->where('is_aggregate', false)
            ->get(['id', 'name', 'iso_alpha3', 'slug', 'continent'])
            ->keyBy('id');

        $rows = [];

        foreach ($end as $entityId => $endValue) {
            $entity = $entities->get($entityId);

            if ($entity === null || ! isset($start[$entityId])) {
                continue;
            }

            $startValue = $start[$entityId];

            // A percentage on a base near zero is noise, not growth.
            if (abs($startValue) < 0.000001) {
                continue;
            }

            $change = $endValue - $startValue;
            $pct = ($change / abs($startValue)) * 100;

            $rows[] = [
                'entity' => [
                    'name' => $entity->name,
                    'iso_alpha3' => $entity->iso_alpha3,
                    'slug' => $entity->slug,
                    'continent' => $entity->continent,
                ],
                'from_value' => round($startValue, 4),
                'to_value' => round($endValue, 4),
                'change' => round($change, 4),
                'pct' => round($pct, 2),
                'suspect' => abs($pct) > (float) ($validated['suspicious_above'] ?? 50),
            ];
        }

        usort($rows, fn (array $a, array $b): int => abs($b['pct']) <=> abs($a['pct']));

        $limit = $validated['limit'] ?? 20;

        return response()->json([
            'indicator' => $this->indicatorPayload($indicator),
            'from' => $from,
            'to' => $to,
            'rows' => array_slice($rows, 0, $limit),
            'meta' => [
                'comparable_entities' => count($rows),
                'flagged_for_review' => count(array_filter($rows, fn (array $row): bool => $row['suspect'])),
                'note' => 'Ranks countries by the size of the change. A flagged row may be a real event or a mis-read source cell — the provenance link is the way to tell.',
            ],
        ]);
    }

    private function indicatorPayload(Indicator $indicator): array
    {
        return [
            'code' => $indicator->code,
            'name' => $indicator->name,
            'unit' => $indicator->unit,
            'decimals' => $indicator->decimals,
            'module' => $indicator->module,
        ];
    }

    /**
     * The served value per entity for one year: curated first, then best-ranked source.
     *
     * @return array<int, float>
     */
    private function servedValues(Indicator $indicator, int $year): array
    {
        $rows = $this->analyticsQuery()
            ->current()
            ->where('indicator_id', $indicator->id)
            ->where('period_year', $year)
            ->whereNotNull('value')
            ->with('source:id,credibility_rank')
            ->get(['id', 'entity_id', 'value', 'is_curated', 'source_id']);

        $best = [];

        foreach ($rows as $row) {
            $current = $best[$row->entity_id] ?? null;
            $rank = $row->source?->credibility_rank ?? 99;

            if ($current === null
                || ($row->is_curated && ! $current['curated'])
                || ($row->is_curated === $current['curated'] && $rank < $current['rank'])) {
                $best[$row->entity_id] = [
                    'value' => (float) $row->value,
                    'rank' => $rank,
                    'curated' => (bool) $row->is_curated,
                ];
            }
        }

        return array_map(fn (array $entry): float => $entry['value'], $best);
    }

    /**
     * The year with the most values for an indicator.
     *
     * Defaulting to the *latest* year is the obvious choice and the wrong one: the newest
     * year is the thinnest, so a comparison across sources would rest on a handful of
     * countries. The year with the widest coverage is the honest default.
     */
    private function busiestYear(Indicator $indicator, bool $includeDerived): ?int
    {
        $row = $this->analyticsQuery()
            ->current()
            ->where('indicator_id', $indicator->id)
            ->whereNotNull('value')
            ->when(! $includeDerived, fn ($query) => $query->where('is_derived', false))
            ->selectRaw('period_year, count(*) as observations')
            ->groupBy('period_year')
            ->orderByDesc('observations')
            ->orderByDesc('period_year')
            ->first();

        return $row?->period_year !== null ? (int) $row->period_year : null;
    }

    /**
     * The year where the two indicators overlap for the most countries.
     *
     * @return array<int, array<int, true>>
     */
    private function yearsByEntity(Indicator $indicator): array
    {
        $out = [];

        foreach ($this->analyticsQuery()
            ->current()
            ->where('indicator_id', $indicator->id)
            ->whereNotNull('value')
            ->get(['entity_id', 'period_year']) as $row) {
            $out[$row->period_year][$row->entity_id] = true;
        }

        return $out;
    }

    private function latestSharedYear(Indicator $x, Indicator $y): ?int
    {
        $left = $this->yearsByEntity($x);
        $right = $this->yearsByEntity($y);

        $best = null;
        $bestCount = -1;

        foreach ($left as $year => $entities) {
            if (! isset($right[$year])) {
                continue;
            }

            $count = count(array_intersect_key($entities, $right[$year]));

            // Ties go to the later year: same sample, fresher data.
            if ($count > $bestCount || ($count === $bestCount && (int) $year > (int) $best)) {
                $bestCount = $count;
                $best = (int) $year;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, float|int>  $xs
     * @param  array<int, float|int>  $ys
     */
    private function pearson(array $xs, array $ys): ?float
    {
        $n = count($xs);

        if ($n < 3 || $n !== count($ys)) {
            return null;
        }

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $num = 0.0;
        $denX = 0.0;
        $denY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $meanX;
            $dy = $ys[$i] - $meanY;

            $num += $dx * $dy;
            $denX += $dx * $dx;
            $denY += $dy * $dy;
        }

        $denominator = sqrt($denX * $denY);

        if ($denominator == 0.0) {
            return null;
        }

        return round($num / $denominator, 4);
    }

    /**
     * @param  array<int, float|int>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];

        return round((float) $median, 2);
    }

    /**
     * @return array<int, array{code: string, publisher: string|null}>
     */
    private function sourcesFor(Indicator $indicator): array
    {
        return $this->analyticsQuery()
            ->where('indicator_id', $indicator->id)
            ->join('sources', 'sources.id', '=', 'observations.source_id')
            ->distinct()
            ->get(['sources.code', 'sources.publisher_short'])
            ->map(fn ($row): array => ['code' => $row->code, 'publisher' => $row->publisher_short])
            ->all();
    }

    /**
     * Correlation within each continent, as a crude check that a pooled coefficient is
     * not just a geography effect.
     *
     * @param  array<int, array{entity: array{continent: string|null}, x: float, y: float}>  $points
     * @return array<int, array{continent: string, n: int, r: float|null}>
     */
    private function continentBreakdown(array $points): array
    {
        $groups = [];

        foreach ($points as $point) {
            $groups[$point['entity']['continent'] ?? 'Unknown'][] = $point;
        }

        $out = [];

        foreach ($groups as $continent => $group) {
            if (count($group) < 3) {
                continue;
            }

            $out[] = [
                'continent' => (string) $continent,
                'n' => count($group),
                'r' => $this->pearson(array_column($group, 'x'), array_column($group, 'y')),
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['n'] <=> $a['n']);

        return $out;
    }
}
