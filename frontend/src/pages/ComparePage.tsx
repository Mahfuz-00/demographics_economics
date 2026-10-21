import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

import { api } from '../lib/api'
import { formatCompact, formatValue, unitLabel } from '../lib/format'
import { Card, ErrorState, Select, Spinner } from '../components/ui'

const COLOURS = ['#333845', '#1d4ed8', '#b45309', '#047857', '#7e22ce', '#be123c']

export function ComparePage() {
  const [indicatorCode, setIndicatorCode] = useState('pop.total')
  const [selected, setSelected] = useState<string[]>(['IND', 'USA', 'CHN'])
  const [from, setFrom] = useState(1960)
  const [includeProjections, setIncludeProjections] = useState(false)

  const indicators = useQuery({ queryKey: ['indicators'], queryFn: () => api.indicators() })
  const entities = useQuery({ queryKey: ['entities', 'countries_only'], queryFn: () => api.entities({ countries_only: 1 }) })

  const compare = useQuery({
    queryKey: ['compare', selected.join(','), indicatorCode, from, includeProjections],
    queryFn: () =>
      api.compare({
        entities: selected.join(','),
        indicator: indicatorCode,
        from,
        include_projections: includeProjections,
      }),
    enabled: selected.length > 0,
  })

  const indicatorOptions = useMemo(
    () => (indicators.data?.data ?? []).map((indicator) => ({ value: indicator.code, label: indicator.name })),
    [indicators.data],
  )

  const entityOptions = useMemo(
    () =>
      (entities.data?.data ?? []).map((entity) => ({
        value: entity.iso_alpha3 ?? entity.slug,
        label: `${entity.name} (${entity.iso_alpha3 ?? '—'})`,
      })),
    [entities.data],
  )

  // Recharts needs one row per period, with a column per entity.
  const chartRows = useMemo(() => {
    const byPeriod = new Map<string, Record<string, number | string | null>>()

    ;(compare.data?.data ?? []).forEach((series) => {
      const key = series.entity.iso_alpha3 ?? series.entity.slug

      series.points.forEach((point) => {
        if (point.value === null || point.is_missing) return

        const row = byPeriod.get(point.period) ?? { period: point.period }
        row[key] = point.value
        byPeriod.set(point.period, row)
      })
    })

    return Array.from(byPeriod.values()).sort((a, b) => Number(a.period) - Number(b.period))
  }, [compare.data])

  const toggle = (value: string) => {
    setSelected((current) =>
      current.includes(value) ? current.filter((item) => item !== value) : current.length >= 6 ? current : [...current, value],
    )
  }

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-lg font-semibold text-ink-900">Compare entities</h1>
        <p className="mt-1 text-xs text-ink-600">
          Aligned historical series for one indicator. Pick up to six entities; every line uses the same source-priority rules
          as the country view.
        </p>
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <Select label="Indicator" value={indicatorCode} onChange={setIndicatorCode} options={indicatorOptions} />
          <Select
            label="From year"
            value={String(from)}
            onChange={(value) => setFrom(Number(value))}
            options={[1900, 1950, 1960, 1980, 2000].map((year) => ({ value: String(year), label: String(year) }))}
          />
          <label className="flex items-center gap-2 pb-1 text-xs text-ink-600">
            <input
              type="checkbox"
              checked={includeProjections}
              onChange={(event) => setIncludeProjections(event.target.checked)}
            />
            Include projections
          </label>
        </div>

        {entities.data !== undefined && (
          <div className="mt-4">
            <p className="text-[11px] font-medium text-ink-500">Selected ({selected.length}/6)</p>
            <div className="mt-1 flex flex-wrap gap-1">
              {selected.map((key) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => toggle(key)}
                  className="rounded bg-ink-900 px-2 py-0.5 text-[11px] font-medium text-white"
                >
                  {key} ✕
                </button>
              ))}
            </div>
            <div className="mt-3 max-h-52 overflow-y-auto rounded border border-ink-200 p-2">
              <div className="grid grid-cols-2 gap-x-4 gap-y-1 md:grid-cols-4">
                {entityOptions.map((option) => (
                  <label key={option.value} className="flex items-center gap-1.5 text-[11px] text-ink-700">
                    <input
                      type="checkbox"
                      checked={selected.includes(option.value)}
                      onChange={() => toggle(option.value)}
                      disabled={!selected.includes(option.value) && selected.length >= 6}
                    />
                    <span className="truncate">{option.label}</span>
                  </label>
                ))}
              </div>
            </div>
          </div>
        )}
      </Card>

      {compare.isLoading && <Spinner />}
      {compare.error !== null && <ErrorState error={compare.error} />}

      {compare.data !== undefined && (
        <Card
          title={compare.data.indicator.name}
          subtitle={`${compare.data.data.length} entities · unit ${unitLabel(compare.data.indicator.unit) || compare.data.indicator.unit}`}
        >
          <div style={{ width: '100%', height: 360 }}>
            <ResponsiveContainer>
              <LineChart data={chartRows} margin={{ top: 8, right: 16, bottom: 8, left: 8 }}>
                <CartesianGrid stroke="#eceef2" vertical={false} />
                <XAxis dataKey="period" tick={{ fontSize: 11, fill: '#66738d' }} minTickGap={32} />
                <YAxis
                  tick={{ fontSize: 11, fill: '#66738d' }}
                  tickFormatter={(value: number) => formatCompact(value)}
                  width={68}
                />
                <Tooltip
                  formatter={(value: number, name: string) => [
                    formatValue(value, compare.data.indicator.unit, compare.data.indicator.decimals),
                    name,
                  ]}
                  contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #d5d9e2' }}
                />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                {compare.data.data.map((series, index) => {
                  const key = series.entity.iso_alpha3 ?? series.entity.slug

                  return (
                    <Line
                      key={key}
                      type="monotone"
                      dataKey={key}
                      name={key}
                      stroke={COLOURS[index % COLOURS.length]}
                      strokeWidth={2}
                      dot={false}
                      connectNulls={false}
                      isAnimationActive={false}
                    />
                  )
                })}
              </LineChart>
            </ResponsiveContainer>
          </div>

          <div className="mt-4 max-h-80 overflow-y-auto">
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-white">
                <tr className="border-b border-ink-200 text-left text-ink-500">
                  <th className="py-2 pr-3 font-medium">Entity</th>
                  <th className="py-2 pr-3 font-medium">Latest period</th>
                  <th className="py-2 pr-3 font-medium">Latest value</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 pr-3 font-medium">Source</th>
                  <th className="py-2 pr-3 font-medium">Periods</th>
                </tr>
              </thead>
              <tbody>
                {compare.data.data.map((series) => {
                  const latest = series.points[series.points.length - 1]

                  return (
                    <tr key={series.entity.uuid} className="border-b border-ink-100 last:border-0">
                      <td className="py-1.5 pr-3 text-ink-800">{series.entity.name}</td>
                      <td className="tnum py-1.5 pr-3 text-ink-600">{latest?.period ?? '—'}</td>
                      <td className="tnum py-1.5 pr-3 text-ink-900">
                        {latest !== undefined
                          ? formatValue(latest.value, compare.data.indicator.unit, compare.data.indicator.decimals)
                          : '—'}
                      </td>
                      <td className="py-1.5 pr-3 text-ink-600">{latest?.status ?? '—'}</td>
                      <td className="py-1.5 pr-3 text-ink-600">{latest?.source?.publisher_short ?? '—'}</td>
                      <td className="tnum py-1.5 pr-3 text-ink-600">{series.points.length}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )
}
