import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, Search, X } from 'lucide-react'

import { api } from '../lib/api'
import type { IndicatorRef, MatrixCell } from '../lib/types'
import { formatValue, missingLabel, unitLabel } from '../lib/format'
import { compareNullable, compareText, nextSort, type SortState } from '../lib/sort'
import { MODULES, type ModuleCode } from '../lib/modules'
import { ColumnPicker, useSavedHidden } from '../components/ColumnPicker'
import { Button, Card, EmptyState, ErrorState, SortGlyph, Spinner } from '../components/ui'

/** Full numbers with thousands separators; years print plainly. */
function formatCell(cell: MatrixCell | undefined, indicator: IndicatorRef): string {
  if (cell === undefined || cell.value === null) return '—'

  return formatValue(cell.value, indicator.unit, indicator.decimals)
}

function cellTitle(cell: MatrixCell, indicator: IndicatorRef): string {
  const parts = [
    `${indicator.name}: ${formatValue(cell.value, indicator.unit, indicator.decimals)} ${unitLabel(cell.unit)}`.trim(),
    `Period: ${cell.period}${cell.period_is_assumed ? ' (assumed — the source states no year)' : ''}`,
    `Status: ${cell.status}`,
    `Source: ${cell.source.publisher_short ?? cell.source.code ?? 'unknown'} (rank ${cell.source.credibility_rank ?? '—'})`,
  ]

  if (cell.is_curated) parts.push('Curated by a project editor.')
  if (cell.is_derived) parts.push('Calculated from other observations, not reported by a source.')
  if (cell.is_projection) parts.push('A projection, not an observed figure.')

  return parts.join('\n')
}

/**
 * The country × indicator table: one row per country, one column per indicator, filled
 * with each country's latest known value — the shape of the original spreadsheet, but
 * every cell carrying the period and source it came from.
 */
export function MatrixPage() {
  const [search, setSearch] = useState('')
  const [module, setModule] = useState<ModuleCode>('demographics')
  const [category, setCategory] = useState('')
  const [includeProjections, setIncludeProjections] = useState(false)
  const [showPeriods, setShowPeriods] = useState(false)
  const [provenanceUuid, setProvenanceUuid] = useState<string | null>(null)
  const [hidden, setHidden] = useSavedHidden(`demographics.table.columns.${module}`)
  const [sort, setSort] = useState<SortState>({ key: 'country', dir: 'asc' })

  const matrix = useQuery({
    queryKey: ['matrix', module, includeProjections],
    queryFn: () => api.matrix({ module, include_projections: includeProjections }),
  })

  const categories = useMemo(() => {
    const seen = new Map<string, string>()
    ;(matrix.data?.indicators ?? []).forEach((indicator) => {
      const code = indicator.category?.code ?? 'other'
      if (!seen.has(code)) seen.set(code, indicator.category?.name ?? 'Other')
    })
    return Array.from(seen.entries())
  }, [matrix.data])

  const indicators = useMemo(
    () => (matrix.data?.indicators ?? []).filter((indicator) => category === '' || indicator.category?.code === category),
    [matrix.data, category],
  )

  /** The columns actually drawn: every indicator in the category, less the hidden ones. */
  const columns = useMemo(
    () => indicators.filter((indicator) => !hidden.includes(indicator.code)),
    [indicators, hidden],
  )

  // Sorting a column that is no longer on screen would silently reorder the rows.
  useEffect(() => {
    if (sort.key === 'country' || sort.key === 'continent') return
    if (!columns.some((column) => column.code === sort.key)) setSort({ key: 'country', dir: 'asc' })
  }, [columns, sort.key])

  /**
   * Columns follow the source spreadsheet's own sequence (peak population, present
   * population, decline, migration, fertility, births, deaths, age, life expectancy,
   * per capita), which the API returns via each indicator's display order.
   */
  const rows = useMemo(() => {
    const term = search.trim().toLowerCase()

    return (matrix.data?.rows ?? []).filter((row) => {
      if (term === '') return true
      return (
        row.entity.name.toLowerCase().includes(term) ||
        (row.entity.iso_alpha3 ?? '').toLowerCase().includes(term)
      )
    })
  }, [matrix.data, search])

  const sortedRows = useMemo(() => {
    const copy = [...rows]

    copy.sort((a, b) => {
      if (sort.key === 'country') return compareText(a.entity.name, b.entity.name, sort.dir)
      if (sort.key === 'continent') return compareText(a.entity.continent ?? '', b.entity.continent ?? '', sort.dir)

      return compareNullable(a.values[sort.key]?.value, b.values[sort.key]?.value, sort.dir)
    })

    return copy
  }, [rows, sort])

  const exportCsv = useCallback(() => {
    const header = ['country', 'continent', 'iso3', ...columns.map((i) => i.code)]
    const lines = sortedRows.map((row) => [
      row.entity.name,
      row.entity.continent ?? '',
      row.entity.iso_alpha3 ?? '',
      ...columns.map((indicator) => {
        const cell = row.values[indicator.code]
        return cell === undefined || cell.value === null ? '' : `${cell.value} (${cell.period})`
      }),
    ])

    const csv = [header, ...lines]
      .map((line) => line.map((value) => (/[",\n]/.test(String(value)) ? `"${String(value).replace(/"/g, '""')}"` : value)).join(','))
      .join('\r\n')

    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `${module}-matrix.csv`
    link.click()
    URL.revokeObjectURL(url)
  }, [columns, sortedRows, module])

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-ink-900">Table</h1>
          <p className="mt-1 text-xs text-ink-600">
            One row per country, one column per indicator, each cell that country's latest known value. Click a column
            heading to sort; hover a cell for its reference period, status and source.
          </p>
          {matrix.data !== undefined && (
            <p className="mt-1 text-[11px] text-ink-500">
              {rows.length} countries · {columns.length} of {indicators.length} indicators · {matrix.data.meta.cells} cells filled
              {includeProjections ? ' · projections included' : ''}
            </p>
          )}
        </div>
        <Button onClick={exportCsv} disabled={sortedRows.length === 0}>
          <Download size={12} /> Export CSV
        </Button>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1">
          <span className="text-[11px] font-medium text-ink-500">Country</span>
          <span className="flex items-center gap-1 rounded-md border border-ink-300 bg-white px-2 py-1.5">
            <Search size={13} className="text-ink-400" />
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Name or ISO code"
              className="w-40 border-0 p-0 text-xs outline-none"
            />
          </span>
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-[11px] font-medium text-ink-500">Module</span>
          <select
            value={module}
            onChange={(event) => {
              setModule(event.target.value as ModuleCode)
              setCategory('')
              setSort({ key: 'country', dir: 'asc' })
            }}
            className="rounded-md border border-ink-300 bg-white px-2 py-1.5 text-xs"
          >
            {MODULES.map((entry) => (
              <option key={entry.code} value={entry.code}>
                {entry.name}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-[11px] font-medium text-ink-500">Category</span>
          <select
            value={category}
            onChange={(event) => setCategory(event.target.value)}
            className="rounded-md border border-ink-300 bg-white px-2 py-1.5 text-xs"
          >
            <option value="">All categories</option>
            {categories.map(([code, name]) => (
              <option key={code} value={code}>
                {name}
              </option>
            ))}
          </select>
        </label>
        <label className="flex items-center gap-2 pb-1 text-xs text-ink-600">
          <input
            type="checkbox"
            checked={includeProjections}
            onChange={(event) => setIncludeProjections(event.target.checked)}
          />
          Include projections
        </label>
        <label className="flex items-center gap-2 pb-1 text-xs text-ink-600">
          <input type="checkbox" checked={showPeriods} onChange={(event) => setShowPeriods(event.target.checked)} />
          Show reference years
        </label>
        {sort.key !== 'country' && (
          <Button variant="ghost" onClick={() => setSort({ key: 'country', dir: 'asc' })}>
            Reset sort
          </Button>
        )}
      </div>

      <ColumnPicker
        indicators={indicators}
        hidden={hidden}
        onHiddenChange={setHidden}
        storageKey={`demographics.table.columns.${module}`}
      />

      {matrix.isLoading && <Spinner />}
      {matrix.error !== null && <ErrorState error={matrix.error} />}

      {matrix.data !== undefined && rows.length === 0 && <EmptyState>No countries match that filter.</EmptyState>}

      {sortedRows.length > 0 && (
        <Card>
          <div className="max-h-[70vh] overflow-auto">
            <table className="border-separate border-spacing-0 text-xs">
              <thead>
                <tr>
                  <th className="sticky left-0 top-0 z-40 min-w-[11rem] border-b border-r border-ink-200 bg-white p-0 text-left font-medium text-ink-500">
                    <button
                      type="button"
                      onClick={() => setSort((current) => nextSort(current, 'country', false))}
                      className="flex w-full items-center gap-1 px-2 py-1.5 hover:bg-ink-50"
                    >
                      Country <SortGlyph active={sort.key === 'country'} dir={sort.dir} />
                    </button>
                  </th>
                  <th className="sticky top-0 z-20 min-w-[7rem] border-b border-r border-ink-100 bg-white p-0 text-left font-medium text-ink-500">
                    <button
                      type="button"
                      onClick={() => setSort((current) => nextSort(current, 'continent', false))}
                      className="flex w-full items-center gap-1 px-2 py-1.5 hover:bg-ink-50"
                    >
                      Continent <SortGlyph active={sort.key === 'continent'} dir={sort.dir} />
                    </button>
                  </th>
                  {columns.map((indicator) => (
                    <th
                      key={indicator.code}
                      className="sticky top-0 z-20 min-w-[8rem] border-b border-r border-ink-100 bg-white p-0 align-bottom"
                    >
                      <button
                        type="button"
                        title={`${indicator.name} (${unitLabel(indicator.unit) || indicator.unit})`}
                        onClick={() => setSort((current) => nextSort(current, indicator.code, true))}
                        className="flex w-full flex-col items-end gap-0.5 px-1.5 py-1.5 pr-5 text-right hover:bg-ink-50"
                      >
                        <span className="flex items-start justify-end gap-1 font-medium text-ink-600">
                          <span className="whitespace-normal leading-tight">{indicator.name}</span>
                          <SortGlyph active={sort.key === indicator.code} dir={sort.dir} />
                        </span>
                        <span className="whitespace-normal text-[10px] font-normal text-ink-400">
                          {unitLabel(indicator.unit) || indicator.unit}
                        </span>
                      </button>
                      <button
                        type="button"
                        aria-label={`Hide ${indicator.name}`}
                        title={`Hide ${indicator.name}`}
                        onClick={() => setHidden([...hidden, indicator.code])}
                        className="absolute right-0.5 top-0.5 rounded p-0.5 text-ink-300 hover:bg-ink-100 hover:text-ink-800"
                      >
                        <X size={10} />
                      </button>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {sortedRows.map((row) => (
                  <tr key={row.entity.uuid} className="hover:bg-ink-50">
                    <th className="sticky left-0 z-30 border-b border-r border-ink-100 bg-white px-2 py-1 text-left font-normal">
                      <Link
                        to={`/countries/${row.entity.iso_alpha3 ?? row.entity.slug}`}
                        className="font-medium text-ink-900 underline decoration-ink-200 hover:decoration-ink-600"
                      >
                        {row.entity.name}
                      </Link>
                      {row.entity.is_aggregate && (
                        <span className="ml-1 text-[10px] text-ink-400">aggregate</span>
                      )}
                    </th>
                    <td className="border-b border-r border-ink-100 px-2 py-1 text-ink-600">
                      {row.entity.continent ?? '—'}
                    </td>
                    {columns.map((indicator) => {
                      const cell = row.values[indicator.code]

                      if (cell === undefined) {
                        return (
                          <td key={indicator.code} className="border-b border-r border-ink-100 px-1.5 py-1 text-right text-ink-300">
                            —
                          </td>
                        )
                      }

                      // Two things read as bad news and are coloured that way: a negative
                      // value, and a population that has fallen below its own peak.
                      const peak = row.values['pop.peak']?.value ?? null
                      const belowPeakPopulation =
                        indicator.code === 'pop.total' &&
                        peak !== null &&
                        cell.value !== null &&
                        cell.value < peak

                      const isNegative = cell.value !== null && cell.value < 0

                      const tone = isNegative || belowPeakPopulation
                        ? 'text-rose-700 font-medium'
                        : cell.is_curated
                          ? 'text-sky-800 font-semibold'
                          : cell.is_projection
                            ? 'text-amber-700'
                            : cell.is_estimate
                              ? 'text-sky-700'
                              : 'text-ink-800'

                      return (
                        <td
                          key={indicator.code}
                          title={cellTitle(cell, indicator)}
                          onClick={() => setProvenanceUuid(cell.observation_uuid)}
                          className="tnum cursor-pointer border-b border-r border-ink-100 px-1.5 py-1 text-right"
                        >
                          <span
                            className={`${tone} ${cell.period_is_assumed ? 'underline decoration-amber-400 decoration-dotted underline-offset-2' : ''}`}
                          >
                            {formatCell(cell, indicator)}
                          </span>
                          {showPeriods && <span className="ml-1 text-[10px] text-ink-400">{cell.period}</span>}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-3 flex flex-wrap gap-3 text-[11px] text-ink-500">
            <span>
              <span className="font-medium text-ink-700">—</span> no value held
            </span>
            <span>
              <span className="text-sky-700">blue</span> estimate or curated
            </span>
            <span>
              <span className="text-amber-700">amber</span> projection
            </span>
            <span>
              <span className="underline decoration-amber-400 decoration-dotted underline-offset-2">dotted</span> period assumed
            </span>
            <span>Missing values sort last whichever direction is active.</span>
          </div>
        </Card>
      )}

      {provenanceUuid !== null && <MatrixProvenance uuid={provenanceUuid} onClose={() => setProvenanceUuid(null)} />}
    </div>
  )
}

/**
 * The table is dense, so provenance opens here rather than navigating away.
 */
function MatrixProvenance({ uuid, onClose }: { uuid: string; onClose: () => void }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['provenance', uuid],
    queryFn: () => api.provenance(uuid),
  })

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-ink-900/30" onClick={onClose}>
      <aside className="h-full w-full max-w-md overflow-y-auto bg-white p-4 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between">
          <h2 className="text-sm font-semibold text-ink-900">Provenance</h2>
          <Button variant="ghost" onClick={onClose}>
            close
          </Button>
        </div>

        {isLoading && <Spinner />}
        {error !== null && <ErrorState error={error} />}

        {data !== undefined && (
          <div className="mt-3 space-y-3 text-xs">
            <p className="tnum text-xl font-semibold text-ink-900">
              {formatValue(data.data.value, data.data.unit, 2)}
            </p>
            <p className="text-ink-600">
              {data.data.indicator?.name} · {data.data.entity?.name} · {data.data.period}
              {data.data.period_is_assumed && ' (assumed period)'}
            </p>
            <dl className="grid grid-cols-[7rem_1fr] gap-x-2 gap-y-1">
              <dt className="text-ink-500">Source</dt>
              <dd className="text-ink-800">{data.data.source?.publisher ?? data.data.source?.publisher_short}</dd>
              <dt className="text-ink-500">Rank</dt>
              <dd className="text-ink-800">{data.data.source?.credibility_rank}</dd>
              <dt className="text-ink-500">Status</dt>
              <dd className="text-ink-800">{data.data.status}</dd>
              <dt className="text-ink-500">Raw value</dt>
              <dd className="text-ink-800">{data.data.raw_value ?? '—'}</dd>
              <dt className="text-ink-500">Retrieved</dt>
              <dd className="text-ink-800">{data.data.retrieved_at ?? '—'}</dd>
              {data.data.is_missing && (
                <>
                  <dt className="text-ink-500">Missing</dt>
                  <dd className="text-ink-800">{missingLabel(data.data.missing_reason)}</dd>
                </>
              )}
            </dl>
            {data.data.reference_note !== null && (
              <p className="rounded bg-ink-50 px-2 py-1 text-[11px] text-ink-600">{data.data.reference_note}</p>
            )}
          </div>
        )}
      </aside>
    </div>
  )
}
