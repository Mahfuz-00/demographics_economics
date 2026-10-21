import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api, getToken } from '../lib/api'
import { formatDateTime, formatNumber } from '../lib/format'
import { Badge, Button, Card, EmptyState, ErrorState, Spinner } from '../components/ui'

const REASON_LABELS: Record<string, string> = {
  unknown_entity: 'Name could not be matched to any entity',
  missing_reference_year: 'Value present but the source states no reference period',
  unparseable_value: 'Cell could not be read as a single value',
  broken_reference: 'Spreadsheet error token in the cell',
  unsupported_frequency: 'Sub-annual observation refused for an annual-only indicator',
  missing_source: 'No source attached to the value',
  unknown_indicator: 'Mapping points at an indicator that does not exist',
}

export function QualityPage() {
  const queryClient = useQueryClient()
  const authenticated = getToken() !== null
  const [tab, setTab] = useState<'conflicts' | 'quarantine' | 'candidates'>('conflicts')

  const conflicts = useQuery({ queryKey: ['conflicts'], queryFn: () => api.conflicts({ limit: 200 }) })
  const quarantine = useQuery({
    queryKey: ['quarantine'],
    queryFn: () => api.admin.quarantine({ limit: 200 }),
    retry: false,
  })
  const candidates = useQuery({
    queryKey: ['candidates'],
    queryFn: () => api.admin.candidates({ status: 'pending', limit: 200 }),
    enabled: authenticated,
    retry: false,
  })

  const resolveConflict = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) =>
      api.admin.resolveConflict(id, status, 'Reviewed in the data-quality view.'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['conflicts'] }),
  })

  const resolveQuarantine = useMutation({
    mutationFn: ({ id, resolved }: { id: number; resolved: boolean }) => api.admin.resolveQuarantine(id, resolved),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['quarantine'] }),
  })

  const reviewCandidate = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => api.admin.reviewCandidate(id, status),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['candidates'] }),
  })

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-lg font-semibold text-ink-900">Data quality</h1>
        <p className="mt-1 text-xs text-ink-600">
          Two queues: values the importer refused to guess at, and series where two sources disagree. Neither queue alters the
          stored data — resolution is recorded, values are kept. Sources found by web search are proposals only.
        </p>
        {!authenticated && (
          <p className="mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
            Reading only. Sign in on the Admin page to record resolutions.
          </p>
        )}
      </div>

      <div className="flex gap-2">
        <Button variant={tab === 'conflicts' ? 'primary' : 'default'} onClick={() => setTab('conflicts')}>
          Source disagreements {conflicts.data !== undefined ? `(${conflicts.data.meta.count})` : ''}
        </Button>
        <Button variant={tab === 'quarantine' ? 'primary' : 'default'} onClick={() => setTab('quarantine')}>
          Quarantine queue{' '}
          {quarantine.data !== undefined ? `(${quarantine.data.meta.unresolved_total})` : ''}
        </Button>
        {authenticated && (
          <Button variant={tab === 'candidates' ? 'primary' : 'default'} onClick={() => setTab('candidates')}>
            Source candidates {candidates.data !== undefined ? `(${candidates.data.meta.pending_total})` : ''}
          </Button>
        )}
      </div>

      {tab === 'candidates' && (
        <Card
          title="Source candidates"
          subtitle="Found by web search when a country's article had no usable vital-statistics table. Nothing here is data until you accept it."
        >
          {candidates.isLoading && <Spinner />}
          {candidates.error !== null && <ErrorState error={candidates.error} />}
          {candidates.data !== undefined && candidates.data.data.length === 0 && (
            <EmptyState>No candidates waiting for review.</EmptyState>
          )}
          {candidates.data !== undefined && candidates.data.data.length > 0 && (
            <ul className="space-y-2">
              {candidates.data.data.map((candidate) => (
                <li key={candidate.id} className="rounded border border-ink-200 px-3 py-2 text-xs">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="font-medium text-ink-900">{candidate.title ?? candidate.url}</p>
                      <p className="text-[11px] text-ink-500">
                        {candidate.entity?.name ?? '—'} · {candidate.host ?? '—'}
                      </p>
                      {candidate.snippet !== null && candidate.snippet !== undefined && (
                        <p className="mt-1 text-[11px] text-ink-600">{candidate.snippet}</p>
                      )}
                      <a
                        className="mt-1 inline-block break-all text-[11px] text-sky-700 underline"
                        href={candidate.url}
                        target="_blank"
                        rel="noreferrer"
                      >
                        {candidate.url}
                      </a>
                    </div>
                    <div className="flex shrink-0 gap-1">
                      <Button
                        variant="default"
                        onClick={() => reviewCandidate.mutate({ id: candidate.id, status: 'accepted' })}
                        disabled={reviewCandidate.isPending}
                      >
                        accept
                      </Button>
                      <Button
                        variant="ghost"
                        onClick={() => reviewCandidate.mutate({ id: candidate.id, status: 'rejected' })}
                        disabled={reviewCandidate.isPending}
                      >
                        reject
                      </Button>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {tab === 'conflicts' && (
        <Card title="Source disagreements" subtitle="Higher-ranked source is served; the other value stays visible.">
          {conflicts.isLoading && <Spinner />}
          {conflicts.error !== null && <ErrorState error={conflicts.error} />}
          {conflicts.data !== undefined && conflicts.data.data.length === 0 && (
            <EmptyState>No disagreements recorded.</EmptyState>
          )}
          {conflicts.data !== undefined && conflicts.data.data.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-ink-200 text-left text-ink-500">
                    <th className="py-2 pr-3 font-medium">Entity</th>
                    <th className="py-2 pr-3 font-medium">Indicator</th>
                    <th className="py-2 pr-3 font-medium">Period</th>
                    <th className="py-2 pr-3 font-medium">Served</th>
                    <th className="py-2 pr-3 font-medium">Other source</th>
                    <th className="py-2 pr-3 font-medium">Difference</th>
                    <th className="py-2 pr-3 font-medium">Status</th>
                    {authenticated && <th className="py-2 pr-3 font-medium"></th>}
                  </tr>
                </thead>
                <tbody>
                  {conflicts.data.data.map((conflict) => (
                    <tr key={conflict.id} className="border-b border-ink-100 last:border-0">
                      <td className="py-1.5 pr-3 text-ink-800">{conflict.entity?.name}</td>
                      <td className="py-1.5 pr-3 text-ink-600">{conflict.indicator?.name}</td>
                      <td className="tnum py-1.5 pr-3 text-ink-600">{conflict.period}</td>
                      <td className="tnum py-1.5 pr-3 text-ink-800">
                        {formatNumber(conflict.primary.value, 2)}
                        <span className="ml-1 text-[10px] text-ink-500">({conflict.primary.source?.publisher_short})</span>
                      </td>
                      <td className="tnum py-1.5 pr-3 text-ink-600">
                        {formatNumber(conflict.conflicting.value, 2)}
                        <span className="ml-1 text-[10px] text-ink-500">({conflict.conflicting.source?.publisher_short})</span>
                      </td>
                      <td className="tnum py-1.5 pr-3">
                        <Badge tone={conflict.significance === 'major' ? 'danger' : conflict.significance === 'notable' ? 'warn' : 'neutral'}>
                          {conflict.pct_delta !== null ? `${conflict.pct_delta.toFixed(1)}%` : conflict.significance}
                        </Badge>
                      </td>
                      <td className="py-1.5 pr-3 text-ink-600">
                        {conflict.resolution_status === 'open' ? (
                          <span className="text-amber-700">open</span>
                        ) : (
                          <span title={conflict.resolution_note ?? undefined}>{conflict.resolution_status.replace(/_/g, ' ')}</span>
                        )}
                      </td>
                      {authenticated && (
                        <td className="py-1.5 pr-3">
                          {conflict.resolution_status === 'open' && (
                            <div className="flex gap-1">
                              <Button
                                variant="ghost"
                                onClick={() => resolveConflict.mutate({ id: conflict.id, status: 'reviewed_kept_primary' })}
                                disabled={resolveConflict.isPending}
                              >
                                keep served
                              </Button>
                              <Button
                                variant="ghost"
                                onClick={() => resolveConflict.mutate({ id: conflict.id, status: 'dismissed' })}
                                disabled={resolveConflict.isPending}
                              >
                                dismiss
                              </Button>
                            </div>
                          )}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {tab === 'quarantine' && (
        <Card
          title="Quarantine queue"
          subtitle="Rows the importer could not read confidently. Nothing here was stored as a value."
        >
          {quarantine.isLoading && <Spinner />}
          {quarantine.error !== null && <ErrorState error={quarantine.error} />}
          {quarantine.data !== undefined && (
            <>
              <div className="mb-3 flex flex-wrap gap-2">
                {Object.entries(quarantine.data.meta.by_reason).map(([reason, count]) => (
                  <Badge key={reason} tone="warn" title={REASON_LABELS[reason] ?? reason}>
                    {reason}: {count}
                  </Badge>
                ))}
              </div>
              {quarantine.data.data.length === 0 && <EmptyState>Nothing is waiting for review.</EmptyState>}
              <div className="max-h-[36rem] overflow-y-auto">
                <table className="w-full text-xs">
                  <thead className="sticky top-0 bg-white">
                    <tr className="border-b border-ink-200 text-left text-ink-500">
                      <th className="py-2 pr-3 font-medium">Sub-table</th>
                      <th className="py-2 pr-3 font-medium">Row</th>
                      <th className="py-2 pr-3 font-medium">Reason</th>
                      <th className="py-2 pr-3 font-medium">Detail</th>
                      {authenticated && <th className="py-2 pr-3 font-medium"></th>}
                    </tr>
                  </thead>
                  <tbody>
                    {quarantine.data.data.map((row) => (
                      <tr key={row.id} className="border-b border-ink-100 last:border-0">
                        <td className="py-1.5 pr-3 text-ink-700">{row.sub_table ?? '—'}</td>
                        <td className="tnum py-1.5 pr-3 text-ink-600">{row.row_number ?? '—'}</td>
                        <td className="py-1.5 pr-3">
                          <Badge tone="warn" title={REASON_LABELS[row.reason_code] ?? row.reason_code}>
                            {row.reason_code}
                          </Badge>
                        </td>
                        <td className="py-1.5 pr-3 text-ink-600">{row.reason_detail}</td>
                        {authenticated && (
                          <td className="py-1.5 pr-3">
                            {!row.resolved && (
                              <Button
                                variant="ghost"
                                onClick={() => resolveQuarantine.mutate({ id: row.id, resolved: true })}
                                disabled={resolveQuarantine.isPending}
                              >
                                mark reviewed
                              </Button>
                            )}
                            {row.resolved && <span className="text-[10px] text-ink-400">reviewed {formatDateTime(row.resolved_at)}</span>}
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </Card>
      )}
    </div>
  )
}
