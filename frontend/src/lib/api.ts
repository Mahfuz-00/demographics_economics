import type {
  CompareResponse,
  ConflictRow,
  CountryFetchResponse,
  CoverageRow,
  EntityDetail,
  EntityMatrixResponse,
  SourceGapResponse,
  CorrelationResponse,
  PeersResponse,
  MoversResponse,
  EntityObservationsResponse,
  EntityRef,
  FetchRun,
  HealthResponse,
  IndicatorRef,
  IngestionBatchRow,
  LatestRow,
  MatrixResponse,
  ProvenanceResponse,
  QuarantineRow,
  SeriesResponse,
  SourceCandidate,
  SourceDataset,
  SourceDetail,
} from './types'

const BASE = (import.meta.env.VITE_API_BASE as string | undefined) ?? '/api/v1'

const TOKEN_KEY = 'demographics.admin.token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token === null) {
    localStorage.removeItem(TOKEN_KEY)
    return
  }
  localStorage.setItem(TOKEN_KEY, token)
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

type Query = Record<string, string | number | boolean | null | undefined>

function qs(params: Query = {}): string {
  const search = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return
    search.set(key, String(value))
  })

  const encoded = search.toString()

  return encoded === '' ? '' : `?${encoded}`
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = getToken()

  const response = await fetch(`${BASE}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      ...(init.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      ...(token !== null ? { Authorization: `Bearer ${token}` } : {}),
      ...(init.headers ?? {}),
    },
  })

  if (!response.ok) {
    let message = `Request failed (${response.status})`

    try {
      const payload = await response.json()
      message = payload.message ?? message
      if (payload.errors) {
        message = Object.values(payload.errors as Record<string, string[]>).flat().join(' ')
      }
    } catch {
      // Response was not JSON; keep the status-based message.
    }

    throw new ApiError(response.status, message)
  }

  return (await response.json()) as T
}

export interface SeriesQuery {
  entity: string
  indicator: string
  frequency?: string
  from?: number
  to?: number
  status?: string
  include_projections?: boolean
}

export const api = {
  health: () => request<HealthResponse>('/health'),

  entities: (params: Query = {}) =>
    request<{ data: EntityRef[]; meta: { count: number } }>(`/entities${qs(params)}`),

  entity: (key: string) => request<{ data: EntityDetail }>(`/entities/${encodeURIComponent(key)}`),

  /** Every current value held for one entity — the flat "all data" table. */
  entityObservations: (
    key: string,
    params: { indicator?: string; from?: number; to?: number; include_projections?: boolean } = {},
  ) =>
    request<EntityObservationsResponse>(
      `/entities/${encodeURIComponent(key)}/observations${qs({
        ...params,
        include_projections: params.include_projections === false ? 0 : 1,
      })}`,
    ),

  indicators: (params: Query = {}) =>
    request<{ data: IndicatorRef[]; meta: { count: number } }>(`/indicators${qs(params)}`),

  /** Countries as rows, indicators as columns, each cell the latest known value. */
  matrix: (params: Query = {}) =>
    request<MatrixResponse>(
      `/matrix${qs({ ...params, include_projections: params.include_projections === false ? 0 : 1 })}`,
    ),

  /** How far apart the sources are for one indicator, country by country. */
  sourceGap: (params: { indicator: string; year?: number; include_derived?: boolean }) =>
    request<SourceGapResponse>(`/analysis/source-gap${qs(params)}`),

  /** Pearson correlation between two indicators across countries, in one year. */
  correlation: (params: { x: string; y: string; year?: number; continent?: string }) =>
    request<CorrelationResponse>(`/analysis/correlation${qs(params)}`),

  /** A country against countries in the same continent with a similar reference value. */
  peers: (params: { entity: string; peer: string; metric: string; year?: number; tolerance?: number }) =>
    request<PeersResponse>(`/analysis/peers${qs(params)}`),

  /** Largest changes for one indicator between two years. */
  movers: (params: { indicator: string; from?: number; to?: number; limit?: number }) =>
    request<MoversResponse>(`/analysis/movers${qs(params)}`),

  /** One country, one row per year, every indicator across. */
  entityMatrix: (
    key: string,
    params: { indicator?: string; from?: number; to?: number; include_projections?: boolean } = {},
  ) =>
    request<EntityMatrixResponse>(
      `/entities/${encodeURIComponent(key)}/matrix${qs({
        ...params,
        include_projections: params.include_projections === false ? 0 : 1,
      })}`,
    ),

  indicator: (code: string) => request<{ data: IndicatorRef }>(`/indicators/${encodeURIComponent(code)}`),

  series: (query: SeriesQuery) =>
    request<SeriesResponse>(
      `/series${qs({ ...query, include_projections: query.include_projections === false ? 0 : 1 })}`,
    ),

  latest: (entities: string, indicators: string, includeProjections = false) =>
    request<{ data: LatestRow[] }>(
      `/series/latest${qs({ entities, indicators, include_projections: includeProjections ? 1 : 0 })}`,
    ),

  compare: (params: {
    entities: string
    indicator: string
    from?: number
    to?: number
    frequency?: string
    include_projections?: boolean
  }) =>
    request<CompareResponse>(
      `/compare${qs({ ...params, include_projections: params.include_projections === false ? 0 : 1 })}`,
    ),

  provenance: (uuid: string) =>
    request<ProvenanceResponse>(`/observations/${encodeURIComponent(uuid)}/provenance`),

  history: (uuid: string) =>
    request<{
      period_key: string
      count: number
      data: Array<{
        observation_uuid: string
        value: number | null
        status: string
        raw_value: string | null
        revision: number
        is_current: boolean
        retrieved_at: string | null
        source: { code: string; publisher_short: string | null } | null
      }>
    }>(`/observations/${encodeURIComponent(uuid)}/history`),

  coverage: (params: Query = {}) =>
    request<{ entity: EntityRef | null; data: CoverageRow[]; meta: { indicator_count: number } }>(
      `/coverage${qs(params)}`,
    ),

  conflicts: (params: Query = {}) =>
    request<{ data: ConflictRow[]; meta: { count: number } }>(`/conflicts${qs(params)}`),

  sources: () => request<{ data: SourceDetail[]; meta: { count: number } }>('/sources'),

  source: (code: string) => request<{ data: SourceDetail }>(`/sources/${encodeURIComponent(code)}`),

  sourceRuns: (code: string) =>
    request<{ data: FetchRun[] }>(`/sources/${encodeURIComponent(code)}/runs`),

  // --- admin -----------------------------------------------------------------

  login: (email: string, password: string) =>
    request<{ token: string; user: { name: string; email: string } }>('/auth/token', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    }),

  admin: {
    ingest: (datasetCode: string, queued = false) =>
      request<{ data?: FetchRun; queued?: boolean }>(`/admin/ingest/${encodeURIComponent(datasetCode)}`, {
        method: 'POST',
        body: JSON.stringify({ queued }),
      }),

    importCsv: (file: File, recordMissing = true) => {
      const body = new FormData()
      body.append('file', file)
      body.append('record_missing', recordMissing ? '1' : '0')

      return request<{ data: IngestionBatchRow }>('/admin/import/csv', { method: 'POST', body })
    },

    batches: () => request<{ data: IngestionBatchRow[] }>('/admin/batches'),

    quarantine: (params: Query = {}) =>
      request<{
        data: QuarantineRow[]
        meta: { count: number; unresolved_total: number; by_reason: Record<string, number> }
      }>(`/admin/quarantine${qs(params)}`),

    resolveQuarantine: (id: number, resolved: boolean, reasonDetail?: string) =>
      request<{ data: { id: number; resolved: boolean } }>(`/admin/quarantine/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ resolved, reason_detail: reasonDetail }),
      }),

    resolveConflict: (id: number, resolutionStatus: string, note?: string) =>
      request<{ data: { id: number; resolution_status: string } }>(`/admin/conflicts/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ resolution_status: resolutionStatus, resolution_note: note }),
      }),

    /** Scrape one country, with a web-search fallback that only proposes sources. */
    fetchCountry: (entityKey: string, dataset = 'wikipedia_vital_statistics') =>
      request<CountryFetchResponse>(`/admin/entities/${encodeURIComponent(entityKey)}/fetch`, {
        method: 'POST',
        body: JSON.stringify({ dataset }),
      }),

    candidates: (params: Query = {}) =>
      request<{ data: SourceCandidate[]; meta: { count: number; pending_total: number } }>(
        `/admin/candidates${qs(params)}`,
      ),

    reviewCandidate: (id: number, status: string, reason?: string) =>
      request<{ data: { id: number; status: string } }>(`/admin/candidates/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ status, reason }),
      }),

    /** Correct a value. Appended as a curated revision; the old value is kept. */
    curateObservation: (
      uuid: string,
      changes: { value?: number | null; period_year?: number; status?: string; missing_reason?: string; note?: string },
    ) =>
      request<{ data: { observation_uuid: string; period: string; value: number | null; is_curated: boolean } }>(
        `/admin/observations/${encodeURIComponent(uuid)}`,
        { method: 'PATCH', body: JSON.stringify(changes) },
      ),
  },
}

export type { SourceDataset }
