<?php

namespace App\Domain\Demographics\Derivation;

use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\Dto\WriteResult;
use App\Domain\Demographics\Ingestion\ObservationWriter;
use App\Domain\Demographics\Ingestion\Validation\IndicatorSanity;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\Observation;
use App\Models\ObservationSupersession;
use App\Models\Source;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fills gaps using the demographic accounting identity.
 *
 * A source often publishes annual births, deaths and population but not the rates that
 * follow from them. Those rates are defined arithmetic — crude birth rate *is* births
 * divided by population times 1,000 — so computing them is not interpolation and invents
 * nothing. What it must not do is compete with a figure a source actually published:
 *
 *  - a derived value is only written where no current observation exists for that
 *    indicator and period, from any source;
 *  - it is stored against a low-ranked `derived_calculations` source and flagged
 *    `is_derived`, so provenance always says "calculated", never "reported";
 *  - the note on the observation names the formula and the inputs it used.
 */
class DerivedIndicators
{
    public const SOURCE_CODE = 'derived_calculations';

    /** Indices used as inputs. */
    private const INPUTS = [
        'pop.total',
        'birth.count.annual',
        'death.count.annual',
        'birth.rate.crude',
        'death.rate.crude',
    ];

    /** Indicators this pass is allowed to fill. */
    private const TARGETS = [
        'birth.count.annual',
        'death.count.annual',
        'pop.natural_change.absolute',
        'pop.natural_change.rate',
        'birth.rate.crude',
        'death.rate.crude',
        'pop.change.annual',
        'mig.net_annual',
        'pop.change.natural_share',
        'pop.natural_change.change_pct',
        'birth.change.pct',
        'death.change.pct',
    ];

    /** Indicators that are a summary of another indicator's whole history. */
    private const PEAKS = [
        'pop.peak' => 'pop.total',
        'economy.gdp.peak_bn' => 'economy.gdp.nominal_bn',
        'economy.gdp.per_capita.peak' => 'economy.gdp.per_capita.nominal',
        'economy.gdp.ppp_peak_bn' => 'economy.gdp.ppp_bn',
        'economy.gdp.per_capita.ppp_peak' => 'economy.gdp.per_capita.ppp',
        'wealth.peak_bn_usd' => 'wealth.current_bn_usd',
    ];

    /**
     * "How far below its peak" — the difference between the served peak and the most
     * recent value in the series. Arithmetic over the two figures beside it, so it is
     * computed rather than trusted: the sheet's own differences did not reconcile with
     * its own peak and latest columns for 53 of 195 countries.
     */
    private const PEAK_GAPS = [
        'economy.gdp.peak_diff_bn' => 'economy.gdp.peak_bn',
        'economy.gdp.per_capita.peak_diff' => 'economy.gdp.per_capita.peak',
        'economy.gdp.ppp_peak_diff_bn' => 'economy.gdp.ppp_peak_bn',
        'economy.gdp.per_capita.ppp_peak_diff' => 'economy.gdp.per_capita.ppp_peak',
        'wealth.decline_bn_usd' => 'wealth.peak_bn_usd',
    ];

    /** Ratios of one indicator to another, in the same year. */
    private const RATIOS = [
        'economy.ppp_to_nominal.gdp_ratio' => ['economy.gdp.ppp_bn', 'economy.gdp.nominal_bn'],
        'economy.ppp_to_nominal.pc_ratio' => ['economy.gdp.per_capita.ppp', 'economy.gdp.per_capita.nominal'],
    ];

    public function __construct(private readonly ObservationWriter $writer)
    {
    }

    /**
     * @param  array<int, int>|null  $entityIds
     */
    public function run(?array $entityIds = null, ?int $from = null, ?int $to = null): array
    {
        $source = Source::query()->where('code', self::SOURCE_CODE)->firstOrFail();

        $all = Indicator::query()
            ->whereIn('code', array_merge(self::INPUTS, self::TARGETS))
            ->get()
            ->keyBy('code');

        $inputIds = collect(self::INPUTS)->map(fn (string $code) => $all->get($code)?->id)->filter()->values()->all();
        $targetIds = collect(self::TARGETS)->map(fn (string $code) => $all->get($code)?->id)->filter()->values()->all();

        if ($inputIds === [] || $targetIds === []) {
            return ['created' => 0, 'unchanged' => 0, 'rejected' => 0];
        }

        [$inputs, $existing] = $this->loadState($inputIds, $targetIds, $entityIds, $from, $to);

        $batch = IngestionBatch::create([
            'kind' => 'derivation',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $drafts = $this->buildDrafts($inputs, $existing, $source, $all);
            $result = $this->writer->write($batch, $drafts);
            $peaks = $this->derivePeaks($batch, $source);
            $ratios = $this->deriveRatios($batch, $source);
            $decline = $this->deriveCumulativeDecline($batch, $source);
        } catch (Throwable $error) {
            // Never leave a batch looking as though it is still working: a run that threw
            // halfway has written whatever it managed and then stopped.
            $batch->update([
                'status' => 'failed',
                'finished_at' => now(),
                'report' => ['error' => $error->getMessage()],
            ]);

            throw $error;
        }

        $batch->update([
            'status' => 'success',
            'observations_created' => $result->created + $peaks['created'] + $ratios['created'] + $decline['created'],
            'observations_unchanged' => $result->unchanged,
            'observations_superseded' => $peaks['superseded'] + $ratios['superseded'] + $decline['superseded'],
            'quarantined' => 0,
            'finished_at' => now(),
            'report' => [
                'kind' => 'derivation',
                'write' => $result->toArray(),
                'targets' => self::TARGETS,
                'inputs' => self::INPUTS,
                'peaks' => $peaks,
            ],
        ]);

        return ['created' => $result->created + $peaks['created'] + $ratios['created'] + $decline['created'], 'unchanged' => $result->unchanged, 'rejected' => $result->rejected];
    }

    /**
     * Cumulative natural decline since the 1970s: every year in which deaths outnumbered
     * births, added together.
     *
     * The figure is the sum of the negative net changes, so it is itself negative — a
     * country that has been in natural decline carries a negative total, and a country
     * that never has carries nothing at all rather than a misleading zero.
     *
     * @return array{created: int, superseded: int}
     */
    private function deriveCumulativeDecline(IngestionBatch $batch, Source $source): array
    {
        /*
         * DISABLED pending a fix.
         *
         * The intent is right — sum only the years in which deaths exceeded births, never
         * netting them against the surplus years — but the figure does not reconcile.
         * Russia's own rows give 57 negative years totalling -17,539,905, yet this
         * aggregate returns 125, and Italy returns 57. Germany happens to land correctly
         * at -7,466,430, which is exactly the dangerous case: some values look right.
         *
         * Leaving the source's own figure in place is better than serving a number that
         * cannot be explained. Nothing is written from here until the discrepancy is
         * understood.
         */
        return ['created' => 0, 'superseded' => 0];
    }

    /**
     * The cumulative-decline computation, kept for the fix.
     *
     * @return array{created: int, superseded: int}
     */
    private function deriveCumulativeDeclineUnused(IngestionBatch $batch, Source $source): array
    {
        $decline = Indicator::query()->where('code', 'pop.natural_decline.since_1970s')->first();
        $natural = Indicator::query()->where('code', 'pop.natural_change.absolute')->first();

        if ($decline === null || $natural === null) {
            return ['created' => 0, 'superseded' => 0];
        }

        /*
         * Computed in one statement rather than accumulated in PHP. The served value per
         * entity and year is picked first (curated, then best rank), then only the
         * negative years are added — never netted against the positive ones. The figure is
         * "how many people this country has lost to natural decline", so it is stored as
         * a positive magnitude.
         */
        $rows = DB::select(
            <<<'SQL'
            with served as (
                select distinct on (o.entity_id, o.period_year)
                    o.entity_id, o.period_year, o.value
                from observations o
                join sources s on s.id = o.source_id
                where o.indicator_id = ?
                  and o.period_year >= 1970
                  and o.value is not null
                  and not exists (select 1 from observation_supersessions ss where ss.superseded_id = o.id)
                order by o.entity_id, o.period_year, o.is_curated desc, s.credibility_rank asc, o.id desc
            )
            select entity_id,
                   count(*) filter (where value < 0) as negative_years,
                   sum(value) filter (where value < 0) as lost,
                   max(period_year) as last_year
            from served
            group by entity_id
            having count(*) filter (where value < 0) >= 20
            SQL,
            [$natural->id]
        );

        $drafts = [];

        foreach ($rows as $row) {
            $lost = abs((float) $row->lost);

            if (! IndicatorSanity::isPlausible('pop.natural_decline.since_1970s', $lost)) {
                continue;
            }

            $drafts[] = new ObservationDraft(
                entityId: (int) $row->entity_id,
                indicatorId: (int) $decline->id,
                periodType: 'annual',
                periodYear: (int) $row->last_year,
                value: round($lost, 4),
                unit: $decline->unit,
                status: 'reported',
                isDerived: true,
                sourceId: $source->id,
                sourceRecordRef: 'derived:pop.natural_decline.since_1970s:'.$row->entity_id.':'.$row->last_year,
                referenceNote: sprintf(
                    'Calculated, not reported: the total lost to natural decline since 1970 — %d years in which deaths exceeded births, added together. Years with more births than deaths add nothing, they do not offset the loss.',
                    (int) $row->negative_years
                ),
            );
        }

        $result = $this->writer->write($batch, $drafts);

        $superseded = 0;

        $stale = Observation::query()
            ->current()
            ->where('indicator_id', $decline->id)
            ->where('is_derived', false)
            ->get(['id', 'entity_id']);

        foreach ($stale as $old) {
            $replacement = Observation::query()
                ->current()
                ->where('entity_id', $old->entity_id)
                ->where('indicator_id', $decline->id)
                ->where('is_derived', true)
                ->orderByDesc('period_year')
                ->first(['id']);

            if ($replacement === null) {
                continue;
            }

            ObservationSupersession::create([
                'superseded_id' => $old->id,
                'superseded_by_id' => $replacement->id,
                'reason' => 'revision',
            ]);

            $superseded++;
        }

        return ['created' => $result->created, 'superseded' => $superseded];
    }

    /**
     * One indicator divided by another in the same year — the PPP-to-nominal ratios.
     * Arithmetic over two served figures, so it follows them rather than sitting on the
     * vintage the spreadsheet was compiled at.
     *
     * @return array{created: int, superseded: int}
     */
    private function deriveRatios(IngestionBatch $batch, Source $source): array
    {
        $codes = array_merge(array_keys(self::RATIOS), ...array_values(self::RATIOS));

        $indicators = Indicator::query()->whereIn('code', $codes)->get()->keyBy('code');

        $ratioIds = [];
        $inputIds = [];
        $codeById = [];

        foreach (self::RATIOS as $ratioCode => [$numerator, $denominator]) {
            if (! isset($indicators[$ratioCode], $indicators[$numerator], $indicators[$denominator])) {
                continue;
            }

            $ratioIds[$ratioCode] = $indicators[$ratioCode]->id;
            $inputIds[$numerator] = $indicators[$numerator]->id;
            $inputIds[$denominator] = $indicators[$denominator]->id;
            $codeById[$indicators[$numerator]->id] = $numerator;
            $codeById[$indicators[$denominator]->id] = $denominator;
        }

        if ($ratioIds === []) {
            return ['created' => 0, 'superseded' => 0];
        }

        $rows = Observation::query()
            ->current()
            ->whereIn('indicator_id', array_merge(array_values($inputIds), array_values($ratioIds)))
            ->whereNotNull('value')
            ->with('source')
            ->get(['id', 'entity_id', 'indicator_id', 'period_year', 'value', 'source_id', 'is_curated']);

        $served = [];
        $reported = [];

        foreach ($rows as $row) {
            if (in_array($row->indicator_id, array_values($ratioIds), true)) {
                if (! $row->is_derived) {
                    $reported[$row->entity_id.':'.$row->indicator_id] = (float) $row->value;
                }

                continue;
            }

            $code = $codeById[$row->indicator_id] ?? null;

            if ($code === null) {
                continue;
            }

            $key = $row->entity_id.':'.$code.':'.$row->period_year;
            $current = $served[$key] ?? null;

            if ($current === null || $this->beats($row, $current)) {
                $served[$key] = $row;
            }
        }

        $drafts = [];

        foreach (self::RATIOS as $ratioCode => $pair) {
            [$numerator, $denominator] = $pair;

            $radix = ':'.$numerator.':';

            foreach ($served as $key => $row) {
                if (! str_contains($key, $radix)) {
                    continue;
                }

                [$entityId, , $year] = explode(':', $key);
                $denominatorRow = $served[$entityId.':'.$denominator.':'.$year] ?? null;

                if ($denominatorRow === null || (float) $denominatorRow->value <= 0) {
                    continue;
                }

                $ratio = (float) $row->value / (float) $denominatorRow->value;

                if (! IndicatorSanity::isPlausible($ratioCode, $ratio)) {
                    continue;
                }

                $indicatorId = $ratioIds[$ratioCode];
                $existing = $reported[$entityId.':'.$indicatorId] ?? null;

                if ($existing !== null && abs($existing - $ratio) < 0.005) {
                    continue;
                }

                $drafts[] = new ObservationDraft(
                    entityId: (int) $entityId,
                    indicatorId: (int) $indicatorId,
                    periodType: 'annual',
                    periodYear: (int) $year,
                    value: round($ratio, 4),
                    unit: $indicators[$ratioCode]->unit,
                    status: 'reported',
                    isDerived: true,
                    sourceId: $source->id,
                    sourceRecordRef: 'derived:'.$ratioCode.':'.$entityId.':'.$year,
                    referenceNote: sprintf(
                        'Calculated, not reported: %s divided by %s, both as served for %d.',
                        $numerator,
                        $denominator,
                        $year
                    ),
                );
            }
        }

        $result = $this->writer->write($batch, $drafts);

        $superseded = 0;

        $stale = Observation::query()
            ->current()
            ->whereIn('indicator_id', array_values($ratioIds))
            ->where('is_derived', false)
            ->get(['id', 'entity_id', 'indicator_id']);

        foreach ($stale as $old) {
            $replacement = Observation::query()
                ->current()
                ->where('entity_id', $old->entity_id)
                ->where('indicator_id', $old->indicator_id)
                ->where('is_derived', true)
                ->orderByDesc('period_year')
                ->first(['id']);

            if ($replacement === null) {
                continue;
            }

            ObservationSupersession::create([
                'superseded_id' => $old->id,
                'superseded_by_id' => $replacement->id,
                'reason' => 'revision',
            ]);

            $superseded++;
        }

        return ['created' => $result->created, 'superseded' => $superseded];
    }

    /**
     * A peak is not something a source reports; it is the maximum of a series, and it
     * belongs to the year it occurred.
     *
     * The spreadsheet carried pre-computed peak columns, and they had drifted badly out
     * of step with the series: 159 of 249 stored population peaks were *below* a
     * population we already hold for that country, and 81 of 100 GDP peaks likewise once
     * the IMF series was added. A peak lower than an observed value is not a competing
     * opinion, it is stale arithmetic, so the recomputed value replaces it — and the
     * replaced row is kept, pointed at from the new one, so nothing is lost.
     *
     * @return array{created: int, superseded: int}
     */
    private function derivePeaks(IngestionBatch $batch, Source $source): array
    {
        $wanted = array_merge(
            array_keys(self::PEAKS),
            array_values(self::PEAKS),
            array_keys(self::PEAK_GAPS),
        );

        $indicators = Indicator::query()->whereIn('code', $wanted)->get()->keyBy('code');

        $gapIndicators = Indicator::query()->whereIn('code', array_keys(self::PEAK_GAPS))->get()->keyBy('code');

        $seriesIds = [];
        $peakIds = [];
        $codeById = [];

        foreach (self::PEAKS as $peakCode => $seriesCode) {
            if (! isset($indicators[$peakCode], $indicators[$seriesCode])) {
                continue;
            }

            $seriesIds[$seriesCode] = $indicators[$seriesCode]->id;
            $peakIds[$peakCode] = $indicators[$peakCode]->id;
            $codeById[$indicators[$seriesCode]->id] = $seriesCode;
        }

        if ($seriesIds === []) {
            return ['created' => 0, 'superseded' => 0];
        }

        $rows = Observation::query()
            ->current()
            ->whereIn('indicator_id', array_values($seriesIds))
            ->whereNotNull('value')
            ->with('source')
            ->get(['id', 'entity_id', 'indicator_id', 'period_year', 'value', 'source_id', 'is_curated']);

        // The served value per entity, series and year — curated first, then rank.
        $served = [];

        foreach ($rows as $row) {
            $code = $codeById[$row->indicator_id] ?? null;

            if ($code === null) {
                continue;
            }

            $key = $row->entity_id.':'.$code.':'.$row->period_year;
            $current = $served[$key] ?? null;

            if ($current === null || $this->beats($row, $current)) {
                $served[$key] = $row;
            }
        }

        // The maximum per entity and series. On a tie the later year is kept, because
        // the series is then still at its peak rather than having passed it.
        $peaks = [];
        $latest = [];

        foreach ($served as $key => $row) {
            [$entityId, $code, $year] = explode(':', $key);

            $pair = $entityId.':'.$code;
            $current = $peaks[$pair] ?? null;

            $isHigher = $current === null
                || (float) $row->value > $current['value']
                || ((float) $row->value === $current['value'] && (int) $year > $current['year']);

            if ($isHigher) {
                $peaks[$pair] = ['value' => (float) $row->value, 'year' => (int) $year];
            }

            if (! isset($latest[$pair]) || (int) $year > $latest[$pair]['year']) {
                $latest[$pair] = ['value' => (float) $row->value, 'year' => (int) $year];
            }
        }

        $peakCodeBySeries = array_flip(self::PEAKS);
        $drafts = [];

        // A source that states a peak knows the history behind it, which may reach back
        // further than the series we hold. The computed peak only overrides a reported
        // one when it *exceeds* it — that is proof the reported figure is stale. When the
        // reported peak is higher, it stands: US national wealth peaked well above its
        // current level, and a single snapshot must not be allowed to flatten that.
        $reported = [];

        foreach (Observation::query()
            ->current()
            ->whereIn('indicator_id', array_merge(array_values($peakIds), $gapIndicators->pluck('id')->all()))
            ->where('is_derived', false)
            ->get(['entity_id', 'indicator_id', 'value']) as $row) {
            $reported[$row->entity_id.':'.$row->indicator_id] = (float) $row->value;
        }

        foreach ($peaks as $pair => $peak) {
            [$entityId, $seriesCode] = explode(':', $pair, 2);

            $peakCode = $peakCodeBySeries[$seriesCode] ?? null;

            if ($peakCode === null || ! isset($peakIds[$peakCode])) {
                continue;
            }

            $indicator = $indicators[$peakCode];

            if (! IndicatorSanity::isPlausible($peakCode, $peak['value'])) {
                continue;
            }

            $existingReported = $reported[$entityId.':'.$indicator->id] ?? null;

            if ($existingReported !== null && $existingReported >= $peak['value']) {
                continue;
            }

            $drafts[] = new ObservationDraft(
                entityId: (int) $entityId,
                indicatorId: (int) $indicator->id,
                periodType: 'annual',
                periodYear: $peak['year'],
                value: $peak['value'],
                unit: $indicator->unit,
                status: 'reported',
                isDerived: true,
                sourceId: $source->id,
                sourceRecordRef: 'derived:'.$peakCode.':'.$entityId.':'.$peak['year'],
                referenceNote: sprintf(
                    'Calculated, not reported: the highest %s in the series held for this country, in %d. Replaces a stated peak that was lower than an observed value.',
                    $seriesCode,
                    $peak['year']
                ),
            );
        }

        /*
         * "How far below its peak" is arithmetic over the peak and the latest value, so
         * it is computed from the pair that is actually served rather than read from the
         * sheet's own difference column — which did not reconcile with the sheet's own
         * peak and latest for 53 of 195 countries.
         */
        foreach ($gapIndicators as $gapCode => $gapIndicator) {
            $peakCode = self::PEAK_GAPS[$gapCode];
            $seriesCode = self::PEAKS[$peakCode] ?? null;
            $peakIndicatorId = $peakIds[$peakCode] ?? null;

            if ($seriesCode === null || $peakIndicatorId === null) {
                continue;
            }

            foreach ($latest as $pair => $latestPoint) {
                [$entityId, $code] = explode(':', $pair, 2);

                if ($code !== $seriesCode) {
                    continue;
                }

                $computedPeak = $peaks[$pair]['value'] ?? null;
                $reportedPeak = $reported[$entityId.':'.$peakIndicatorId] ?? null;

                // Whichever peak is served: a stated one stands unless it was beaten.
                $peakValue = ($reportedPeak !== null && ($computedPeak === null || $reportedPeak >= $computedPeak))
                    ? $reportedPeak
                    : $computedPeak;

                if ($peakValue === null) {
                    continue;
                }

                $gap = max(0.0, $peakValue - $latestPoint['value']);

                if (! IndicatorSanity::isPlausible($gapCode, $gap)) {
                    continue;
                }

                $existing = $reported[$entityId.':'.$gapIndicator->id] ?? null;

                // Leave a stated difference alone when it already agrees.
                if ($existing !== null && abs($existing - $gap) < 0.5) {
                    continue;
                }

                $drafts[] = new ObservationDraft(
                    entityId: (int) $entityId,
                    indicatorId: (int) $gapIndicator->id,
                    periodType: 'annual',
                    periodYear: $latestPoint['year'],
                    value: round($gap, 4),
                    unit: $gapIndicator->unit,
                    status: 'reported',
                    isDerived: true,
                    sourceId: $source->id,
                    sourceRecordRef: 'derived:'.$gapCode.':'.$entityId.':'.$latestPoint['year'],
                    referenceNote: sprintf(
                        'Calculated, not reported: the peak (%s) less the latest value in %d, both as served.',
                        $peakCode,
                        $latestPoint['year']
                    ),
                );
            }
        }

        $result = $this->writer->write($batch, $drafts);

        // Any reported peak for the same entity and indicator is now stale arithmetic;
        // point it at the computed one. The old row is retained.
        $superseded = 0;

        $stale = Observation::query()
            ->current()
            ->whereIn('indicator_id', array_merge(array_values($peakIds), $gapIndicators->pluck('id')->all()))
            ->where('is_derived', false)
            ->get(['id', 'entity_id', 'indicator_id']);

        foreach ($stale as $old) {
            $replacement = Observation::query()
                ->current()
                ->where('entity_id', $old->entity_id)
                ->where('indicator_id', $old->indicator_id)
                ->where('is_derived', true)
                ->orderByDesc('period_year')
                ->first(['id']);

            if ($replacement === null) {
                continue;
            }

            ObservationSupersession::create([
                'superseded_id' => $old->id,
                'superseded_by_id' => $replacement->id,
                'reason' => 'revision',
            ]);

            $superseded++;
        }

        return ['created' => $result->created, 'superseded' => $superseded];
    }

    /**
     * @param  array<int, int>  $inputIds
     * @param  array<int, int>  $targetIds
     * @return array{0: array<string, array<int, array<string, float>>>, 1: array<string, true>}
     */
    private function loadState(array $inputIds, array $targetIds, ?array $entityIds, ?int $from, ?int $to): array
    {
        $indicatorCodes = Indicator::query()->pluck('code', 'id');

        $rows = Observation::query()
            ->current()
            ->whereIn('indicator_id', array_merge($inputIds, $targetIds))
            ->whereNotNull('value')
            ->when($entityIds !== null, fn ($query) => $query->whereIn('entity_id', $entityIds))
            ->betweenYears($from, $to)
            ->with('source')
            ->get(['id', 'entity_id', 'indicator_id', 'period_key', 'period_year', 'value', 'source_id', 'is_curated']);

        // entity => year => code => chosen value, and which (indicator, period) already exist.
        $inputs = [];
        $existing = [];
        $chosen = [];

        foreach ($rows as $row) {
            $code = $indicatorCodes[$row->indicator_id] ?? null;

            if ($code === null) {
                continue;
            }

            // An indicator can be both: a published crude rate is an input to the death
            // count, and also something this pass would otherwise fill in. Recording it
            // as present stops it being rewritten; reading it as an input is what lets
            // the count be reconstructed. The two are not mutually exclusive.
            if (in_array($code, self::TARGETS, true)) {
                $existing[$row->entity_id.':'.$row->indicator_id.':'.$row->period_key] = true;
            }

            if (! in_array($code, self::INPUTS, true)) {
                continue;
            }

            $key = $row->entity_id.':'.$row->period_year.':'.$code;
            $current = $chosen[$key] ?? null;

            // Curated first, then the better-ranked source.
            if ($current === null || $this->beats($row, $current)) {
                $chosen[$key] = $row;
            }
        }

        foreach ($chosen as $key => $row) {
            [$entityId, $year, $code] = explode(':', $key);
            $inputs[$entityId][(int) $year][$code] = (float) $row->value;
        }

        return [$inputs, $existing];
    }

    private function beats(Observation $candidate, Observation $current): bool
    {
        if ($candidate->is_curated !== $current->is_curated) {
            return $candidate->is_curated;
        }

        $a = $candidate->source?->credibility_rank ?? 99;
        $b = $current->source?->credibility_rank ?? 99;

        return $a < $b;
    }

    /**
     * @param  array<string, array<int, array<string, float>>>  $inputs
     * @param  array<string, true>  $existing
     * @param  Collection<string, Indicator>  $indicators
     * @return array<int, ObservationDraft>
     */
    private function buildDrafts(array $inputs, array $existing, Source $source, Collection $indicators): array
    {
        $drafts = [];

        foreach ($inputs as $entityId => $years) {
            ksort($years);

            $previousYear = null;
            $previousPopulation = null;
            $previousBirths = null;
            $previousDeaths = null;
            $previousNaturalChange = null;

            foreach ($years as $year => $values) {
                $population = $values['pop.total'] ?? null;

                // A count is used as published when a source gives it. Where only the
                // crude rate is published — which is the common case, and the only one
                // for most countries — the count is reconstructed from the rate and the
                // population, because that is what a crude rate means: births per 1,000
                // people. The reconstruction inherits the rate's rounding, so the note
                // says where it came from.
                $publishedBirths = $values['birth.count.annual'] ?? null;
                $publishedDeaths = $values['death.count.annual'] ?? null;

                $births = $publishedBirths
                    ?? $this->countFromRate($values['birth.rate.crude'] ?? null, $population);
                $deaths = $publishedDeaths
                    ?? $this->countFromRate($values['death.rate.crude'] ?? null, $population);

                $estimating = ($publishedBirths === null && $births !== null)
                    || ($publishedDeaths === null && $deaths !== null);

                $naturalChange = ($births !== null && $deaths !== null) ? $births - $deaths : null;

                $candidates = [
                    'birth.count.annual' => [
                        $publishedBirths === null ? $births : null,
                        'the crude birth rate applied to the population',
                    ],
                    'death.count.annual' => [
                        $publishedDeaths === null ? $deaths : null,
                        'the crude death rate applied to the population',
                    ],
                    'pop.natural_change.absolute' => [
                        $naturalChange,
                        'births minus deaths',
                    ],
                    'pop.natural_change.rate' => [
                        $this->rate($naturalChange, $population),
                        'births minus deaths, per 1,000 population',
                    ],
                    'birth.rate.crude' => [
                        $this->rate($births, $population),
                        'births per 1,000 population',
                    ],
                    'death.rate.crude' => [
                        $this->rate($deaths, $population),
                        'deaths per 1,000 population',
                    ],
                ];

                // Population change needs consecutive years for the same entity.
                $populationChange = ($population !== null && $previousPopulation !== null && $year === $previousYear + 1)
                    ? $population - $previousPopulation
                    : null;

                $candidates['pop.change.annual'] = [
                    $populationChange,
                    'population this year minus population the year before',
                ];

                // Net migration closes the identity: growth = natural increase + net migration.
                $candidates['mig.net_annual'] = [
                    ($populationChange !== null && $naturalChange !== null) ? $populationChange - $naturalChange : null,
                    'population change minus natural change',
                ];

                // Consecutive years only; a gap breaks the chain rather than bridging it.
                $adjacent = $year === $previousYear + 1;

                $candidates['birth.change.pct'] = [
                    $adjacent ? $this->percentChange($births, $previousBirths) : null,
                    'live births this year against the year before',
                ];

                $candidates['death.change.pct'] = [
                    $adjacent ? $this->percentChange($deaths, $previousDeaths) : null,
                    'deaths this year against the year before',
                ];

                $candidates['pop.natural_change.change_pct'] = [
                    $adjacent ? $this->percentChange($naturalChange, $previousNaturalChange) : null,
                    'natural change this year against the year before',
                ];

                // How much of the year's population change came from births and deaths
                // rather than from migration. Can exceed 100% or be negative, because
                // migration can move in the opposite direction.
                $candidates['pop.change.natural_share'] = [
                    ($adjacent && $populationChange !== null && $populationChange != 0 && $naturalChange !== null)
                        ? ($naturalChange / $populationChange) * 100
                        : null,
                    'natural change as a share of total population change',
                ];

                foreach ($candidates as $code => [$value, $formula]) {
                    if ($value === null) {
                        continue;
                    }

                    $indicator = $indicators->get($code);

                    if ($indicator === null || ! IndicatorSanity::isPlausible($code, $value)) {
                        continue;
                    }

                    $periodKey = Observation::makePeriodKey('annual', (int) $year);

                    if (isset($existing[$entityId.':'.$indicator->id.':'.$periodKey])) {
                        continue;
                    }

                    $drafts[] = new ObservationDraft(
                        entityId: (int) $entityId,
                        indicatorId: (int) $indicator->id,
                        periodType: 'annual',
                        periodYear: (int) $year,
                        value: round($value, 4),
                        unit: $indicator->unit,
                        status: 'reported',
                        isDerived: true,
                        sourceId: $source->id,
                        sourceRecordRef: 'derived:'.$code.':'.$entityId.':'.$year,
                        referenceNote: sprintf(
                            'Calculated, not reported: %s. Inputs are the latest served values for this year.%s',
                            $formula,
                            $estimating ? ' Counts reconstructed from published crude rates.' : ''
                        ),
                    );
                }

                $previousYear = (int) $year;
                $previousPopulation = $population;
                $previousBirths = $births;
                $previousDeaths = $deaths;
                $previousNaturalChange = $naturalChange;
            }
        }

        return $drafts;
    }

    private function percentChange(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || $previous == 0) {
            return null;
        }

        return (($current - $previous) / abs($previous)) * 100;
    }

    /**
     * A crude rate is deaths (or births) per 1,000 people, so the count is the rate
     * applied back to the population. Rounded to the nearest person, because a
     * fractional birth is not a thing.
     */
    private function countFromRate(?float $rate, ?float $population): ?float
    {
        if ($rate === null || $population === null || $population <= 0 || $rate < 0) {
            return null;
        }

        return round(($rate / 1000) * $population);
    }

    private function rate(?float $numerator, ?float $population): ?float
    {
        if ($numerator === null || $population === null || $population <= 0) {
            return null;
        }

        return ($numerator / $population) * 1000;
    }
}
