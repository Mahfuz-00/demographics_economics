import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { Link } from 'react-router-dom'

import { api } from '../lib/api'
import type { EntityRef } from '../lib/types'
import { Badge, Card, EmptyState, ErrorState, Select, Spinner } from '../components/ui'

const TYPE_OPTIONS = [
  { value: '', label: 'All entities' },
  { value: 'countries', label: 'Countries & territories only' },
  { value: 'country', label: 'Sovereign countries' },
  { value: 'territory', label: 'Dependent territories' },
  { value: 'aggregate', label: 'Aggregates (World, continents)' },
]

export function CountriesPage() {
  const [search, setSearch] = useState('')
  const [type, setType] = useState('countries')
  const [continent, setContinent] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['entities'],
    queryFn: () => api.entities(),
  })

  const continents = useMemo(
    () =>
      Array.from(new Set((data?.data ?? []).map((entity) => entity.continent).filter((value): value is string => value !== null)))
        .sort(),
    [data],
  )

  const visible = useMemo(() => {
    const term = search.trim().toLowerCase()

    return (data?.data ?? []).filter((entity: EntityRef) => {
      if (type === 'countries' && !['country', 'dependency', 'territory'].includes(entity.entity_type)) return false
      if (type === 'country' && entity.entity_type !== 'country') return false
      if (type === 'territory' && !['dependency', 'territory'].includes(entity.entity_type)) return false
      if (type === 'aggregate' && !['aggregate', 'region', 'group'].includes(entity.entity_type)) return false
      if (continent !== '' && entity.continent !== continent) return false
      if (term !== '' && !entity.name.toLowerCase().includes(term) && !(entity.iso_alpha3 ?? '').toLowerCase().includes(term)) {
        return false
      }

      return true
    })
  }, [data, search, type, continent])

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-ink-900">Entities</h1>
          <p className="mt-1 text-xs text-ink-600">
            {data !== undefined ? `${visible.length} of ${data.meta.count} entities` : 'Loading…'} · ISO 3166-1 countries and
            territories, plus the aggregate rows carried by the source data.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <label className="flex flex-col gap-1">
            <span className="text-[11px] font-medium text-ink-500">Search</span>
            <span className="flex items-center gap-1 rounded-md border border-ink-300 bg-white px-2 py-1.5">
              <Search size={13} className="text-ink-400" />
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Name or ISO code"
                className="w-40 border-0 p-0 text-xs text-ink-800 outline-none"
              />
            </span>
          </label>
          <Select
            label="Type"
            value={type}
            onChange={setType}
            options={TYPE_OPTIONS}
          />
          <Select
            label="Continent"
            value={continent}
            onChange={setContinent}
            options={[{ value: '', label: 'All continents' }, ...continents.map((value) => ({ value, label: value }))]}
          />
        </div>
      </div>

      <Card>
        {isLoading && <Spinner />}
        {error !== null && <ErrorState error={error} />}
        {data !== undefined && visible.length === 0 && <EmptyState>No entities match those filters.</EmptyState>}
        {visible.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-ink-200 text-left text-ink-500">
                  <th className="py-2 pr-3 font-medium">Name</th>
                  <th className="py-2 pr-3 font-medium">ISO</th>
                  <th className="py-2 pr-3 font-medium">Type</th>
                  <th className="py-2 pr-3 font-medium">Continent</th>
                  <th className="py-2 pr-3 font-medium">Subregion</th>
                  <th className="py-2 pr-3 font-medium">Sovereign</th>
                </tr>
              </thead>
              <tbody>
                {visible.map((entity) => (
                  <tr key={entity.uuid} className="border-b border-ink-100 last:border-0 hover:bg-ink-50">
                    <td className="py-1.5 pr-3">
                      <Link
                        to={`/countries/${entity.iso_alpha3 ?? entity.slug}`}
                        className="font-medium text-ink-900 underline decoration-ink-300 hover:decoration-ink-600"
                      >
                        {entity.name}
                      </Link>
                    </td>
                    <td className="py-1.5 pr-3 font-mono text-[11px] text-ink-600">
                      {entity.iso_alpha2 ?? '—'} / {entity.iso_alpha3 ?? '—'}
                    </td>
                    <td className="py-1.5 pr-3">
                      <Badge tone={entity.is_aggregate ? 'warn' : 'neutral'}>{entity.entity_type}</Badge>
                    </td>
                    <td className="py-1.5 pr-3 text-ink-600">{entity.continent ?? '—'}</td>
                    <td className="py-1.5 pr-3 text-ink-600">{entity.subregion ?? '—'}</td>
                    <td className="py-1.5 pr-3 text-ink-600">{entity.sovereignty?.name ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
