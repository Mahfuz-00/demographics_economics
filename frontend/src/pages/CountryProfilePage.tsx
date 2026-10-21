import { useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download } from 'lucide-react'

import { api, getToken } from '../lib/api'
import type { IndicatorRef, SeriesPoint } from '../lib/types'
import { formatDateTime, unitLabel } from '../lib/format'
import { CountryAllData } from '../components/CountryAllData'
import { CurrentVitalStatistics } from '../components/CurrentVitalStatistics'
import { ProvenanceDrawer } from '../components/ProvenanceDrawer'
import { SeriesChart } from '../components/SeriesChart'
import { ValueCell } from '../components/ValueCell'
import { Badge, Button, Card, EmptyState, ErrorState, Spinner } from '../components/ui'

export function CountryProfilePage() {
  const { key = '' } = useParams()

  const [selected, setSelected] = useState<string | null>(null)
  const [includeProjections, setIncludeProjections] = useState(false)
  const [provenanceUuid, setProvenanceUuid] = useState<string | null>(null)
  const [fetchMessage, setFetchMessage] = useState<string | null>(null)

  const authenticated = getToken() !== null
  const queryClient = useQueryClient()

  const entity = useQuery({ queryKey: ['entity', key], queryFn: () => api.entity(key) })
  const indicators = useQuery({ queryKey: ['indicators'], queryFn: () => api.indicators() })

  const fetchSource = useMutation({
    mutationFn: () => api.admin.fetchCountry(key),
    onSuccess: (result) => {
      const parts = [
        `${result.observations_created} new value(s)`,
        `${result.observations_unchanged} already held`,
      ]

      if (result.searched_web && result.candidates.length > 0) {
        parts.push(
          `nothing usable was found, so ${result.candidates.length} candidate source(s) were queued for review`,
        )
      } else if (result.searched_web) {
        parts.push('nothing usable was found and the web search returned no candidates')
      }

      setFetchMessage(parts.join(' · '))
      queryClient.invalidateQueries({ queryKey: ['latest'] })
      queryClient.invalidateQueries({ queryKey: ['entity-observations'] })
      queryClient.invalidateQueries({ queryKey: ['series'] })
      queryClient.invalidateQueries({ queryKey: ['coverage'] })
    },
    onError: (error: Error) => setFetchMessage(error.message),
  })

  const codes = useMemo(() => (indicators.data?.data ?? []).map((indicator) => indicator.code), [indicators.data])

  const latest = useQuery({
    queryKey: ['latest', key, codes.join(','), includeProjections],
    queryFn: () => api.latest(key, codes.join(','), includeProjections),
    enabled: codes.length > 0,
  })

  const series = useQuery({
    queryKey: ['series', key, selected, includeProjections],
    queryFn: () =>
      api.series({
        entity: key,
        indicator: selected as string,
        frequency: 'all',
        include_projections: includeProjections,
      }),
    enabled: selected !== null,
  })

  const selectedIndicator: IndicatorRef | undefined = (indicators.data?.data ?? []).find(
    (indicator) => indicator.code === selected,
  )

  const grouped = useMemo(() => {
    const rows = latest.data?.data ?? []
    const groups = new Map<string, { name: string; rows: typeof rows }>()

    rows.forEach((row) => {
      const groupKey = row.indicator.category?.code ?? 'other'
      const existing = groups.get(groupKey)

      if (existing === undefined) {
        groups.set(groupKey, { name: row.indicator.category?.name ?? 'Other', rows: [row] })
      } else {
        existing.rows.push(row)
      }
    })

    return Array.from(groups.entries())
  }, [latest.data])

  const latestPoints: SeriesPoint[] = (series.data?.points ?? []).slice().reverse()

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-ink-900">
            {entity.data?.data.name ?? 'Loading…'}
            {entity.data?.data.iso_alpha3 !== null && entity.data !== undefined && (
              <span className="ml-2 font-mono text-xs text-ink-500">{entity.data.data.iso_alpha3}</span>
            )}
          </h1>
          {entity.data !== undefined && (
            <p className="mt-1 text-xs text-ink-600">
              {entity.data.data.entity_type} · {entity.data.data.continent ?? '—'}
              {entity.data.data.subregion !== null && ` · ${entity.data.data.subregion}`}
              {entity.data.data.sovereignty !== null && ` · sovereign: ${entity.data.data.sovereignty.name}`}
              {entity.data.data.csv_label !== null && ` · label in source spreadsheet: “${entity.data.data.csv_label}”`}
            </p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-2 text-xs text-ink-600">
            <input
              type="checkbox"
              checked={includeProjections}
              onChange={(event) => setIncludeProjections(event.target.checked)}
            />
            Include projections
          </label>
          {authenticated && (
            <Button
              variant="primary"
              onClick={() => fetchSource.mutate()}
              disabled={fetchSource.isPending}
              title="Scrape this country's vital-statistics tables from its Wikipedia article. If nothing is found, a web search queues candidate sources for review."
            >
              <Download size={12} />
              {fetchSource.isPending ? 'Getting data…' : 'Get data from source'}
            </Button>
          )}
        </div>
      </div>

      {fetchMessage !== null && (
        <div className="rounded border border-ink-200 bg-white px-3 py-2 text-xs text-ink-700">{fetchMessage}</div>
      )}

      {entity.isLoading && <Spinner />}
      {entity.error !== null && <ErrorState error={entity.error} />}

      {entity.data !== undefined && entity.data.data.national_statistics_offices.length > 0 && (
        <Card title="National statistical authority" subtitle="Outranks every other source when values disagree.">
          <ul className="space-y-1 text-xs">
            {entity.data.data.national_statistics_offices.map((office) => (
              <li key={office.office_name} className="text-ink-700">
                <span className="font-medium text-ink-900">{office.office_name}</span>
                {office.website_url !== null && (
                  <>
                    {' · '}
                    <a className="text-sky-700 underline" href={office.website_url} target="_blank" rel="noreferrer">
                      website
                    </a>
                  </>
                )}
                {office.data_portal_url !== null && (
                  <>
                    {' · '}
                    <a className="text-sky-700 underline" href={office.data_portal_url} target="_blank" rel="noreferrer">
                      data portal
                    </a>
                  </>
                )}
                {office.access_notes !== null && <p className="mt-0.5 text-[11px] text-ink-500">{office.access_notes}</p>}
              </li>
            ))}
          </ul>
        </Card>
      )}

      {series.data !== undefined && selectedIndicator !== undefined && (
        <Card
          title={`${selectedIndicator.name} — history`}
          subtitle={`${series.data.count} periods held · ${series.data.conflicting_periods} with a source disagreement · ${series.data.missing_periods} recorded as missing`}
          actions={
            <Button variant="ghost" onClick={() => setSelected(null)}>
              Close
            </Button>
          }
        >
          <SeriesChart
            points={series.data.points}
            unit={selectedIndicator.unit}
            decimals={selectedIndicator.decimals}
            onSelect={(point) => setProvenanceUuid(point.observation_uuid)}
          />
          <div className="mt-3 max-h-72 overflow-y-auto">
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-white">
                <tr className="border-b border-ink-200 text-left text-ink-500">
                  <th className="py-2 pr-3 font-medium">Period</th>
                  <th className="py-2 pr-3 font-medium">Value</th>
                  <th className="py-2 pr-3 font-medium">Source</th>
                  <th className="py-2 pr-3 font-medium">Retrieved</th>
                </tr>
              </thead>
              <tbody>
                {latestPoints.map((point) => (
                  <tr key={point.observation_uuid} className="border-b border-ink-100 last:border-0">
                    <td className="tnum py-1.5 pr-3 text-ink-700">{point.period}</td>
                    <td className="py-1.5 pr-3">
                      <ValueCell
                        point={point}
                        decimals={selectedIndicator.decimals}
                        onOpenProvenance={setProvenanceUuid}
                        showUnit={false}
                      />
                    </td>
                    <td className="py-1.5 pr-3 text-ink-600">
                      {point.source?.publisher_short ?? point.source?.code}
                      <span className="ml-1 text-ink-400" title={`Credibility rank ${point.source?.credibility_rank}`}>
                        (rank {point.source?.credibility_rank})
                      </span>
                    </td>
                    <td className="py-1.5 pr-3 text-ink-500">{formatDateTime(point.retrieved_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-2 text-[11px] text-ink-500">
            Reference periods are those stated by the source. Drafted or modelled figures carry their own status and are never
            merged with observed ones.
          </p>
        </Card>
      )}

      {latest.isLoading && <Spinner label="Loading latest values…" />}
      {latest.error !== null && <ErrorState error={latest.error} />}
      {latest.data !== undefined && latest.data.data.length === 0 && (
        <EmptyState>No observations held for this entity.</EmptyState>
      )}

      {grouped.map(([code, group]) => (
        <Card key={code} title={group.name}>
          <ul className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
            {group.rows.map((row) => (
              <li
                key={row.indicator.code}
                className={`rounded-md border px-3 py-2 ${
                  selected === row.indicator.code ? 'border-ink-900 bg-ink-50' : 'border-ink-200'
                }`}
              >
                {/* The indicator name is the click target for the history chart. The
                    value row sits outside it so the interactive elements inside
                    ValueCell are never nested inside another button. */}
                <div className="flex items-start justify-between gap-2">
                  <button
                    type="button"
                    onClick={() => setSelected(row.indicator.code)}
                    className="text-left text-xs font-medium text-ink-800 underline decoration-ink-200 hover:decoration-ink-600"
                  >
                    {row.indicator.name}
                  </button>
                  {row.latest !== null && <Badge tone="neutral">{row.latest.period}</Badge>}
                </div>
                <div className="mt-1">
                  <ValueCell
                    point={row.latest}
                    decimals={row.indicator.decimals}
                    onOpenProvenance={setProvenanceUuid}
                    showUnit={false}
                  />
                </div>
                <div className="mt-1 text-[11px] text-ink-500">
                  {unitLabel(row.indicator.unit) || row.indicator.unit}
                  {row.latest?.source !== undefined && row.latest?.source !== null && (
                    <> · {row.latest.source.publisher_short ?? row.latest.source.code}</>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </Card>
      ))}

      <CurrentVitalStatistics entityKey={key} />

      <CountryAllData entityKey={key} authenticated={authenticated} onOpenProvenance={setProvenanceUuid} />

      <ProvenanceDrawer uuid={provenanceUuid} onClose={() => setProvenanceUuid(null)} />
    </div>
  )
}
