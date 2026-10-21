import { useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ComposableMap, Geographies, Geography } from 'react-simple-maps'

import topo from '../assets/countries-110m.json'
import { api } from '../lib/api'
import type { IndicatorRef, MatrixCell } from '../lib/types'
import { formatValue, unitLabel } from '../lib/format'
import { ISO_NUMERIC } from '../lib/isoNumeric'
import { Badge, Card, ErrorState, Select, Spinner } from '../components/ui'

/** No value held. Deliberately its own colour, never the bottom of the ramp. */
const MISSING_FILL = '#e7e5e4'

/**
 * Five distinct bands, light to dark.
 *
 * A single-hue ramp (five blues) makes neighbouring bands hard to tell apart on a map,
 * especially when countries of the same band share a border. These are five different
 * colours that still read as an order: pale yellow for the lowest band through to deep
 * red for the highest.
 */
const RAMP = ['#ffffb2', '#fecc5c', '#fd8d3c', '#f03b20', '#bd0026']

/**
 * A choropleth of one indicator's latest value per country.
 *
 * Three rules this follows, because a map makes it easy to lie:
 *
 *  - a country with no value is **grey**, never the lowest colour — otherwise "no data"
 *    reads as "very low";
 *  - aggregates (World, EU, continents) are excluded, since they have no geometry but
 *    would colour whatever matched them;
 *  - the scale is **quantile**, not linear. GDP per capita spans 200 to 155,000, and a
 *    linear ramp paints almost every country the same shade.
 */
export function MapPage() {
  const indicators = useQuery({ queryKey: ['indicators'], queryFn: () => api.indicators() })
  const [code, setCode] = useState('fert.tfr')
  const [hovered, setHovered] = useState<{ label: string; detail: string; x: number; y: number } | null>(null)
  const mapRef = useRef<HTMLDivElement>(null)

  /** Pointer position inside the map box, so the tooltip can follow the cursor. */
  const pointerPosition = (event: React.MouseEvent): { x: number; y: number } => {
    const box = mapRef.current?.getBoundingClientRect()

    return {
      x: event.clientX - (box?.left ?? 0),
      y: event.clientY - (box?.top ?? 0),
    }
  }

  const options = useMemo(
    () =>
      (indicators.data?.data ?? []).map((indicator: IndicatorRef) => ({
        value: indicator.code,
        label: `${indicator.name} — ${unitLabel(indicator.unit) || indicator.unit}`,
      })),
    [indicators.data],
  )

  const matrix = useQuery({
    queryKey: ['matrix', 'map', code],
    queryFn: () => api.matrix({ indicators: code }),
    enabled: code !== '',
  })

  const indicator = (matrix.data?.indicators ?? [])[0]

  const byNumeric = useMemo(() => {
    const out = new Map<number, { value: number; cell: MatrixCell; name: string; slug: string }>()

    for (const row of matrix.data?.rows ?? []) {
      if (row.entity.is_aggregate === true) continue

      const numeric = row.entity.iso_alpha3 !== null ? ISO_NUMERIC[row.entity.iso_alpha3] : undefined
      const cell = row.values[code]

      if (numeric === undefined || cell === undefined || cell.value === null) continue

      out.set(numeric, { value: cell.value, cell, name: row.entity.name, slug: row.entity.slug })
    }

    return out
  }, [matrix.data, code])

  // Quintile break points over the countries that actually have a value.
  const thresholds = useMemo(() => {
    const values = [...byNumeric.values()].map((entry) => entry.value).sort((a, b) => a - b)

    if (values.length === 0) return []

    return [0.2, 0.4, 0.6, 0.8].map((q) => values[Math.floor(q * (values.length - 1))])
  }, [byNumeric])

  const fill = (numeric: number): string => {
    const entry = byNumeric.get(numeric)

    if (entry === undefined) return MISSING_FILL

    const step = thresholds.filter((threshold) => entry.value > threshold).length

    return RAMP[Math.min(step, RAMP.length - 1)]
  }

  const legend = useMemo(() => {
    if (thresholds.length === 0 || indicator === undefined) return []

    return thresholds.map((threshold, index) => ({
      fill: RAMP[index],
      label: `> ${formatValue(threshold, indicator.unit, indicator.decimals)}`,
    }))
  }, [thresholds, indicator])

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-ink-900">Map</h1>
        <p className="mt-1 text-xs text-ink-600">
          Each country's latest known value for one indicator. Grey means no value is held — it is not zero.
        </p>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <Select label="Indicator" value={code} onChange={setCode} options={options} />
        {matrix.data !== undefined && (
          <span className="pb-1.5 text-[11px] text-ink-500">
            {byNumeric.size} countries coloured
          </span>
        )}
      </div>

      {matrix.isLoading && <Spinner />}
      {matrix.error !== null && <ErrorState error={matrix.error} />}

      {indicator !== undefined && (
        <Card
          title={indicator.name}
          subtitle={`Shown in ${unitLabel(indicator.unit) || indicator.unit}. Quantile scale, so each colour band holds a similar number of countries.`}
        >
          <div ref={mapRef} className="relative rounded border border-ink-200 bg-ink-50">
            <ComposableMap projectionConfig={{ scale: 145 }} height={420} width={900} style={{ width: '100%', height: 'auto' }}>
              <Geographies geography={topo as unknown as Parameters<typeof Geographies>[0]['geography']}>
                {({ geographies }) =>
                  geographies.map((geo) => {
                    const numeric = Number(geo.id)
                    const entry = byNumeric.get(numeric)
                    const fallback = geo.properties?.name ?? 'Unknown'
                    const isHovered = hovered !== null && hovered.label === (entry?.name ?? fallback)

                    return (
                      <Geography
                        key={geo.rsmKey}
                        geography={geo}
                        fill={fill(numeric)}
                        stroke={isHovered ? '#0f172a' : '#ffffff'}
                        strokeWidth={isHovered ? 1.2 : 0.4}
                        onMouseMove={(event) =>
                          setHovered({
                            label: entry?.name ?? fallback,
                            detail:
                              entry === undefined
                                ? 'No value held for this indicator'
                                : `${formatValue(entry.value, indicator!.unit, indicator!.decimals)} ${unitLabel(indicator!.unit)} · ${entry.cell.period} · ${entry.cell.source.publisher_short ?? entry.cell.source.code}`,
                            ...pointerPosition(event),
                          })
                        }
                        onMouseLeave={() => setHovered(null)}
                      />
                    )
                  })
                }
              </Geographies>
            </ComposableMap>

            {hovered !== null && (
              <div
                data-testid="map-tooltip"
                className="pointer-events-none absolute z-10 max-w-[18rem] rounded border border-ink-300 bg-white px-2 py-1 text-[11px] shadow-lg"
                style={{ left: hovered.x + 14, top: hovered.y + 14 }}
              >
                <span className="font-medium text-ink-900">{hovered.label}</span>
                <span className="text-ink-600"> — {hovered.detail}</span>
              </div>
            )}
          </div>

          <div className="mt-3 rounded border border-ink-200 bg-white px-3 py-2 text-xs">
            {hovered === null ? (
              <span className="text-ink-500">Hover a country to see its value, reference period and source.</span>
            ) : (
              <>
                <span className="font-medium text-ink-900">{hovered.label}</span>
                <span className="text-ink-600"> — {hovered.detail}</span>
              </>
            )}
          </div>

          <div className="mt-3 flex flex-wrap items-center gap-3 text-[11px] text-ink-600">
            <span className="flex items-center gap-1">
              <span className="inline-block h-3 w-3 rounded-sm border border-ink-200" style={{ background: MISSING_FILL }} />
              no value
            </span>
            {legend.map((entry) => (
              <span key={entry.fill} className="flex items-center gap-1">
                <span className="inline-block h-3 w-3 rounded-sm border border-ink-200" style={{ background: entry.fill }} />
                {entry.label}
              </span>
            ))}
            <Badge tone="neutral">hover a country for its period and source</Badge>
          </div>
        </Card>
      )}
    </div>
  )
}
