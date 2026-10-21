import { useMemo } from 'react'
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

import type { SeriesPoint } from '../lib/types'
import { formatCompact, formatValue, unitLabel } from '../lib/format'

interface Row {
  period: string
  actual: number | null
  projected: number | null
  source?: SeriesPoint
}

/**
 * Historical series.
 *
 * Reported values and projections are drawn as separate series so a modelled figure
 * can never be read as an observed one, and periods the sources do not cover remain
 * gaps rather than being bridged or filled.
 */
export function SeriesChart({
  points,
  unit,
  decimals,
  height = 280,
  onSelect,
}: {
  points: SeriesPoint[]
  unit: string
  decimals: number
  height?: number
  onSelect?: (point: SeriesPoint) => void
}) {
  const rows = useMemo<Row[]>(
    () =>
      points.map((point) => ({
        period: point.period,
        actual: point.value === null || point.is_projection ? null : point.value,
        projected: point.value !== null && point.is_projection ? point.value : null,
        source: point,
      })),
    [points],
  )

  const hasProjections = rows.some((row) => row.projected !== null)

  const selectByPeriod = (period: string) => {
    const point = points.find((candidate) => candidate.period === period)
    if (point !== undefined) onSelect?.(point)
  }

  return (
    <div style={{ width: '100%', height }}>
      <ResponsiveContainer>
        <LineChart
          data={rows}
          margin={{ top: 8, right: 16, bottom: 8, left: 8 }}
          onClick={(state) => {
            const period = (state as { activeLabel?: string | number } | null)?.activeLabel
            if (period !== undefined && period !== null) selectByPeriod(String(period))
          }}
        >
          <CartesianGrid stroke="#eceef2" vertical={false} />
          <XAxis
            dataKey="period"
            tick={{ fontSize: 11, fill: '#66738d' }}
            interval="preserveStartEnd"
            minTickGap={24}
          />
          <YAxis
            tick={{ fontSize: 11, fill: '#66738d' }}
            tickFormatter={(value: number) => formatCompact(value)}
            width={64}
          />
          <Tooltip
            formatter={(value: number, name: string) => [
              `${formatValue(value, unit, decimals)} ${unitLabel(unit)}`.trim(),
              name === 'projected' ? 'Projection' : 'Reported',
            ]}
            labelFormatter={(label: string) => `Period ${label}`}
            contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #d5d9e2' }}
          />
          {hasProjections && <Legend wrapperStyle={{ fontSize: 11 }} />}
          <Line
            type="monotone"
            dataKey="actual"
            name="Reported"
            stroke="#333845"
            strokeWidth={2}
            dot={false}
            activeDot={{ r: 4 }}
            connectNulls={false}
            isAnimationActive={false}
          />
          {hasProjections && (
            <Line
              type="monotone"
              dataKey="projected"
              name="Projection"
              stroke="#d97706"
              strokeWidth={2}
              strokeDasharray="4 3"
              dot={false}
              activeDot={{ r: 4 }}
              connectNulls={false}
              isAnimationActive={false}
            />
          )}
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}
