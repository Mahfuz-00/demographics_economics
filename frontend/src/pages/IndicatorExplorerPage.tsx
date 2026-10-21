import { useMemo, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'

import { api } from '../lib/api'
import { formatDateTime, unitLabel } from '../lib/format'
import { ProvenanceDrawer } from '../components/ProvenanceDrawer'
import { SeriesChart } from '../components/SeriesChart'
import { ValueCell } from '../components/ValueCell'
import { Button, Card, ErrorState, Select, Spinner } from '../components/ui'

export function IndicatorExplorerPage() {
  const { code = '' } = useParams()

  const [entityKey, setEntityKey] = useState('IND')
  const [includeProjections, setIncludeProjections] = useState(false)
  const [provenanceUuid, setProvenanceUuid] = useState<string | null>(null)

  const indicator = useQuery({ queryKey: ['indicator', code], queryFn: () => api.indicator(code) })
  const entities = useQuery({
    queryKey: ['entities', 'countries_only'],
    queryFn: () => api.entities({ countries_only: 1 }),
  })

  const series = useQuery({
    queryKey: ['series', entityKey, code, includeProjections],
    queryFn: () => api.series({ entity: entityKey, indicator: code, frequency: 'all', include_projections: includeProjections }),
  })

  const options = useMemo(
    () =>
      (entities.data?.data ?? []).map((entity) => ({
        value: entity.iso_alpha3 ?? entity.slug,
        label: `${entity.name} (${entity.iso_alpha3 ?? '—'})`,
      })),
    [entities.data],
  )

  const points = (series.data?.points ?? []).slice().reverse()

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-ink-900">{indicator.data?.data.name ?? code}</h1>
          <p className="mt-1 max-w-3xl text-xs text-ink-600">{indicator.data?.data.description}</p>
          {indicator.data !== undefined && (
            <p className="mt-1 text-[11px] text-ink-500">
              Unit: {unitLabel(indicator.data.data.unit) || indicator.data.data.unit} · value kind:{' '}
              {indicator.data.data.value_kind} · module: {indicator.data.data.module}
              {!indicator.data.data.supports_disaggregation && ' · published annually only'}
            </p>
          )}
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <Select label="Entity" value={entityKey} onChange={setEntityKey} options={options} />
          <label className="flex items-center gap-2 pb-1 text-xs text-ink-600">
            <input
              type="checkbox"
              checked={includeProjections}
              onChange={(event) => setIncludeProjections(event.target.checked)}
            />
            Include projections
          </label>
        </div>
      </div>

      {series.isLoading && <Spinner />}
      {series.error !== null && <ErrorState error={series.error} />}

      {series.data !== undefined && indicator.data !== undefined && (
        <>
          <Card
            title={`${series.data.entity.name} — ${series.data.count} periods`}
            subtitle={`${series.data.conflicting_periods} period(s) where sources disagree · ${series.data.missing_periods} recorded as missing`}
            actions={
              <Link to={`/countries/${series.data.entity.iso_alpha3 ?? series.data.entity.slug}`} className="text-[11px] text-sky-700 underline">
                Open country profile
              </Link>
            }
          >
            <SeriesChart
              points={series.data.points}
              unit={indicator.data.data.unit}
              decimals={indicator.data.data.decimals}
              onSelect={(point) => setProvenanceUuid(point.observation_uuid)}
            />
            {series.data.points.some((point) => point.is_projection) === false && (
              <p className="mt-2 text-[11px] text-ink-500">
                Projections are hidden. Enable them above to see modelled figures, which are drawn separately and never merged
                with observed values.
              </p>
            )}
          </Card>

          <Card title="Historical table">
            <div className="max-h-[32rem] overflow-y-auto">
              <table className="w-full text-xs">
                <thead className="sticky top-0 bg-white">
                  <tr className="border-b border-ink-200 text-left text-ink-500">
                    <th className="py-2 pr-3 font-medium">Period</th>
                    <th className="py-2 pr-3 font-medium">Value</th>
                    <th className="py-2 pr-3 font-medium">Status</th>
                    <th className="py-2 pr-3 font-medium">Source</th>
                    <th className="py-2 pr-3 font-medium">Retrieved</th>
                    <th className="py-2 pr-3 font-medium"></th>
                  </tr>
                </thead>
                <tbody>
                  {points.map((point) => (
                    <tr key={point.observation_uuid} className="border-b border-ink-100 last:border-0">
                      <td className="tnum py-1.5 pr-3 text-ink-700">{point.period}</td>
                      <td className="py-1.5 pr-3">
                        <ValueCell point={point} decimals={indicator.data.data.decimals} showUnit={false} />
                      </td>
                      <td className="py-1.5 pr-3 text-ink-600">{point.status}</td>
                      <td className="py-1.5 pr-3 text-ink-600">
                        {point.source?.publisher_short ?? point.source?.code}
                        {point.reference_note !== null && (
                          <span className="ml-1 text-ink-400" title={point.reference_note}>
                            ⓘ
                          </span>
                        )}
                      </td>
                      <td className="py-1.5 pr-3 text-ink-500">{formatDateTime(point.retrieved_at)}</td>
                      <td className="py-1.5 pr-3">
                        <Button variant="ghost" onClick={() => setProvenanceUuid(point.observation_uuid)}>
                          trace
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}

      <ProvenanceDrawer uuid={provenanceUuid} onClose={() => setProvenanceUuid(null)} />
    </div>
  )
}
