import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'

import { api } from '../lib/api'
import type { MatrixCell } from '../lib/types'
import { formatCompact, formatNumber, formatValue } from '../lib/format'
import { VITAL_CODES } from '../components/CurrentVitalStatistics'
import { Badge, Card, EmptyState, ErrorState, Spinner, Stat } from '../components/ui'

/**
 * The columns of the recent-change table, in the order they are shown: net change first,
 * then the year-on-year percentages that describe how it moved.
 */
const CHANGE_COLUMNS = [
  { code: VITAL_CODES.births, label: 'Live births' },
  { code: VITAL_CODES.deaths, label: 'Deaths' },
  { code: VITAL_CODES.natural, label: 'Net change' },
  { code: VITAL_CODES.naturalShare, label: '% of net change', percent: true },
  { code: VITAL_CODES.birthsChange, label: 'Birth change %', percent: true },
  { code: VITAL_CODES.deathsChange, label: 'Death change %', percent: true },
  { code: VITAL_CODES.naturalChange, label: 'Net change %', percent: true },
]

function number(cell: MatrixCell | undefined): string {
  if (cell === undefined || cell.value === null) return '—'
  return formatValue(cell.value, cell.unit, 0)
}

export function DashboardPage() {
  const health = useQuery({ queryKey: ['health'], queryFn: api.health })
  const sources = useQuery({ queryKey: ['sources'], queryFn: api.sources })
  const coverage = useQuery({ queryKey: ['coverage'], queryFn: () => api.coverage() })
  const conflicts = useQuery({
    queryKey: ['conflicts', 'summary'],
    queryFn: () => api.conflicts({ limit: 8 }),
  })

  const vitals = useQuery({
    queryKey: ['matrix', 'vital-change'],
    queryFn: () => api.matrix({ indicators: CHANGE_COLUMNS.map((column) => column.code).join(',') }),
  })

  const vitalRows = (vitals.data?.rows ?? [])
    .filter((row) => {
      const births = row.values[VITAL_CODES.births]
      const deaths = row.values[VITAL_CODES.deaths]
      return (births?.value ?? null) !== null || (deaths?.value ?? null) !== null
    })
    .slice()
    .sort((a, b) => a.entity.name.localeCompare(b.entity.name))

  const datasets = (sources.data?.data ?? []).flatMap((source) => source.datasets ?? [])

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-lg font-semibold text-ink-900">Overview</h1>
        <p className="mt-1 text-xs text-ink-600">
          Current coverage of the demographics module: what is held, how fresh it is, and where sources disagree.
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat
          label="Stored values"
          value={health.data !== undefined ? formatCompact(health.data.observations) : '—'}
          hint="append-only observations"
        />
        <Stat label="Indicators" value={coverage.data?.meta.indicator_count ?? '—'} hint="with data held" />
        <Stat
          label="Sources disagreeing"
          value={conflicts.data?.meta.count ?? '—'}
          hint="recorded, not overwritten"
        />
        <Stat
          label="Registered sources"
          value={sources.data?.meta.count ?? '—'}
          hint={`${datasets.length} datasets`}
        />
      </div>

      <Card
        title="Source freshness"
        subtitle="Each dataset is fetched on its own cadence and skipped when the payload is unchanged."
      >
        {sources.isLoading && <Spinner />}
        {sources.error !== null && <ErrorState error={sources.error} />}
        {sources.data !== undefined && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-ink-200 text-left text-ink-500">
                  <th className="py-2 pr-3 font-medium">Source</th>
                  <th className="py-2 pr-3 font-medium">Dataset</th>
                  <th className="py-2 pr-3 font-medium">Rank</th>
                  <th className="py-2 pr-3 font-medium">Cadence</th>
                  <th className="py-2 pr-3 font-medium">Last checked</th>
                  <th className="py-2 pr-3 font-medium">Next due</th>
                </tr>
              </thead>
              <tbody>
                {datasets.map((dataset) => (
                  <tr key={dataset.code} className="border-b border-ink-100 last:border-0">
                    <td className="py-2 pr-3 text-ink-800">{dataset.source?.publisher_short ?? dataset.source?.code}</td>
                    <td className="py-2 pr-3 text-ink-600">{dataset.name}</td>
                    <td className="py-2 pr-3">
                      <Badge tone={dataset.source?.credibility_rank === 1 ? 'info' : 'neutral'}>
                        {dataset.source?.credibility_rank ?? '—'}
                      </Badge>
                    </td>
                    <td className="py-2 pr-3 text-ink-600">{dataset.update_frequency}</td>
                    <td className="py-2 pr-3 text-ink-600">
                      {dataset.last_checked_at !== null ? new Date(dataset.last_checked_at).toLocaleString() : 'never'}
                    </td>
                    <td className="py-2 pr-3 text-ink-600">
                      {dataset.next_run_at !== null ? new Date(dataset.next_run_at).toLocaleDateString() : 'unscheduled'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="Coverage by indicator" subtitle="Span held, plus periods explicitly recorded as missing.">
          {coverage.isLoading && <Spinner />}
          {coverage.error !== null && <ErrorState error={coverage.error} />}
          {coverage.data !== undefined && (
            <div className="max-h-96 overflow-y-auto">
              <table className="w-full text-xs">
                <thead className="sticky top-0 bg-white">
                  <tr className="border-b border-ink-200 text-left text-ink-500">
                    <th className="py-2 pr-3 font-medium">Indicator</th>
                    <th className="py-2 pr-3 font-medium">Span</th>
                    <th className="py-2 pr-3 font-medium">Values</th>
                    <th className="py-2 pr-3 font-medium">Missing</th>
                    <th className="py-2 pr-3 font-medium">Sources</th>
                  </tr>
                </thead>
                <tbody>
                  {(coverage.data.data ?? []).map((row) => (
                    <tr key={row.indicator?.code} className="border-b border-ink-100 last:border-0">
                      <td className="py-1.5 pr-3">
                        {row.indicator !== null && (
                          <Link
                            to={`/indicators/${row.indicator.code}`}
                            className="text-ink-800 underline decoration-ink-300 hover:decoration-ink-600"
                          >
                            {row.indicator.name}
                          </Link>
                        )}
                      </td>
                      <td className="tnum py-1.5 pr-3 text-ink-600">
                        {row.span.from}–{row.span.to}
                      </td>
                      <td className="tnum py-1.5 pr-3 text-ink-600">{formatNumber(row.observations, 0)}</td>
                      <td className="tnum py-1.5 pr-3">
                        {row.missing_periods > 0 ? (
                          <span className="text-amber-700">{row.missing_periods}</span>
                        ) : (
                          <span className="text-ink-400">0</span>
                        )}
                      </td>
                      <td className="tnum py-1.5 pr-3 text-ink-600">{row.source_count}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card
          title="Largest disagreements between sources"
          subtitle="Both values are retained; the higher-ranked source is served."
          actions={
            <Link to="/quality" className="text-[11px] text-sky-700 underline">
              Review all
            </Link>
          }
        >
          {conflicts.isLoading && <Spinner />}
          {conflicts.error !== null && <ErrorState error={conflicts.error} />}
          {conflicts.data !== undefined && conflicts.data.data.length === 0 && (
            <EmptyState>No source disagreements recorded.</EmptyState>
          )}
          {conflicts.data !== undefined && conflicts.data.data.length > 0 && (
            <ul className="space-y-2">
              {conflicts.data.data.map((conflict) => (
                <li key={conflict.id} className="rounded border border-ink-200 px-3 py-2 text-xs">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium text-ink-800">
                      {conflict.entity?.name} · {conflict.indicator?.name} · {conflict.period}
                    </span>
                    <Badge tone={conflict.significance === 'major' ? 'danger' : conflict.significance === 'notable' ? 'warn' : 'neutral'}>
                      {conflict.significance}
                    </Badge>
                  </div>
                  <p className="tnum mt-1 text-ink-600">
                    {conflict.primary.source?.publisher_short}: {formatNumber(conflict.primary.value, 2)} vs{' '}
                    {conflict.conflicting.source?.publisher_short}: {formatNumber(conflict.conflicting.value, 2)}
                    {conflict.pct_delta !== null && ` · ${conflict.pct_delta.toFixed(1)}% apart`}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card
        title="Recent change in vital statistics"
        subtitle="Each country's latest year against the year before. The percentage columns are calculated from the counts, not published by a source."
      >
        {vitals.isLoading && <Spinner />}
        {vitals.error !== null && <ErrorState error={vitals.error} />}
        {vitals.data !== undefined && vitalRows.length === 0 && (
          <EmptyState>No annual birth or death counts are held yet.</EmptyState>
        )}
        {vitalRows.length > 0 && (
          <div className="max-h-96 overflow-auto">
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-white">
                <tr className="border-b border-ink-200 text-right text-ink-500">
                  <th className="py-2 pr-3 text-left font-medium">Country</th>
                  <th className="py-2 pr-3 font-medium">Year</th>
                  {CHANGE_COLUMNS.map((column) => (
                    <th key={column.code} className="whitespace-normal py-2 pr-3 font-medium">
                      {column.label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {vitalRows.map((row) => (
                  <tr key={row.entity.uuid} className="border-b border-ink-100 last:border-0">
                    <td className="py-1.5 pr-3">
                      <Link
                        to={`/countries/${row.entity.iso_alpha3 ?? row.entity.slug}`}
                        className="text-ink-800 underline decoration-ink-300 hover:decoration-ink-600"
                      >
                        {row.entity.name}
                      </Link>
                    </td>
                    <td className="tnum py-1.5 pr-3 text-right text-ink-600">
                      {row.values[VITAL_CODES.births]?.period ?? row.values[VITAL_CODES.deaths]?.period ?? '—'}
                    </td>
                    {CHANGE_COLUMNS.map((column) => {
                      const cell = row.values[column.code]

                      return (
                        <td key={column.code} className="tnum py-1.5 pr-3 text-right text-ink-700">
                          {column.percent === true
                            ? cell === undefined || cell.value === null
                              ? '—'
                              : `${cell.value > 0 && column.code !== VITAL_CODES.naturalShare ? '+' : ''}${formatNumber(cell.value, 1)}%`
                            : number(cell)}
                          {cell?.is_derived === true && (
                            <span className="ml-1 text-[9px] uppercase text-ink-400" title="Calculated from other observations">
                              calc
                            </span>
                          )}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card title="Note on missing data">
        <p className="text-xs text-ink-600">
          Blank cells in the source spreadsheet are stored as explicit <span className="font-medium">missing</span> records
          with a reason, never as zero and never interpolated. Values the World Bank simply omits for a year are reported as
          coverage gaps rather than written as placeholder rows. Values whose reference period the source does not state are
          quarantined for review instead of being dated by assumption — see{' '}
          <Link to="/quality" className="text-sky-700 underline">
            Data quality
          </Link>
          .
        </p>
      </Card>
    </div>
  )
}
