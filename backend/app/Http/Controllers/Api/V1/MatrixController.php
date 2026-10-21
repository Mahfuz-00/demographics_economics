<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ObservationPresenter;
use App\Models\Entity;
use App\Models\Indicator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The country × indicator table: one row per country, one column per indicator, filled
 * with that country's latest known value — the shape of the original spreadsheet.
 *
 * "Latest known" means the most recent period for which a source actually published a
 * figure. Periods recorded as missing are skipped so a country does not appear blank
 * merely because the newest year is unknown; each cell reports the period it came from,
 * so nothing is implied to be more current than it is.
 */
class MatrixController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'indicators' => ['nullable', 'string'],
            'entities' => ['nullable', 'string'],
            'module' => ['nullable', 'in:demographics,economy,debt,wealth'],
            'include_projections' => ['nullable', 'boolean'],
            'include_sensitive' => ['nullable', 'boolean'],
        ]);

        $includeProjections = $request->boolean('include_projections', false);
        $includeSensitive = $request->boolean('include_sensitive', true);

        $indicators = Indicator::query()
            ->with('category')
            ->when($validated['module'] ?? null, fn ($query, $module) => $query->where('indicators.module', $module))
            ->when($validated['indicators'] ?? null, function ($query, $codes): void {
                $list = array_values(array_filter(array_map('trim', explode(',', (string) $codes))));
                $query->whereIn('indicators.code', $list);
            })
            ->when(! $includeSensitive, fn ($query) => $query->where('indicators.is_sensitive', false))
            ->join('indicator_categories', 'indicator_categories.id', '=', 'indicators.category_id')
            // Column order follows the source spreadsheet, not the category tree.
            ->orderBy('indicators.display_order')
            ->orderBy('indicators.name')
            ->select('indicators.*')
            ->get();

        if ($indicators->isEmpty()) {
            return response()->json(['indicators' => [], 'entities' => [], 'rows' => [], 'meta' => ['cells' => 0]]);
        }

        $entities = Entity::query()
            /*
             * Countries and territories by default. The World/continent aggregate rows
             * live in the same table and are excluded unless asked for, so the table
             * does not silently mix a total into a list of countries.
             */
            ->when(
                ! $request->boolean('include_aggregates', false),
                fn ($query) => $query->whereIn('entity_type', ['country', 'dependency', 'territory'])
            )
            ->when($validated['entities'] ?? null, function ($query, $keys): void {
                $list = array_values(array_filter(array_map('trim', explode(',', (string) $keys))));
                $ids = Entity::query()
                    ->whereIn('iso_alpha3', array_map('strtoupper', $list))
                    ->orWhereIn('iso_alpha2', array_map('strtoupper', $list))
                    ->orWhereIn('slug', $list)
                    ->pluck('id')
                    ->all();
                $query->whereIn('id', $ids);
            })
            ->orderBy('name')
            ->get(['id', 'uuid', 'iso_alpha2', 'iso_alpha3', 'iso_numeric', 'name', 'slug', 'entity_type', 'is_aggregate', 'continent', 'subregion', 'csv_label']);

        if ($entities->isEmpty()) {
            return response()->json([
                'indicators' => $indicators->map(fn (Indicator $i): array => ObservationPresenter::indicator($i))->all(),
                'entities' => [],
                'rows' => [],
                'meta' => ['cells' => 0],
            ]);
        }

        $cells = $this->latestCells(
            $entities->pluck('id')->all(),
            $indicators->pluck('id')->all(),
            $includeProjections,
        );

        $indicatorCodes = $indicators->pluck('code', 'id');
        $rows = [];

        foreach ($entities as $entity) {
            $values = [];

            foreach ($indicatorCodes as $indicatorId => $code) {
                $cell = $cells[$entity->id.':'.$indicatorId] ?? null;

                if ($cell !== null) {
                    $values[$code] = $cell;
                }
            }

            $rows[] = [
                'entity' => ObservationPresenter::entity($entity),
                'values' => $values,
            ];
        }

        return response()->json([
            'indicators' => $indicators->map(fn (Indicator $i): array => ObservationPresenter::indicator($i))->all(),
            'entities' => $entities->map(fn (Entity $e): array => ObservationPresenter::entity($e))->all(),
            'rows' => $rows,
            'meta' => [
                'entity_count' => count($rows),
                'indicator_count' => $indicators->count(),
                'cells' => array_sum(array_map(fn (array $row): int => count($row['values']), $rows)),
                'include_projections' => $includeProjections,
                'include_aggregates' => $request->boolean('include_aggregates', false),
            ],
        ]);
    }

    /**
     * One winning observation per (entity, indicator): the newest period, then a curated
     * value, then the highest-ranked source. A window function keeps this in the database
     * rather than pulling 150k rows into PHP.
     *
     * @param  array<int, int>  $entityIds
     * @param  array<int, int>  $indicatorIds
     * @return array<string, array<string, mixed>> "entityId:indicatorId" => cell
     */
    private function latestCells(array $entityIds, array $indicatorIds, bool $includeProjections): array
    {
        $conditions = ['o.value IS NOT NULL'];
        $bindings = [];

        if (! $includeProjections) {
            $conditions[] = 'o.is_projection = false';
        }

        $conditions[] = 'o.entity_id IN ('.$this->placeholders($entityIds).')';
        $bindings = array_merge($bindings, $entityIds);

        $conditions[] = 'o.indicator_id IN ('.$this->placeholders($indicatorIds).')';
        $bindings = array_merge($bindings, $indicatorIds);

        $where = implode(' AND ', $conditions);

        $sql = <<<SQL
            WITH ranked AS (
                SELECT
                    o.entity_id,
                    o.indicator_id,
                    o.uuid,
                    o.period_key,
                    o.period_year,
                    o.period_type,
                    o.period_is_assumed,
                    o.value,
                    o.unit,
                    o.status,
                    o.is_estimate,
                    o.is_projection,
                    o.is_curated,
                    o.is_derived,
                    o.missing_reason,
                    o.source_id,
                    s.code AS source_code,
                    s.publisher_short,
                    s.credibility_rank,
                    row_number() OVER (
                        PARTITION BY o.entity_id, o.indicator_id
                        ORDER BY o.period_year DESC, o.is_curated DESC, s.credibility_rank ASC, o.source_id ASC
                    ) AS rn
                FROM observations o
                JOIN sources s ON s.id = o.source_id
                WHERE {$where}
                  AND NOT EXISTS (
                      SELECT 1 FROM observation_supersessions ss WHERE ss.superseded_id = o.id
                  )
            )
            SELECT * FROM ranked WHERE rn = 1
        SQL;

        $rows = DB::select($sql, $bindings);
        $cells = [];

        foreach ($rows as $row) {
            $cells[$row->entity_id.':'.$row->indicator_id] = [
                'value' => $row->value === null ? null : (float) $row->value,
                'period' => $row->period_key,
                'year' => (int) $row->period_year,
                'unit' => $row->unit,
                'status' => $row->status,
                'is_estimate' => (bool) $row->is_estimate,
                'is_projection' => (bool) $row->is_projection,
                'is_missing' => false,
                'period_is_assumed' => (bool) $row->period_is_assumed,
                'is_curated' => (bool) $row->is_curated,
                'is_derived' => (bool) $row->is_derived,
                'missing_reason' => $row->missing_reason,
                'observation_uuid' => $row->uuid,
                'source' => [
                    'code' => $row->source_code,
                    'publisher_short' => $row->publisher_short,
                    'credibility_rank' => (int) $row->credibility_rank,
                ],
            ];
        }

        return $cells;
    }

    /**
     * @param  array<int, int>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
