import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'

import { api } from '../lib/api'
import type { IndicatorRef } from '../lib/types'
import { unitLabel } from '../lib/format'
import { MODULES, type ModuleCode } from '../lib/modules'
import { Badge, Card, ErrorState, Spinner } from '../components/ui'

export function IndicatorsPage() {
  const [module, setModule] = useState<ModuleCode>('demographics')

  const { data, isLoading, error } = useQuery({
    queryKey: ['indicators', module],
    queryFn: () => api.indicators({ module }),
  })

  const grouped = useMemo(() => {
    const groups = new Map<string, { name: string; indicators: IndicatorRef[] }>()

    ;(data?.data ?? []).forEach((indicator) => {
      const key = indicator.category?.code ?? 'other'
      const existing = groups.get(key)

      if (existing === undefined) {
        groups.set(key, { name: indicator.category?.name ?? 'Other', indicators: [indicator] })
      } else {
        existing.indicators.push(indicator)
      }
    })

    return Array.from(groups.entries())
  }, [data])

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-ink-900">Indicators</h1>
        <p className="mt-1 text-xs text-ink-600">
          Every indicator records its unit and definition, so a value can never be lifted out of the context that makes it
          comparable.
        </p>
      </div>

      {isLoading && <Spinner />}
      {error !== null && <ErrorState error={error} />}

      <label className="flex w-fit flex-col gap-1">
        <span className="text-[11px] font-medium text-ink-500">Module</span>
        <select
          value={module}
          onChange={(event) => setModule(event.target.value as ModuleCode)}
          className="rounded-md border border-ink-300 bg-white px-2 py-1.5 text-xs"
        >
          {MODULES.map((entry) => (
            <option key={entry.code} value={entry.code}>
              {entry.name}
            </option>
          ))}
        </select>
      </label>

      <div className="space-y-4">
        {grouped.map(([code, group]) => (
          <Card key={code} title={group.name}>
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-ink-200 text-left text-ink-500">
                  <th className="py-2 pr-3 font-medium">Indicator</th>
                  <th className="py-2 pr-3 font-medium">Code</th>
                  <th className="py-2 pr-3 font-medium">Unit</th>
                  <th className="py-2 pr-3 font-medium">Frequency</th>
                  <th className="py-2 pr-3 font-medium">Module</th>
                  <th className="py-2 pr-3 font-medium">Notes</th>
                </tr>
              </thead>
              <tbody>
                {group.indicators.map((indicator) => (
                  <tr key={indicator.code} className="border-b border-ink-100 last:border-0 hover:bg-ink-50">
                    <td className="py-2 pr-3">
                      <Link
                        to={`/indicators/${indicator.code}`}
                        className="font-medium text-ink-900 underline decoration-ink-300 hover:decoration-ink-600"
                      >
                        {indicator.name}
                      </Link>
                      <p className="mt-0.5 max-w-xl text-[11px] text-ink-500">{indicator.description}</p>
                    </td>
                    <td className="py-2 pr-3 font-mono text-[11px] text-ink-600">{indicator.code}</td>
                    <td className="py-2 pr-3 text-ink-600">{unitLabel(indicator.unit) || indicator.unit}</td>
                    <td className="py-2 pr-3 text-ink-600">{indicator.built_in_frequency}</td>
                    <td className="py-2 pr-3">
                      <Badge tone={indicator.module === 'demographics' ? 'info' : 'warn'}>{indicator.module}</Badge>
                    </td>
                    <td className="py-2 pr-3">
                      {indicator.is_sensitive && <Badge tone="danger" title="Sensitive or unverified indicator">sensitive</Badge>}
                      {indicator.is_stock && <Badge tone="neutral">stock</Badge>}
                      {!indicator.supports_disaggregation && (
                        <Badge tone="neutral" title="Published annually only; quarterly or monthly figures are never derived from it.">
                          annual only
                        </Badge>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
        ))}
      </div>
    </div>
  )
}
