import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CartesianGrid, ResponsiveContainer, Scatter, ScatterChart, Tooltip, XAxis, YAxis } from 'recharts'

import { api } from '../lib/api'
import type { CorrelationResponse, MoversResponse, PeersResponse, SourceGapResponse } from '../lib/types'
import { formatNumber, formatValue, unitLabel } from '../lib/format'
import { Badge, Card, EmptyState, ErrorState, Select, Spinner } from '../components/ui'

/** Indicators to offer, ordered so the usual suspects come first. */
const SUGGESTED = [
  'fert.tfr',
  'economy.gdp.per_capita.nominal',
  'death.life_expectancy',
  'birth.rate.crude',
  'pop.total',
  'debt.public_pct_gdp',
  'economy.gdp.ppp_bn',
]

/**
 * Two questions a table of values cannot answer:
 *
 *  - how far apart do sources disagree about the same country and year;
 *  - does one indicator move with another across countries.
 *
 * Both are computed server-side. Neither is a claim of correctness or of cause, and the
 * page says so rather than letting a chart imply more than it should.
 */
export function AnalysisPage() {
  const indicators = useQuery({ queryKey: ['indicators'], queryFn: () => api.indicators() })

  const options = useMemo(() => {
    const all = indicators.data?.data ?? []
    const byCode = new Map(all.map((indicator) => [indicator.code, indicator]))

    const ordered = [
      ...SUGGESTED.filter((code) => byCode.has(code)).map((code) => byCode.get(code)!),
      ...all.filter((indicator) => !SUGGESTED.includes(indicator.code)),
    ]

    return ordered.map((indicator) => ({
      value: indicator.code,
      label: `${indicator.name} — ${unitLabel(indicator.unit) || indicator.unit}`,
    }))
  }, [indicators.data])

  const [gapIndicator, setGapIndicator] = useState('pop.total')
  const [xCode, setXCode] = useState('fert.tfr')
  const [yCode, setYCode] = useState('economy.gdp.per_capita.nominal')
  const [entityKey, setEntityKey] = useState('IND')
  const [peerCode, setPeerCode] = useState('fert.tfr')
  const [metricCode, setMetricCode] = useState('economy.gdp.per_capita.nominal')
  const [moverCode, setMoverCode] = useState('pop.total')
  const [continent, setContinent] = useState('')

  const peers = useQuery<PeersResponse>({
    queryKey: ['analysis', 'peers', entityKey, peerCode, metricCode],
    queryFn: () => api.peers({ entity: entityKey.trim(), peer: peerCode, metric: metricCode }),
    enabled: entityKey.trim() !== '' && peerCode !== metricCode,
  })

  const movers = useQuery<MoversResponse>({
    queryKey: ['analysis', 'movers', moverCode],
    queryFn: () => api.movers({ indicator: moverCode }),
    enabled: moverCode !== '',
  })

  const gap = useQuery<SourceGapResponse>({
    queryKey: ['analysis', 'source-gap', gapIndicator],
    queryFn: () => api.sourceGap({ indicator: gapIndicator }),
    enabled: gapIndicator !== '',
  })

  const correlation = useQuery<CorrelationResponse>({
    queryKey: ['analysis', 'correlation', xCode, yCode, continent],
    queryFn: () =>
      api.correlation({ x: xCode, y: yCode, ...(continent === '' ? {} : { continent }) }),
    enabled: xCode !== '' && yCode !== '' && xCode !== yCode,
  })

  const sources = gap.data?.meta.sources_available ?? []

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-ink-900">Analysis</h1>
        <p className="mt-1 text-xs text-ink-600">
          How far apart the sources are for one indicator, and whether two indicators move together across countries.
        </p>
      </div>

      <Card
        title="Sources disagree"
        subtitle="Per country: what each source says for the same year, and how far apart they are."
      >
        <div className="mb-3 flex flex-wrap items-end gap-3">
          <Select label="Indicator" value={gapIndicator} onChange={setGapIndicator} options={options} />
        </div>

        {gap.isLoading && <Spinner />}
        {gap.error !== null && <ErrorState error={gap.error} />}

        {gap.data !== undefined && (
          <>
            <div className="mb-3 flex flex-wrap items-center gap-2 text-[11px] text-ink-600">
              <Badge tone="neutral">{gap.data.year}</Badge>
              <span>
                {gap.data.meta.comparable_entities} countries with two or more sources · median{' '}
                <span className="font-medium text-ink-800">{formatNumber(gap.data.meta.median_pct, 2)}%</span> apart · worst{' '}
                <span className="font-medium text-ink-800">{formatNumber(gap.data.meta.max_pct, 2)}%</span>
              </span>
            </div>

            {gap.data.rows.length === 0 ? (
              <EmptyState>
                Only one source holds this indicator, so there is nothing to compare. The UN and national-statistics
                feeds are registered but hold no data yet.
              </EmptyState>
            ) : (
              <div className="max-h-96 overflow-auto">
                <table className="w-full text-xs">
                  <thead className="sticky top-0 bg-white">
                    <tr className="border-b border-ink-200 text-right text-ink-500">
                      <th className="py-2 pr-3 text-left font-medium">Country</th>
                      {sources.map((source) => (
                        <th key={source.code} className="py-2 pr-3 font-medium">
                          {source.publisher ?? source.code}
                        </th>
                      ))}
                      <th className="py-2 pr-3 font-medium">Apart</th>
                    </tr>
                  </thead>
                  <tbody>
                    {gap.data.rows.map((row) => (
                      <tr key={row.entity.slug} className="border-b border-ink-100 last:border-0">
                        <td className="py-1.5 pr-3 text-ink-800">{row.entity.name}</td>
                        {sources.map((source) => {
                          const cell = row.values[source.code]

                          return (
                            <td key={source.code} className="tnum py-1.5 pr-3 text-right text-ink-700">
                              {cell === undefined
                                ? '—'
                                : formatValue(cell.value, gap.data!.indicator.unit, gap.data!.indicator.decimals)}
                              {cell?.derived === true && <span className="ml-1 text-[9px] uppercase text-ink-400">calc</span>}
                            </td>
                          )
                        })}
                        <td className="tnum py-1.5 pr-3 text-right font-medium text-ink-900">
                          {formatNumber(row.pct, 2)}%
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <p className="mt-2 text-[11px] text-ink-500">{gap.data.meta.note}</p>
          </>
        )}
      </Card>

      <Card
        title="Correlation"
        subtitle="Pearson coefficient across countries for one year. Association only — no causal claim, no adjustment for confounders."
      >
        <div className="mb-3 flex flex-wrap items-end gap-3">
          <Select label="X axis" value={xCode} onChange={setXCode} options={options} />
          <Select label="Y axis" value={yCode} onChange={setYCode} options={options} />
          <label className="flex flex-col gap-1">
            <span className="text-[11px] font-medium text-ink-500">Continents</span>
            <select
              value={continent}
              onChange={(event) => setContinent(event.target.value)}
              className="rounded-md border border-ink-300 bg-white px-2 py-1.5 text-xs"
            >
              <option value="">All continents</option>
              <option value="Africa">Africa</option>
              <option value="Asia">Asia</option>
              <option value="Europe">Europe</option>
              <option value="North America">North America</option>
              <option value="South America">South America</option>
              <option value="Oceania">Oceania</option>
            </select>
          </label>
        </div>

        {xCode === yCode && <EmptyState>Pick two different indicators.</EmptyState>}
        {correlation.isLoading && <Spinner />}
        {correlation.error !== null && <ErrorState error={correlation.error} />}

        {correlation.data !== undefined && xCode !== yCode && (
          <>
            <div className="mb-3 flex flex-wrap items-center gap-2 text-[11px] text-ink-600">
              <Badge tone={correlation.data.r === null ? 'neutral' : correlation.data.r < 0 ? 'warn' : 'info'}>
                r = {correlation.data.r === null ? '—' : correlation.data.r.toFixed(3)}
              </Badge>
              <span>
                n = {correlation.data.n} countries · {correlation.data.year ?? '—'}
              </span>
            </div>

            {correlation.data.points.length < 3 ? (
              <EmptyState>Not enough overlapping countries to correlate.</EmptyState>
            ) : (
              <div className="h-80 w-full">
                <ResponsiveContainer width="100%" height="100%">
                  <ScatterChart margin={{ top: 8, right: 16, bottom: 24, left: 8 }}>
                    <CartesianGrid stroke="#e5e5e5" />
                    <XAxis
                      dataKey="x"
                      type="number"
                      tick={{ fontSize: 10 }}
                      tickFormatter={(value: number) => formatNumber(value, 0)}
                      label={{ value: correlation.data.x.name, position: 'insideBottom', offset: -12, fontSize: 10 }}
                    />
                    <YAxis
                      dataKey="y"
                      type="number"
                      tick={{ fontSize: 10 }}
                      tickFormatter={(value: number) => formatNumber(value, 0)}
                      width={70}
                    />
                    <Tooltip
                      formatter={(value: number, name: string) => [
                        formatNumber(value, 2),
                        name === 'x' ? correlation.data!.x.name : correlation.data!.y.name,
                      ]}
                      labelFormatter={() => ''}
                      content={({ payload }) => {
                        const point = payload?.[0]?.payload as { entity?: { name: string } } | undefined

                        return point?.entity === undefined ? null : (
                          <div className="rounded border border-ink-200 bg-white px-2 py-1 text-[11px] shadow">
                            {point.entity.name}
                          </div>
                        )
                      }}
                    />
                    <Scatter data={correlation.data.points} fill="#0f766e" fillOpacity={0.6} />
                  </ScatterChart>
                </ResponsiveContainer>
              </div>
            )}

            {correlation.data.meta.continents.length > 0 && (
              <div className="mt-3">
                <p className="mb-1 text-[11px] font-medium text-ink-600">
                  Within each continent — a pooled coefficient can hide an opposite relationship inside a group:
                </p>
                <div className="flex flex-wrap gap-2">
                  {correlation.data.meta.continents.map((entry) => (
                    <span key={entry.continent} className="rounded border border-ink-200 px-2 py-1 text-[11px] text-ink-700">
                      {entry.continent} <span className="text-ink-400">n={entry.n}</span>{' '}
                      <span className="font-medium">r={entry.r === null ? '—' : entry.r.toFixed(2)}</span>
                    </span>
                  ))}
                </div>
              </div>
            )}

            <p className="mt-2 text-[11px] text-ink-500">{correlation.data.meta.note}</p>
          </>
        )}
      </Card>

      <Card
        title="Peer group"
        subtitle="One country against countries in the same continent with a similar value of the reference indicator."
      >
        <div className="mb-3 flex flex-wrap items-end gap-3">
          <label className="flex flex-col gap-1">
            <span className="text-[11px] font-medium text-ink-500">Country (ISO3)</span>
            <input
              value={entityKey}
              onChange={(event) => setEntityKey(event.target.value.toUpperCase())}
              placeholder="IND"
              className="w-20 rounded-md border border-ink-300 bg-white px-2 py-1.5 text-xs"
            />
          </label>
          <Select label="Peer by" value={peerCode} onChange={setPeerCode} options={options} />
          <Select label="Measure" value={metricCode} onChange={setMetricCode} options={options} />
        </div>

        {peerCode === metricCode && <EmptyState>Pick two different indicators.</EmptyState>}
        {peers.isLoading && <Spinner />}
        {peers.error !== null && <ErrorState error={peers.error} />}

        {peers.data !== undefined && peerCode !== metricCode && (
          <>
            <div className="mb-3 flex flex-wrap items-center gap-2 text-[11px] text-ink-600">
              <Badge tone="neutral">{peers.data.year}</Badge>
              <span>
                {peers.data.group.n} peers (±{formatNumber(peers.data.group.tolerance, 2)} on{' '}
                {peers.data.peer_indicator.name.toLowerCase()}, same continent) · median{' '}
                <span className="font-medium text-ink-800">
                  {formatValue(peers.data.group.median, peers.data.metric_indicator.unit, peers.data.metric_indicator.decimals)}
                </span>{' '}
                · {peers.data.entity.name} at the{' '}
                <span className="font-medium text-ink-800">{formatNumber(peers.data.group.target_percentile, 1)}th</span>{' '}
                percentile
              </span>
            </div>

            {peers.data.members.length === 0 ? (
              <EmptyState>No peers within that tolerance.</EmptyState>
            ) : (
              <div className="max-h-80 overflow-auto">
                <table className="w-full text-xs">
                  <thead className="sticky top-0 bg-white">
                    <tr className="border-b border-ink-200 text-right text-ink-500">
                      <th className="py-2 pr-3 text-left font-medium">Country</th>
                      <th className="py-2 pr-3 font-medium">{peers.data.peer_indicator.name}</th>
                      <th className="py-2 pr-3 font-medium">{peers.data.metric_indicator.name}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {peers.data.members.map((member) => (
                      <tr
                        key={member.entity.slug}
                        className={`border-b border-ink-100 last:border-0 ${member.is_target ? 'bg-ink-50 font-medium' : ''}`}
                      >
                        <td className="py-1.5 pr-3 text-ink-800">{member.entity.name}</td>
                        <td className="tnum py-1.5 pr-3 text-right text-ink-700">
                          {formatValue(member.peer_value, peers.data!.peer_indicator.unit, peers.data!.peer_indicator.decimals)}
                        </td>
                        <td className="tnum py-1.5 pr-3 text-right text-ink-700">
                          {formatValue(member.metric_value, peers.data!.metric_indicator.unit, peers.data!.metric_indicator.decimals)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <p className="mt-2 text-[11px] text-ink-500">{peers.data.meta.note}</p>
          </>
        )}
      </Card>

      <Card
        title="Biggest movers"
        subtitle="Largest change between two comparable years. A flagged row may be a real event or a mis-read source cell."
      >
        <div className="mb-3 flex flex-wrap items-end gap-3">
          <Select label="Indicator" value={moverCode} onChange={setMoverCode} options={options} />
        </div>

        {movers.isLoading && <Spinner />}
        {movers.error !== null && <ErrorState error={movers.error} />}

        {movers.data !== undefined && (
          <>
            <div className="mb-3 flex flex-wrap items-center gap-2 text-[11px] text-ink-600">
              <Badge tone="neutral">
                {movers.data.from} → {movers.data.to}
              </Badge>
              <span>
                {movers.data.meta.comparable_entities} countries ·{' '}
                <span className="font-medium text-ink-800">{movers.data.meta.flagged_for_review}</span> flagged for review
              </span>
            </div>

            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-ink-200 text-right text-ink-500">
                  <th className="py-2 pr-3 text-left font-medium">Country</th>
                  <th className="py-2 pr-3 font-medium">{movers.data.from}</th>
                  <th className="py-2 pr-3 font-medium">{movers.data.to}</th>
                  <th className="py-2 pr-3 font-medium">Change</th>
                </tr>
              </thead>
              <tbody>
                {movers.data.rows.map((row) => (
                  <tr key={row.entity.slug} className="border-b border-ink-100 last:border-0">
                    <td className="py-1.5 pr-3 text-ink-800">{row.entity.name}</td>
                    <td className="tnum py-1.5 pr-3 text-right text-ink-600">
                      {formatValue(row.from_value, movers.data!.indicator.unit, movers.data!.indicator.decimals)}
                    </td>
                    <td className="tnum py-1.5 pr-3 text-right text-ink-600">
                      {formatValue(row.to_value, movers.data!.indicator.unit, movers.data!.indicator.decimals)}
                    </td>
                    <td className="tnum py-1.5 pr-3 text-right font-medium">
                      <span className={row.pct < 0 ? 'text-rose-700' : 'text-emerald-700'}>
                        {row.pct > 0 ? '+' : ''}
                        {formatNumber(row.pct, 2)}%
                      </span>
                      {row.suspect && <span className="ml-1 text-[9px] uppercase text-amber-600">review</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <p className="mt-2 text-[11px] text-ink-500">{movers.data.meta.note}</p>
          </>
        )}
      </Card>
    </div>
  )
}
