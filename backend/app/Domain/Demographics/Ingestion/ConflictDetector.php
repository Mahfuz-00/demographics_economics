<?php

namespace App\Domain\Demographics\Ingestion;

use App\Domain\Demographics\Ingestion\Dto\WriteResult;
use App\Models\Observation;
use App\Models\ObservationConflict;

/**
 * Records disagreement between sources instead of letting the last writer win.
 *
 * When a new observation lands on a series point already held by a different
 * source, both rows are kept and a conflict row is written. The higher-ranked
 * source (national statistics before UN before Wikipedia) is designated primary,
 * so reads are deterministic while nothing is silently discarded.
 */
class ConflictDetector
{
    /**
     * Series (indicator:entity) that already hold data from a source other than the one
     * being written, keyed by that source. Loaded once per batch.
     *
     * A conflict can only exist where a second source is present, so this lets a
     * backfill of 150k rows skip almost every per-row lookup while still catching
     * every real disagreement.
     *
     * @var array<int, array<string, true>>
     */
    private array $seriesWithOtherSources = [];

    public function detect(Observation $observation, WriteResult $result): int
    {
        if ($observation->value === null) {
            return 0;
        }

        if (! $this->hasOtherSources($observation)) {
            return 0;
        }

        $others = Observation::query()
            ->current()
            ->where('entity_id', $observation->entity_id)
            ->where('indicator_id', $observation->indicator_id)
            ->where('period_key', $observation->period_key)
            ->where('source_id', '!=', $observation->source_id)
            ->whereNotNull('value')
            ->with('source')
            ->get();

        $epsilon = (float) config('demographics.conflicts.absolute_epsilon', 0.000001);
        $recorded = 0;

        foreach ($others as $other) {
            $delta = abs((float) $observation->value - (float) $other->value);

            if ($delta <= $epsilon) {
                continue;
            }

            [$primary, $conflicting] = $this->orderByPriority($observation, $other);
            $denominator = abs((float) $primary->value);

            ObservationConflict::updateOrCreate(
                [
                    'primary_observation_id' => $primary->id,
                    'conflicting_observation_id' => $conflicting->id,
                ],
                [
                    'entity_id' => $observation->entity_id,
                    'indicator_id' => $observation->indicator_id,
                    'period_key' => $observation->period_key,
                    'period_type' => $observation->period_type,
                    'period_year' => $observation->period_year,
                    'value_delta' => $delta,
                    'pct_delta' => $denominator > 0 ? ($delta / $denominator) * 100 : null,
                    'significance' => $this->significance($delta, $denominator),
                ]
            );

            $recorded++;
        }

        $result->conflicts += $recorded;

        return $recorded;
    }

    private function hasOtherSources(Observation $observation): bool
    {
        $sourceId = (int) $observation->source_id;

        if (! isset($this->seriesWithOtherSources[$sourceId])) {
            $this->seriesWithOtherSources[$sourceId] = Observation::query()
                ->where('source_id', '!=', $sourceId)
                ->select(['entity_id', 'indicator_id'])
                ->distinct()
                ->get()
                ->mapWithKeys(fn (Observation $row): array => [$row->entity_id.':'.$row->indicator_id => true])
                ->all();
        }

        return isset($this->seriesWithOtherSources[$sourceId][$observation->entity_id.':'.$observation->indicator_id]);
    }

    /**
     * @return array{0: Observation, 1: Observation}
     */
    private function orderByPriority(Observation $a, Observation $b): array
    {
        $rankA = $a->source->credibility_rank ?? 99;
        $rankB = $b->source->credibility_rank ?? 99;

        if ($rankA !== $rankB) {
            return $rankA < $rankB ? [$a, $b] : [$b, $a];
        }

        return $a->source_id <= $b->source_id ? [$a, $b] : [$b, $a];
    }

    private function significance(float $delta, float $denominator): string
    {
        if ($denominator <= 0) {
            return 'notable';
        }

        $pct = ($delta / $denominator) * 100;

        if ($pct < (float) config('demographics.conflicts.minor_below_percent', 1.0)) {
            return 'minor';
        }

        if ($pct < (float) config('demographics.conflicts.notable_below_percent', 5.0)) {
            return 'notable';
        }

        return 'major';
    }
}
