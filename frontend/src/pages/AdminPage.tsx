import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api, getToken, setToken } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { Badge, Button, Card, EmptyState, ErrorState, Spinner } from '../components/ui'

export function AdminPage() {
  const queryClient = useQueryClient()
  const [email, setEmail] = useState('admin@example.com')
  const [password, setPassword] = useState('password')
  const [authenticated, setAuthenticated] = useState(getToken() !== null)
  const [message, setMessage] = useState<string | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const sources = useQuery({ queryKey: ['sources'], queryFn: api.sources })

  const batches = useQuery({
    queryKey: ['batches'],
    queryFn: api.admin.batches,
    enabled: authenticated,
    retry: false,
  })

  const login = useMutation({
    mutationFn: () => api.login(email, password),
    onSuccess: (data) => {
      setToken(data.token)
      setAuthenticated(true)
      setMessage(`Signed in as ${data.user.email}.`)
    },
    onError: (error: Error) => setMessage(error.message),
  })

  const ingest = useMutation({
    mutationFn: (code: string) => api.admin.ingest(code, true),
    onSuccess: (_data, code) => {
      setMessage(`Queued a fetch for ${code}. Run a queue worker to process it: php artisan queue:work`)
      queryClient.invalidateQueries({ queryKey: ['batches'] })
    },
    onError: (error: Error) => setMessage(error.message),
  })

  const upload = useMutation({
    mutationFn: (file: File) => api.admin.importCsv(file),
    onSuccess: (data) => {
      setMessage(
        `Imported: ${data.data.observations_created} new, ${data.data.observations_unchanged} unchanged, ` +
          `${data.data.observations_superseded} superseded, ${data.data.quarantined} quarantine groups.`,
      )
      queryClient.invalidateQueries({ queryKey: ['batches'] })
      queryClient.invalidateQueries({ queryKey: ['quarantine'] })
    },
    onError: (error: Error) => setMessage(error.message),
  })

  const datasets = (sources.data?.data ?? []).flatMap((source) => source.datasets ?? [])

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-ink-900">Administration</h1>
          <p className="mt-1 text-xs text-ink-600">
            Trigger fetches, upload a replacement spreadsheet, and review what the last runs did.
          </p>
        </div>
        {authenticated && (
          <Button
            variant="ghost"
            onClick={() => {
              setToken(null)
              setAuthenticated(false)
              setMessage(null)
            }}
          >
            Sign out
          </Button>
        )}
      </div>

      {message !== null && (
        <div className="rounded border border-ink-200 bg-white px-3 py-2 text-xs text-ink-700">{message}</div>
      )}

      {!authenticated && (
        <Card title="Sign in" subtitle="Admin endpoints are protected by Sanctum tokens.">
          <div className="flex flex-wrap items-end gap-3">
            <label className="flex flex-col gap-1">
              <span className="text-[11px] font-medium text-ink-500">Email</span>
              <input
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className="rounded-md border border-ink-300 px-2 py-1.5 text-xs"
              />
            </label>
            <label className="flex flex-col gap-1">
              <span className="text-[11px] font-medium text-ink-500">Password</span>
              <input
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                className="rounded-md border border-ink-300 px-2 py-1.5 text-xs"
              />
            </label>
            <Button variant="primary" onClick={() => login.mutate()} disabled={login.isPending}>
              {login.isPending ? 'Signing in…' : 'Sign in'}
            </Button>
          </div>
          <p className="mt-2 text-[11px] text-ink-500">
            Seeded from <span className="font-mono">ADMIN_EMAIL</span> / <span className="font-mono">ADMIN_PASSWORD</span> when
            the database was seeded.
          </p>
        </Card>
      )}

      {authenticated && (
        <>
          <Card title="Source fetches" subtitle="Queued jobs run when a worker is processing the queue.">
            {sources.isLoading && <Spinner />}
            {sources.error !== null && <ErrorState error={sources.error} />}
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-ink-200 text-left text-ink-500">
                  <th className="py-2 pr-3 font-medium">Dataset</th>
                  <th className="py-2 pr-3 font-medium">Adapter</th>
                  <th className="py-2 pr-3 font-medium">Cadence</th>
                  <th className="py-2 pr-3 font-medium">Last checked</th>
                  <th className="py-2 pr-3 font-medium"></th>
                </tr>
              </thead>
              <tbody>
                {datasets.map((dataset) => (
                  <tr key={dataset.code} className="border-b border-ink-100 last:border-0">
                    <td className="py-1.5 pr-3 text-ink-800">{dataset.name}</td>
                    <td className="py-1.5 pr-3 font-mono text-[11px] text-ink-600">{dataset.adapter_key ?? '—'}</td>
                    <td className="py-1.5 pr-3 text-ink-600">{dataset.update_frequency}</td>
                    <td className="py-1.5 pr-3 text-ink-600">{formatDateTime(dataset.last_checked_at)}</td>
                    <td className="py-1.5 pr-3">
                      {dataset.adapter_key !== null ? (
                        <Button variant="default" onClick={() => ingest.mutate(dataset.code)} disabled={ingest.isPending}>
                          Run now
                        </Button>
                      ) : (
                        <span className="text-[10px] text-ink-400">manual upload</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>

          <Card
            title="Replace the manual spreadsheet"
            subtitle="Uploading the same file again is safe: unchanged values are skipped and nothing is overwritten."
          >
            <div className="flex flex-wrap items-center gap-3">
              <input ref={fileInput} type="file" accept=".csv,text/csv" className="text-xs" />
              <Button
                variant="primary"
                disabled={upload.isPending}
                onClick={() => {
                  const file = fileInput.current?.files?.[0]
                  if (file !== undefined) upload.mutate(file)
                }}
              >
                {upload.isPending ? 'Importing…' : 'Import CSV'}
              </Button>
            </div>
          </Card>

          <Card title="Recent ingestion batches">
            {batches.isLoading && <Spinner />}
            {batches.error !== null && <ErrorState error={batches.error} />}
            {batches.data !== undefined && batches.data.data.length === 0 && <EmptyState>No batches recorded.</EmptyState>}
            {batches.data !== undefined && batches.data.data.length > 0 && (
              <div className="max-h-96 overflow-y-auto">
                <table className="w-full text-xs">
                  <thead className="sticky top-0 bg-white">
                    <tr className="border-b border-ink-200 text-left text-ink-500">
                      <th className="py-2 pr-3 font-medium">#</th>
                      <th className="py-2 pr-3 font-medium">Kind</th>
                      <th className="py-2 pr-3 font-medium">Status</th>
                      <th className="py-2 pr-3 font-medium">Source file</th>
                      <th className="py-2 pr-3 font-medium">New</th>
                      <th className="py-2 pr-3 font-medium">Unchanged</th>
                      <th className="py-2 pr-3 font-medium">Superseded</th>
                      <th className="py-2 pr-3 font-medium">Quarantined</th>
                      <th className="py-2 pr-3 font-medium">Conflicts</th>
                      <th className="py-2 pr-3 font-medium">Started</th>
                    </tr>
                  </thead>
                  <tbody>
                    {batches.data.data.map((batch) => (
                      <tr key={batch.id} className="border-b border-ink-100 last:border-0">
                        <td className="tnum py-1.5 pr-3 text-ink-600">{batch.id}</td>
                        <td className="py-1.5 pr-3 text-ink-600">{batch.kind}</td>
                        <td className="py-1.5 pr-3">
                          <Badge tone={batch.status === 'success' ? 'info' : batch.status === 'failed' ? 'danger' : 'warn'}>
                            {batch.status}
                          </Badge>
                        </td>
                        <td className="py-1.5 pr-3 text-ink-600">{batch.file_name ?? batch.dataset ?? '—'}</td>
                        <td className="tnum py-1.5 pr-3 text-ink-600">{batch.observations_created}</td>
                        <td className="tnum py-1.5 pr-3 text-ink-600">{batch.observations_unchanged}</td>
                        <td className="tnum py-1.5 pr-3 text-ink-600">{batch.observations_superseded}</td>
                        <td className="tnum py-1.5 pr-3 text-ink-600">{batch.quarantined}</td>
                        <td className="tnum py-1.5 pr-3 text-ink-600">{batch.conflicts}</td>
                        <td className="py-1.5 pr-3 text-ink-500">{formatDateTime(batch.started_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  )
}
