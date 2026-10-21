import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'

import { api } from '../lib/api'
import { formatBytes, formatDateTime, formatValue } from '../lib/format'
import { Badge, ErrorState, Spinner } from './ui'

/**
 * The provenance drawer answers "where did this number come from" for one value:
 * the served figure, the exact payload it was parsed from, whether it replaced an
 * earlier value, and any source that disagrees with it.
 */
export function ProvenanceDrawer({ uuid, onClose }: { uuid: string | null; onClose: () => void }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['provenance', uuid],
    queryFn: () => api.provenance(uuid as string),
    enabled: uuid !== null,
  })

  useEffect(() => {
    if (uuid === null) return

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [uuid, onClose])

  if (uuid === null) return null

  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-ink-900/30" onClick={onClose}>
      <aside
        className="h-full w-full max-w-xl overflow-y-auto bg-white shadow-xl"
        onClick={(event) => event.stopPropagation()}
      >
        <header className="sticky top-0 flex items-center justify-between border-b border-ink-200 bg-white px-4 py-3">
          <h2 className="text-sm font-semibold text-ink-900">Provenance</h2>
          <button type="button" onClick={onClose} className="rounded p-1 text-ink-500 hover:bg-ink-100" aria-label="Close">
            <X size={16} />
          </button>
        </header>

        <div className="space-y-4 px-4 py-4">
          {isLoading && <Spinner />}
          {error !== null && error !== undefined && <ErrorState error={error} />}

          {data !== undefined && (
            <>
              <section>
                <h3 className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Value</h3>
                <p className="tnum mt-1 text-2xl font-semibold text-ink-900">
                  {formatValue(data.data.value, data.data.unit, data.data.indicator?.value_kind === 'year' ? 0 : 2)}
                  {data.data.value_text !== null && <span className="ml-2 text-sm text-ink-500">{data.data.value_text}</span>}
                </p>
                <p className="mt-1 text-xs text-ink-600">
                  {data.data.indicator?.name} · {data.data.entity?.name} · period {data.data.period}
                </p>
                <p className="mt-1 text-[11px] text-ink-500">Raw value as published: “{data.data.raw_value ?? '—'}”</p>
                {data.data.reference_note !== null && (
                  <p className="mt-1 rounded bg-ink-50 px-2 py-1 text-[11px] text-ink-600">{data.data.reference_note}</p>
                )}
              </section>

              <section>
                <h3 className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Source</h3>
                <dl className="mt-1 grid grid-cols-[8rem_1fr] gap-x-2 gap-y-1 text-xs">
                  <dt className="text-ink-500">Publisher</dt>
                  <dd className="text-ink-800">{data.data.source?.publisher ?? data.data.source?.publisher_short ?? '—'}</dd>
                  <dt className="text-ink-500">Type</dt>
                  <dd className="text-ink-800">{data.data.source?.source_type ?? '—'}</dd>
                  <dt className="text-ink-500">Credibility rank</dt>
                  <dd className="text-ink-800">{data.data.source?.credibility_rank ?? '—'} (lower outranks higher)</dd>
                  <dt className="text-ink-500">Published</dt>
                  <dd className="text-ink-800">{data.data.published_date ?? 'not stated'}</dd>
                  <dt className="text-ink-500">Retrieved</dt>
                  <dd className="text-ink-800">{formatDateTime(data.data.retrieved_at)}</dd>
                  <dt className="text-ink-500">Record reference</dt>
                  <dd className="break-all text-ink-800">{data.data.source_record_ref ?? '—'}</dd>
                  {data.data.source?.homepage_url != null && (
                    <>
                      <dt className="text-ink-500">Publisher site</dt>
                      <dd>
                        <a className="text-sky-700 underline" href={data.data.source.homepage_url} target="_blank" rel="noreferrer">
                          {data.data.source.homepage_url}
                        </a>
                      </dd>
                    </>
                  )}
                </dl>
              </section>

              <section>
                <h3 className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Raw payload snapshot</h3>
                {data.snapshot === null ? (
                  <p className="mt-1 text-xs text-ink-500">No snapshot recorded for this record.</p>
                ) : (
                  <dl className="mt-1 grid grid-cols-[8rem_1fr] gap-x-2 gap-y-1 text-xs">
                    <dt className="text-ink-500">Fetched</dt>
                    <dd className="text-ink-800">{formatDateTime(data.snapshot.fetched_at)}</dd>
                    <dt className="text-ink-500">Size</dt>
                    <dd className="text-ink-800">{formatBytes(data.snapshot.byte_size)}</dd>
                    <dt className="text-ink-500">SHA-256</dt>
                    <dd className="break-all font-mono text-[10px] text-ink-700">{data.snapshot.content_hash}</dd>
                    <dt className="text-ink-500">Stored at</dt>
                    <dd className="break-all text-ink-700">{data.snapshot.storage_path ?? '—'}</dd>
                  </dl>
                )}
              </section>

              <section>
                <h3 className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Revision history</h3>
                <div className="mt-1 space-y-1 text-xs">
                  <div className="flex items-center gap-2">
                    <Badge tone={data.revisions.this_is_current ? 'info' : 'neutral'}>
                      {data.revisions.this_is_current ? 'Current' : 'Superseded'}
                    </Badge>
                    <span className="text-ink-600">revision {data.data.revision}</span>
                  </div>
                  {data.revisions.replaced !== null && (
                    <p className="text-ink-600">
                      Replaced an earlier value of{' '}
                      <span className="tnum font-medium text-ink-800">
                        {formatValue(data.revisions.replaced.value, data.data.unit, 2)}
                      </span>{' '}
                      (revision {data.revisions.replaced.revision}, {formatDateTime(data.revisions.replaced.retrieved_at)}). That record is still stored.
                    </p>
                  )}
                  {data.revisions.superseded_by !== null && (
                    <p className="text-ink-600">
                      A newer value of{' '}
                      <span className="tnum font-medium text-ink-800">
                        {formatValue(data.revisions.superseded_by.value, data.data.unit, 2)}
                      </span>{' '}
                      has since been recorded (revision {data.revisions.superseded_by.revision}).
                    </p>
                  )}
                </div>
              </section>

              {data.conflicts.length > 0 && (
                <section>
                  <h3 className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Sources that disagree</h3>
                  <ul className="mt-1 space-y-2">
                    {data.conflicts.map((conflict) => (
                      <li key={conflict.id} className="rounded border border-ink-200 px-2 py-2 text-xs">
                        <div className="flex items-center gap-2">
                          <Badge tone={conflict.significance === 'major' ? 'danger' : conflict.significance === 'notable' ? 'warn' : 'neutral'}>
                            {conflict.significance}
                          </Badge>
                          <span className="text-ink-600">
                            {conflict.pct_delta !== null ? `${conflict.pct_delta.toFixed(2)}% apart` : 'differs'}
                          </span>
                        </div>
                        <p className="mt-1 text-ink-700">
                          <span className="font-medium">{conflict.primary.source?.publisher_short ?? conflict.primary.source?.code}</span>:{' '}
                          <span className="tnum">{formatValue(conflict.primary.value, data.data.unit, 2)}</span>
                          {'  ·  '}
                          <span className="font-medium">{conflict.conflicting.source?.publisher_short ?? conflict.conflicting.source?.code}</span>:{' '}
                          <span className="tnum">{formatValue(conflict.conflicting.value, data.data.unit, 2)}</span>
                        </p>
                      </li>
                    ))}
                  </ul>
                </section>
              )}
            </>
          )}
        </div>
      </aside>
    </div>
  )
}
