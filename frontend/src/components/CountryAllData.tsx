import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Pencil, X } from 'lucide-react'

import { api } from '../lib/api'
import type { EntityObservationRow } from '../lib/types'
import { formatDateTime, formatValue, missingLabel, unitLabel } from '../lib/format'
import { ColumnPicker, useSavedHidden } from './ColumnPicker'
import { compareNullable, nextSort, type SortState } from '../lib/sort'
import { Badge, Button, Card, EmptyState, ErrorState, Select, SortGlyph, Spinner, StatusChip } from './ui'

function csvCell(value: unknown): string {
  const text = value === null || value === undefined ? '' : String(value)

  return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

function downloadCsv(rows: EntityObservationRow[], entityLabel: string): void {
  const header = [
    'indicator_code', 'indicator', 'period', 'value', 'unit', 'status',
    'source', 'source_rank', 'assumed_period', 'curated', 'missing_reason', 'reference_note',
  ]

  const lines = rows.map((row) => [
    row.indicator?.code ?? '',
    row.indicator?.name ?? '',
    row.period,
    row.value,
    row.indicator?.unit ?? '',
    row.status,
    row.source.publisher_short ?? row.source.code ?? '',
    row.source.credibility_rank,
    row.period_is_assumed ? 'yes' : 'no',
    row.is_curated ? 'yes' : 'no',
    row.missing_reason ?? '',
    row.reference_note ?? '',
  ])

  const csv = [header, ...lines].map((line) => line.map(csvCell).join(',')).join('\r\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = `${entityLabel.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-demographics.csv`
  link.click()
  URL.revokeObjectURL(url)
}

export function CountryAllData({
  entityKey,
  authenticated,
  onOpenProvenance,
}: {
  entityKey: string
  authenticated: boolean
  onOpenProvenance: (uuid: string) => void
}) {
  const [indicator, setIndicator] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [includeProjections, setIncludeProjections] = useState(true)
  const [view, setView] = useState<'year' | 'indicator'>('year')
  const [yearSort, setYearSort] = useState<SortState>({ key: 'period', dir: 'desc' })
  const [hiddenColumns, setHiddenColumns] = useSavedHidden(`demographics.country.columns.${entityKey}`)
  const [editing, setEditing] = useState<EntityObservationRow | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const queryClient = useQueryClient()

  const indicators = useQuery({ queryKey: ['indicators'], queryFn: () => api.indicators() })

  const observations = useQuery({
    queryKey: ['entity-observations', entityKey, indicator, from, to, includeProjections],
    queryFn: () =>
      api.entityObservations(entityKey, {
        indicator: indicator === '' ? undefined : indicator,
        from: from === '' ? undefined : Number(from),
        to: to === '' ? undefined : Number(to),
        include_projections: includeProjections,
      }),
    enabled: view === 'indicator',
  })

  /*
   * The year view is the same data transposed: one row per year with every indicator
   * across it, which is how a statistical yearbook is laid out.
   */
  const yearMatrix = useQuery({
    queryKey: ['entity-matrix', entityKey, indicator, from, to, includeProjections],
    queryFn: () =>
      api.entityMatrix(entityKey, {
        indicator: indicator === '' ? undefined : indicator,
        from: from === '' ? undefined : Number(from),
        to: to === '' ? undefined : Number(to),
        include_projections: includeProjections,
      }),
    enabled: view === 'year',
  })

  const curate = useMutation({
    mutationFn: (payload: {
      uuid: string
      value: number | null
      period_year: number
      status: string
      note: string
    }) =>
      api.admin.curateObservation(payload.uuid, {
        value: payload.value,
        period_year: payload.period_year,
        status: payload.status,
        note: payload.note,
      }),
    onSuccess: () => {
      setEditing(null)
      setMessage('Correction stored as a new curated revision. The previous value is still on record.')
      queryClient.invalidateQueries({ queryKey: ['entity-observations'] })
      queryClient.invalidateQueries({ queryKey: ['latest'] })
      queryClient.invalidateQueries({ queryKey: ['series'] })
    },
    onError: (error: Error) => setMessage(error.message),
  })

  const indicatorOptions = useMemo(
    () => [
      { value: '', label: 'All indicators' },
      ...(indicators.data?.data ?? []).map((entry) => ({ value: entry.code, label: entry.name })),
    ],
    [indicators.data],
  )

  const rows = observations.data?.data ?? []
  const yearRows = useMemo(() => yearMatrix.data?.rows ?? [], [yearMatrix.data])
  const yearIndicators = useMemo(() => yearMatrix.data?.indicators ?? [], [yearMatrix.data])

  /** The columns actually drawn: the indicators held for the selected years, less the hidden ones. */
  const yearColumns = useMemo(
    () => yearIndicators.filter((entry) => !hiddenColumns.includes(entry.code)),
    [yearIndicators, hiddenColumns],
  )
  const entityLabel = yearMatrix.data?.entity.name ?? observations.data?.entity.name ?? entityKey

  const sortedYearRows = useMemo(() => {
    const copy = [...yearRows]

    copy.sort((a, b) => {
      if (yearSort.key === 'period') {
        const comparison = a.year === b.year ? a.period.localeCompare(b.period) : a.year - b.year

        return yearSort.dir === 'asc' ? comparison : -comparison
      }

      return compareNullable(a.values[yearSort.key]?.value, b.values[yearSort.key]?.value, yearSort.dir)
    })

    return copy
  }, [yearRows, yearSort])

  const exportYearMatrix = () => {
    const header = ['year', ...yearColumns.map((entry) => entry.code)]
    const lines = sortedYearRows.map((row) => [
      row.period,
      ...yearColumns.map((entry) => {
        const cell = row.values[entry.code]
        return cell === undefined || cell.value === null ? '' : String(cell.value)
      }),
    ])

    const csv = [header, ...lines]
      .map((line) => line.map((value) => (/[",\n]/.test(String(value)) ? `"${String(value).replace(/"/g, '""')}"` : value)).join(','))
      .join('\r\n')

    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `${entityLabel.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-by-year.csv`
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <Card
      title="All data"
      subtitle={
        view === 'year'
          ? 'One row per year, every indicator across — the shape of a statistical yearbook.'
          : 'One row per indicator and period, with the served value and its source.'
      }
      actions={
        <div className="flex flex-wrap items-center gap-1.5">
          <Button variant={view === 'year' ? 'primary' : 'default'} onClick={() => setView('year')}>
            By year
          </Button>
          <Button variant={view === 'indicator' ? 'primary' : 'default'} onClick={() => setView('indicator')}>
            By indicator
          </Button>
          <Button
            onClick={() => (view === 'year' ? exportYearMatrix() : downloadCsv(rows, entityLabel))}
            disabled={view === 'year' ? yearRows.length === 0 : rows.length === 0}
          >
            <Download size={12} /> Export CSV
          </Button>
        </div>
      }
    >
      <div className="flex flex-wrap items-end gap-3">
        <Select label="Indicator" value={indicator} onChange={setIndicator} options={indicatorOptions} />
        <label className="flex flex-col gap-1">
          <span className="text-[11px] font-medium text-ink-500">From year</span>
          <input
            value={from}
            onChange={(event) => setFrom(event.target.value.replace(/[^0-9]/g, ''))}
            placeholder="any"
            className="w-20 rounded-md border border-ink-300 px-2 py-1.5 text-xs"
          />
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-[11px] font-medium text-ink-500">To year</span>
          <input
            value={to}
            onChange={(event) => setTo(event.target.value.replace(/[^0-9]/g, ''))}
            placeholder="any"
            className="w-20 rounded-md border border-ink-300 px-2 py-1.5 text-xs"
          />
        </label>
        <label className="flex items-center gap-2 pb-1 text-xs text-ink-600">
          <input
            type="checkbox"
            checked={includeProjections}
            onChange={(event) => setIncludeProjections(event.target.checked)}
          />
          Include projections
        </label>
      </div>

      {view === 'year' && (
        <ColumnPicker
          indicators={yearIndicators}
          hidden={hiddenColumns}
          onHiddenChange={setHiddenColumns}
          storageKey={`demographics.country.columns.${entityKey}`}
        />
      )}

      {message !== null && (
        <p className="mt-3 rounded border border-ink-200 bg-ink-50 px-3 py-2 text-[11px] text-ink-700">{message}</p>
      )}

      {view === 'indicator' && (
        <>
      {observations.isLoading && <Spinner />}
      {observations.error !== null && <ErrorState error={observations.error} />}
      {observations.data !== undefined && (
        <p className="mt-3 text-[11px] text-ink-500">
          {observations.data.meta.count} rows · {observations.data.meta.assumed_periods} with an assumed period ·{' '}
          {observations.data.meta.curated} curated
        </p>
      )}
      {observations.data !== undefined && rows.length === 0 && <EmptyState>No values match these filters.</EmptyState>}

      {rows.length > 0 && (
        <div className="mt-2 max-h-[36rem] overflow-auto">
          <table className="w-full text-xs">
            <thead className="sticky top-0 bg-white">
              <tr className="border-b border-ink-200 text-left text-ink-500">
                <th className="py-2 pr-3 font-medium">Indicator</th>
                <th className="py-2 pr-3 font-medium">Period</th>
                <th className="py-2 pr-3 font-medium">Value</th>
                <th className="py-2 pr-3 font-medium">Status</th>
                <th className="py-2 pr-3 font-medium">Source</th>
                <th className="py-2 pr-3 font-medium">Retrieved</th>
                <th className="py-2 pr-3 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.observation_uuid} className="border-b border-ink-100 last:border-0 hover:bg-ink-50">
                  <td className="py-1.5 pr-3">
                    <span className="text-ink-800">{row.indicator?.name ?? '—'}</span>
                    <span className="ml-1 text-[10px] text-ink-400">{row.indicator?.category}</span>
                  </td>
                  <td className="tnum py-1.5 pr-3 text-ink-700">
                    {row.period}
                    {row.period_is_assumed && (
                      <span className="ml-1" title="The source states no reference year for this figure; dated to the compilation year.">
                        <Badge tone="warn">assumed</Badge>
                      </span>
                    )}
                  </td>
                  <td className="py-1.5 pr-3">
                    {row.is_missing ? (
                      <span className="text-ink-500" title={missingLabel(row.missing_reason)}>—</span>
                    ) : (
                      <span className="tnum text-ink-900">
                        {formatValue(row.value, row.indicator?.unit ?? '', row.indicator?.decimals ?? 2)}
                      </span>
                    )}
                    <span className="ml-1 text-[10px] text-ink-400">{unitLabel(row.indicator?.unit ?? '')}</span>
                  </td>
                  <td className="py-1.5 pr-3">
                    <span className="inline-flex flex-wrap items-center gap-1">
                      <StatusChip point={row} compact />
                      {row.is_curated && <Badge tone="info">curated</Badge>}
                    </span>
                  </td>
                  <td className="py-1.5 pr-3 text-ink-600">
                    {row.source.publisher_short ?? row.source.code ?? '—'}
                    {row.source.credibility_rank !== null && (
                      <span className="ml-1 text-[10px] text-ink-400">rank {row.source.credibility_rank}</span>
                    )}
                    {row.alternatives > 0 && (
                      <span
                        className="ml-1 text-[10px] text-ink-400"
                        title={`${row.alternatives} other source(s) also hold this period; see provenance.`}
                      >
                        +{row.alternatives}
                      </span>
                    )}
                  </td>
                  <td className="py-1.5 pr-3 text-ink-500">{formatDateTime(row.retrieved_at)}</td>
                  <td className="py-1.5 pr-3">
                    <span className="inline-flex gap-1">
                      <Button variant="ghost" onClick={() => onOpenProvenance(row.observation_uuid)}>
                        trace
                      </Button>
                      {authenticated && (
                        <Button variant="ghost" onClick={() => setEditing(row)}>
                          <Pencil size={11} /> edit
                        </Button>
                      )}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
        </>
      )}

      {view === 'year' && (
        <>
          {yearMatrix.isLoading && <Spinner />}
          {yearMatrix.error !== null && <ErrorState error={yearMatrix.error} />}

          {yearMatrix.data !== undefined && (
            <p className="mt-3 text-[11px] text-ink-500">
              {yearMatrix.data.meta.year_count} years · {yearMatrix.data.meta.indicator_count} indicators ·{' '}
              {yearMatrix.data.meta.cells} cells · {yearMatrix.data.meta.assumed_cells} with an assumed period
            </p>
          )}

          {yearMatrix.data !== undefined && yearRows.length === 0 && (
            <EmptyState>No values match these filters.</EmptyState>
          )}

          {yearRows.length > 0 && (
            <div className="mt-2 max-h-[36rem] overflow-auto">
              <table className="border-separate border-spacing-0 text-xs">
                <thead>
                  <tr>
                    <th className="sticky left-0 top-0 z-40 min-w-[5rem] border-b border-r border-ink-200 bg-white p-0 text-left font-medium text-ink-500">
                      <button
                        type="button"
                        onClick={() => setYearSort((current) => nextSort(current, 'period', true))}
                        className="flex w-full items-center gap-1 px-2 py-1.5 hover:bg-ink-50"
                      >
                        Year <SortGlyph active={yearSort.key === 'period'} dir={yearSort.dir} />
                      </button>
                    </th>
                    {yearColumns.map((entry) => (
                      <th
                        key={entry.code}
                        className="sticky top-0 z-20 min-w-[8rem] border-b border-r border-ink-100 bg-white p-0 text-right font-medium text-ink-600"
                      >
                        <button
                          type="button"
                          title={`${entry.name} (${unitLabel(entry.unit) || entry.unit})`}
                          onClick={() => setYearSort((current) => nextSort(current, entry.code, true))}
                          className="flex w-full items-start justify-end gap-1 px-1.5 py-1.5 pr-5 text-right hover:bg-ink-50"
                        >
                          <span className="whitespace-normal leading-tight">{entry.name}</span>
                          <SortGlyph active={yearSort.key === entry.code} dir={yearSort.dir} />
                        </button>
                        <button
                          type="button"
                          aria-label={`Hide ${entry.name}`}
                          title={`Hide ${entry.name}`}
                          onClick={() => setHiddenColumns([...hiddenColumns, entry.code])}
                          className="absolute right-0.5 top-0.5 rounded p-0.5 text-ink-300 hover:bg-ink-100 hover:text-ink-800"
                        >
                          <X size={10} />
                        </button>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {sortedYearRows.map((row) => (
                    <tr key={row.period} className="hover:bg-ink-50">
                      <th className="sticky left-0 z-30 border-b border-r border-ink-100 bg-white px-2 py-1 text-left font-medium text-ink-700">
                        {row.period}
                      </th>
                      {yearColumns.map((entry) => {
                        const cell = row.values[entry.code]

                        if (cell === undefined) {
                          return (
                            <td
                              key={entry.code}
                              className="border-b border-r border-ink-100 px-1.5 py-1 text-right text-ink-300"
                            >
                              —
                            </td>
                          )
                        }

                        const tone = cell.is_curated
                          ? 'text-sky-800 font-semibold'
                          : cell.is_projection
                            ? 'text-amber-700'
                            : cell.is_estimate
                              ? 'text-sky-700'
                              : 'text-ink-800'

                        const title = [
                          `${entry.name}: ${formatValue(cell.value, cell.indicator?.unit ?? entry.unit, cell.indicator?.decimals ?? entry.decimals)}`,
                          `Status: ${cell.status}${cell.period_is_assumed ? ' (period assumed)' : ''}`,
                          `Source: ${cell.source.publisher_short ?? cell.source.code ?? 'unknown'}`,
                        ].join('\n')

                        return (
                          <td
                            key={entry.code}
                            title={title}
                            onClick={() => onOpenProvenance(cell.observation_uuid)}
                            className="tnum cursor-pointer border-b border-r border-ink-100 px-1.5 py-1 text-right"
                          >
                            <span
                              className={`${tone} ${cell.period_is_assumed ? 'underline decoration-amber-400 decoration-dotted underline-offset-2' : ''}`}
                            >
                              {formatValue(
                                cell.value,
                                cell.indicator?.unit ?? entry.unit,
                                cell.indicator?.decimals ?? entry.decimals,
                              )}
                            </span>
                            {cell.is_curated && <span className="ml-1 text-[10px] text-sky-700">c</span>}
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}

      {editing !== null && (
        <EditObservationDialog
          row={editing}
          pending={curate.isPending}
          onCancel={() => setEditing(null)}
          onSave={(changes) =>
            curate.mutate({
              uuid: editing.observation_uuid,
              value: changes.value,
              period_year: changes.periodYear,
              status: changes.status,
              note: changes.note,
            })
          }
        />
      )}
    </Card>
  )
}

function EditObservationDialog({
  row,
  pending,
  onCancel,
  onSave,
}: {
  row: EntityObservationRow
  pending: boolean
  onCancel: () => void
  onSave: (changes: { value: number | null; periodYear: number; status: string; note: string }) => void
}) {
  const [value, setValue] = useState(row.value === null ? '' : String(row.value))
  const [periodYear, setPeriodYear] = useState(String(row.period_year))
  const [status, setStatus] = useState(row.status)
  const [note, setNote] = useState('')

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/30 p-4">
      <div className="w-full max-w-md rounded-lg bg-white p-4 shadow-xl">
        <div className="flex items-start justify-between">
          <div>
            <h3 className="text-sm font-semibold text-ink-900">Correct value</h3>
            <p className="mt-0.5 text-[11px] text-ink-500">
              {row.indicator?.name} · {row.period}
            </p>
          </div>
          <button type="button" onClick={onCancel} className="rounded p-1 text-ink-500 hover:bg-ink-100" aria-label="Close">
            <X size={15} />
          </button>
        </div>

        <div className="mt-3 grid grid-cols-2 gap-3">
          <label className="flex flex-col gap-1">
            <span className="text-[11px] font-medium text-ink-500">Value</span>
            <input
              value={value}
              onChange={(event) => setValue(event.target.value)}
              className="rounded-md border border-ink-300 px-2 py-1.5 text-xs"
            />
          </label>
          <label className="flex flex-col gap-1">
            <span className="text-[11px] font-medium text-ink-500">Reference year</span>
            <input
              value={periodYear}
              onChange={(event) => setPeriodYear(event.target.value.replace(/[^0-9]/g, ''))}
              className="rounded-md border border-ink-300 px-2 py-1.5 text-xs"
            />
          </label>
          <label className="col-span-2 flex flex-col gap-1">
            <span className="text-[11px] font-medium text-ink-500">Status</span>
            <select
              value={status}
              onChange={(event) => setStatus(event.target.value)}
              className="rounded-md border border-ink-300 px-2 py-1.5 text-xs"
            >
              <option value="manual_entry">Manual entry (observed)</option>
              <option value="reported">Reported</option>
              <option value="estimated">Estimate</option>
              <option value="projected">Projection</option>
              <option value="preliminary">Preliminary</option>
              <option value="missing">Missing</option>
            </select>
          </label>
          <label className="col-span-2 flex flex-col gap-1">
            <span className="text-[11px] font-medium text-ink-500">Why (recorded with the revision)</span>
            <input
              value={note}
              onChange={(event) => setNote(event.target.value)}
              placeholder="e.g. corrected against the 2023 statistical yearbook"
              className="rounded-md border border-ink-300 px-2 py-1.5 text-xs"
            />
          </label>
        </div>

        <p className="mt-3 text-[11px] text-ink-500">
          The old value is not overwritten: your correction is stored as a curated revision and served ahead of the
          ingested sources.
        </p>

        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={onCancel}>
            Cancel
          </Button>
          <Button
            variant="primary"
            disabled={pending || periodYear === ''}
            onClick={() =>
              onSave({
                value: value.trim() === '' ? null : Number(value),
                periodYear: Number(periodYear),
                status,
                note,
              })
            }
          >
            {pending ? 'Saving…' : 'Save correction'}
          </Button>
        </div>
      </div>
    </div>
  )
}
