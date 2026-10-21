import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { Badge, Card, ErrorState, Spinner } from '../components/ui'

const RANK_LABELS: Record<number, string> = {
  1: 'National statistical authority',
  2: 'United Nations',
  3: 'Other international official',
  4: 'Wikipedia',
  5: 'Manual / unverified',
  6: 'Other',
}

export function SourcesPage() {
  const [openSource, setOpenSource] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const queryClient = useQueryClient()

  const sources = useQuery({ queryKey: ['sources'], queryFn: api.sources })
  const runs = useQuery({
    queryKey: ['source-runs', openSource],
    queryFn: () => api.sourceRuns(openSource as string),
    enabled: openSource !== null,
  })

  /**
   * Pulls one dataset now, on demand. Ingestions are idempotent — a payload that has not
   * changed writes nothing — so pressing this is always safe to repeat.
   */
  const refresh = useMutation({
    mutationFn: (datasetCode: string) => api.admin.ingest(datasetCode, false),
    onSuccess: (_data, datasetCode) => {
      setMessage(`${datasetCode}: fetch finished`)
      queryClient.invalidateQueries({ queryKey: ['sources'] })
      queryClient.invalidateQueries({ queryKey: ['source-runs'] })
      queryClient.invalidateQueries({ queryKey: ['matrix'] })
      queryClient.invalidateQueries({ queryKey: ['coverage'] })
    },
    onError: (error: Error) => setMessage(error.message),
  })

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-lg font-semibold text-ink-900">Sources</h1>
        <p className="mt-1 text-xs text-ink-600">
          Every source carries a credibility rank. When two sources report the same period, the lower rank is served and the
          other value is retained and flagged — never discarded.
        </p>
      </div>

      {message !== null && (
        <p className="rounded border border-ink-200 bg-ink-50 px-3 py-2 text-[11px] text-ink-700">{message}</p>
      )}

      {sources.isLoading && <Spinner />}
      {sources.error !== null && <ErrorState error={sources.error} />}

      <div className="space-y-3">
        {(sources.data?.data ?? []).map((source) => (
          <Card
            key={source.code}
            title={
              <span className="flex items-center gap-2">
                {source.publisher_short ?? source.code}
                <Badge tone={source.credibility_rank <= 2 ? 'info' : source.credibility_rank >= 5 ? 'warn' : 'neutral'}>
                  rank {source.credibility_rank} · {RANK_LABELS[source.credibility_rank] ?? 'other'}
                </Badge>
              </span>
            }
            subtitle={source.publisher}
            actions={
              <button
                type="button"
                className="text-[11px] text-sky-700 underline"
                onClick={() => setOpenSource(openSource === source.code ? null : source.code)}
              >
                {openSource === source.code ? 'Hide runs' : 'Fetch history'}
              </button>
            }
          >
            <p className="text-xs text-ink-600">{source.terms_note}</p>

            <div className="mt-3 space-y-2">
              {(source.datasets ?? []).map((dataset) => (
                <div key={dataset.code} className="rounded border border-ink-200 px-3 py-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-xs font-medium text-ink-800">{dataset.name}</span>
                    <span className="flex items-center gap-2">
                      <button
                        type="button"
                        title="Fetch this dataset now. Re-running an unchanged source writes nothing."
                        disabled={refresh.isPending}
                        onClick={() => refresh.mutate(dataset.code)}
                        className="rounded border border-ink-300 bg-white px-2 py-0.5 text-[11px] font-medium text-ink-800 hover:bg-ink-50 disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        {refresh.isPending && refresh.variables === dataset.code ? 'Fetching…' : 'Get new data'}
                      </button>
                      <Badge tone={dataset.enabled ? 'info' : 'neutral'}>{dataset.enabled ? 'enabled' : 'disabled'}</Badge>
                      <Badge tone="neutral">{dataset.access_method}</Badge>
                      <Badge tone="neutral">{dataset.update_frequency}</Badge>
                    </span>
                  </div>
                  <p className="mt-1 text-[11px] text-ink-500">{dataset.description}</p>
                  <p className="mt-1 text-[11px] text-ink-500">
                    Last checked: {formatDateTime(dataset.last_checked_at)} · Next due: {formatDateTime(dataset.next_run_at)}
                    {dataset.endpoint_url !== null && (
                      <>
                        {' · '}
                        <a className="text-sky-700 underline" href={dataset.endpoint_url} target="_blank" rel="noreferrer">
                          endpoint
                        </a>
                      </>
                    )}
                    {dataset.documentation_url !== null && (
                      <>
                        {' · '}
                        <a className="text-sky-700 underline" href={dataset.documentation_url} target="_blank" rel="noreferrer">
                          docs
                        </a>
                      </>
                    )}
                  </p>
                </div>
              ))}
            </div>

            {openSource === source.code && (
              <div className="mt-3 border-t border-ink-100 pt-3">
                {runs.isLoading && <Spinner label="Loading fetch history…" />}
                {runs.data !== undefined && runs.data.data.length === 0 && (
                  <p className="text-xs text-ink-500">No fetch attempts recorded yet.</p>
                )}
                {runs.data !== undefined && runs.data.data.length > 0 && (
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="border-b border-ink-200 text-left text-ink-500">
                        <th className="py-1.5 pr-3 font-medium">Started</th>
                        <th className="py-1.5 pr-3 font-medium">Status</th>
                        <th className="py-1.5 pr-3 font-medium">New</th>
                        <th className="py-1.5 pr-3 font-medium">Revised</th>
                        <th className="py-1.5 pr-3 font-medium">Unchanged</th>
                        <th className="py-1.5 pr-3 font-medium">Rejected</th>
                        <th className="py-1.5 pr-3 font-medium">Duration</th>
                      </tr>
                    </thead>
                    <tbody>
                      {runs.data.data.map((run) => (
                        <tr key={run.id} className="border-b border-ink-100 last:border-0">
                          <td className="py-1.5 pr-3 text-ink-600">{formatDateTime(run.started_at)}</td>
                          <td className="py-1.5 pr-3">
                            <Badge tone={run.status === 'success' ? 'info' : run.status === 'failed' ? 'danger' : 'warn'}>
                              {run.status}
                            </Badge>
                            {run.error_message !== null && (
                              <span className="ml-1 text-[10px] text-rose-700" title={run.error_message}>
                                ⓘ
                              </span>
                            )}
                          </td>
                          <td className="tnum py-1.5 pr-3 text-ink-600">{run.records_new}</td>
                          <td className="tnum py-1.5 pr-3 text-ink-600">{run.records_revised}</td>
                          <td className="tnum py-1.5 pr-3 text-ink-600">{run.records_unchanged}</td>
                          <td className="tnum py-1.5 pr-3 text-ink-600">{run.records_rejected}</td>
                          <td className="tnum py-1.5 pr-3 text-ink-600">
                            {run.duration_ms !== null ? `${(run.duration_ms / 1000).toFixed(1)}s` : '—'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </div>
            )}
          </Card>
        ))}
      </div>

      <Card title="Cadence">
        <p className="text-xs text-ink-600">
          The scheduler checks hourly for datasets whose <span className="font-mono text-[11px]">next_run_at</span> has passed,
          so each source runs on its own cadence without separate cron entries. Payloads are content-hashed: an unchanged source
          costs a request and writes nothing, which is what makes frequent checking safe and cheap.
        </p>
      </Card>
    </div>
  )
}
