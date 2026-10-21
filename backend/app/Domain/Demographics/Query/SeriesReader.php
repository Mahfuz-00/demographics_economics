<?php

namespace App\Domain\Demographics\Query;

use App\Models\Observation;
use Illuminate\Support\Collection;

/**
 * Reads a series and decides which observation is served for each period.
 *
 * Multiple sources may hold the same series point. The served value is the one from
 * the highest-ranked source (national statistics, then UN, then others); every other
 * value for that period is returned alongside it as an alternative rather than being
 * hidden, which is what makes disagreements visible in the UI.
 */
class SeriesReader
{
    /**
     * @param  array<int, int>  $entityIds
     * @param  array{frequency?: string, from?: int|null, to?: int|null, include_projections?: bool, status?: string|null}  $options
     * @return array<int, array<int, array{primary: Observation, alternatives: array<int, Observation>}>>
     *                                                                                                    entity id => list of periods, each with a served observation
     */
    public function series(array $entityIds, int $indicatorId, array $options = []): array
    {
        if ($entityIds === []) {
            return [];
        }

        $frequency = $options['frequency'] ?? 'annual';

        $observations = Observation::query()
            ->current()
            ->whereIn('entity_id', $entityIds)
            ->where('indicator_id', $indicatorId)
            ->when($frequency !== 'all', fn ($query) => $query->where('period_type', $frequency))
            ->betweenYears($options['from'] ?? null, $options['to'] ?? null)
            ->when(
                ($options['include_projections'] ?? true) === false,
                fn ($query) => $query->where('is_projection', false)
            )
            ->when(
                ($options['status'] ?? null) !== null,
                fn ($query) => $query->where('status', $options['status'])
            )
            ->with(['source', 'sourceDataset', 'indicator.category'])
            ->orderBy('period_year')
            ->orderBy('period_quarter')
            ->orderBy('period_month')
            ->get();

        $grouped = [];

        foreach ($observations as $observation) {
            $grouped[$observation->entity_id][$observation->period_key][] = $observation;
        }

        $result = [];

        foreach ($grouped as $entityId => $periods) {
            ksort($periods);

            foreach ($periods as $rows) {
                $ordered = $this->orderByPriority($rows);

                $result[$entityId][] = [
                    'primary' => $ordered[0],
                    'alternatives' => array_slice($ordered, 1),
                ];
            }
        }

        return $result;
    }

    /**
     * Latest served observation per series, ignoring projections unless asked for.
     *
     * @param  array<int, int>  $entityIds
     * @param  array<int, int>  $indicatorIds
     * @return array<string, array{primary: Observation, alternatives: array<int, Observation>}>
     *                                                                                           "entityId:indicatorId" => latest period
     */
    public function latest(array $entityIds, array $indicatorIds, bool $includeProjections = false): array
    {
        if ($entityIds === [] || $indicatorIds === []) {
            return [];
        }

        $observations = Observation::query()
            ->current()
            ->whereIn('entity_id', $entityIds)
            ->whereIn('indicator_id', $indicatorIds)
            ->where('period_type', 'annual')
            ->whereNotNull('value')
            ->when(! $includeProjections, fn ($query) => $query->onlyActuals())
            ->with(['source', 'sourceDataset', 'indicator.category'])
            ->orderByDesc('period_year')
            ->get();

        $grouped = [];

        foreach ($observations as $observation) {
            $grouped[$observation->entity_id.':'.$observation->indicator_id][$observation->period_key][] = $observation;
        }

        $result = [];

        foreach ($grouped as $key => $periods) {
            krsort($periods);
            $rows = reset($periods);
            $ordered = $this->orderByPriority($rows);

            $result[$key] = [
                'primary' => $ordered[0],
                'alternatives' => array_slice($ordered, 1),
            ];
        }

        return $result;
    }

    /**
     * Orders competing observations for one series point: curated first, then by the
     * source's credibility rank. Exposed so any view that resolves "which value wins"
     * uses exactly the same rule as the series endpoint.
     *
     * @param  Collection<int, Observation>|array<int, Observation>  $rows
     * @return array<int, Observation>
     */
    public function orderByPriority(Collection|array $rows): array
    {
        $ordered = $rows instanceof Collection ? $rows->all() : $rows;

        usort($ordered, function (Observation $a, Observation $b): int {
            // A value a human has explicitly curated outranks every source's value.
            if ($a->is_curated !== $b->is_curated) {
                return $a->is_curated ? -1 : 1;
            }

            $rankA = $a->source->credibility_rank ?? 99;
            $rankB = $b->source->credibility_rank ?? 99;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return $a->source_id <=> $b->source_id;
        });

        return $ordered;
    }
}
