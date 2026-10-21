import { useQuery } from '@tanstack/react-query'

import { api } from '../lib/api'
import type { EntityMatrixRow, EntityObservationRow } from '../lib/types'
import { formatNumber } from '../lib/format'
import { Badge, Card, EmptyState, ErrorState, Spinner } from './ui'

export const VITAL_CODES = {
  births: 'birth.count.annual',
  deaths: 'death.count.annual',
  natural: 'pop.natural_change.absolute',
  birthsChange: 'birth.change.pct',
  deathsChange: 'death.change.pct',
  naturalChange: 'pop.natural_change.change_pct',
  naturalShare: 'pop.change.natural_share',
}

function value(row: EntityMatrixRow | undefined, code: string): EntityObservationRow | undefined {
  const cell = row?.values[code]
  return cell === undefined || cell.value === null ? undefined : cell
}

function formatCell(cell: EntityObservationRow | undefined): string {
  if (cell === undefined) return '—'
  return formatNumber(cell.value, 0)
}

/**
 * The most recent year against the one before it, for births, deaths and natural change.
 *
 * This is the comparison the "Current vital statistics" table on each country's
 * Wikipedia article makes, and it is the one that answers "is this country's birth count
 * actually falling?" — a question no single year's figure can answer.
 */
export function CurrentVitalStatistics({ entityKey }: { entityKey: string }) {
  const query = useQuery({
    queryKey: ['vital-change', entityKey],
    queryFn: () =>
      api.entityMatrix(entityKey, {
        indicator: [VITAL_CODES.births, VITAL_CODES.deaths, VITAL_CODES.natural].join(','),
      }),
  })

  const rows = (query.data?.rows ?? [])
    .slice()
    .sort((a, b) => a.year - b.year)
    .filter((row) => value(row, VITAL_CODES.births) !== undefined || value(row, VITAL_CODES.deaths) !== undefined)

  const latest = rows.at(-1)
  const previous = rows.at(-2)

  const measures = [
    { label: 'Live births', code: VITAL_CODES.births, change: VITAL_CODES.birthsChange },
    { label: 'Deaths', code: VITAL_CODES.deaths, change: VITAL_CODES.deathsChange },
    { label: 'Natural change', code: VITAL_CODES.natural, change: VITAL_CODES.naturalChange },
  ]

  return (
    <Card
      title="Current vital statistics"
      subtitle="The latest year against the year before, from the birth and death counts themselves."
    >
      {query.isLoading && <Spinner />}
      {query.error !== null && <ErrorState error={query.error} />}
      {query.data !== undefined && latest === undefined && (
        <EmptyState>No annual births or deaths are held for this country yet.</EmptyState>
      )}

      {latest !== undefined && (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-ink-200 text-right text-ink-500">
                <th className="py-2 pr-3 text-left font-medium">Period</th>
                {measures.map((measure) => (
                  <th key={measure.label} className="py-2 pr-3 font-medium">
                    {measure.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              <tr className="border-b border-ink-100">
                <td className="py-1.5 pr-3 text-left font-medium text-ink-800">{latest.period}</td>
                {measures.map((measure) => (
                  <td key={measure.label} className="tnum py-1.5 pr-3 text-right text-ink-800">
                    {formatCell(value(latest, measure.code))}
                  </td>
                ))}
              </tr>

              {previous !== undefined && (
                <>
                  <tr className="border-b border-ink-100 text-ink-600">
                    <td className="py-1.5 pr-3 text-left">{previous.period}</td>
                    {measures.map((measure) => (
                      <td key={measure.label} className="tnum py-1.5 pr-3 text-right">
                        {formatCell(value(previous, measure.code))}
                      </td>
                    ))}
                  </tr>

                  <tr className="border-b border-ink-100 bg-ink-50/60">
                    <td className="py-1.5 pr-3 text-left font-medium text-ink-700">Change</td>
                    {measures.map((measure) => {
                      const now = value(latest, measure.code)
                      const before = value(previous, measure.code)
                      const pct = value(latest, measure.change)

                      const delta = now !== undefined && before !== undefined ? now.value! - before.value! : null

                      return (
                        <td key={measure.label} className="tnum py-1.5 pr-3 text-right font-medium">
                          {delta === null ? (
                            <span className="text-ink-400">—</span>
                          ) : (
                            <span className={delta < 0 ? 'text-rose-700' : delta > 0 ? 'text-emerald-700' : 'text-ink-700'}>
                              {delta > 0 ? '+' : ''}
                              {formatNumber(delta, 0)}
                            </span>
                          )}
                          {pct !== undefined && (
                            <span className="ml-1 text-ink-500">({formatNumber(pct.value, 1)}%)</span>
                          )}
                        </td>
                      )
                    })}
                  </tr>
                </>
              )}
            </tbody>
          </table>

          <p className="mt-2 text-[11px] text-ink-500">
            The change row is calculated from the two years above it, not read from a source.
            {latest.values[VITAL_CODES.natural]?.is_derived === true && ' Natural change is births less deaths.'}
          </p>
        </div>
      )}

      {latest !== undefined && previous === undefined && (
        <p className="mt-2 text-[11px] text-ink-500">
          Only one year with births or deaths is held, so there is nothing to compare against yet. <Badge tone="neutral">no comparison</Badge>
        </p>
      )}
    </Card>
  )
}
