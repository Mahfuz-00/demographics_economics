export type EntityType = 'country' | 'dependency' | 'territory' | 'region' | 'subregion' | 'aggregate' | 'group'

export interface EntityRef {
  uuid: string
  iso_alpha2: string | null
  iso_alpha3: string | null
  iso_numeric: string | null
  name: string
  slug: string
  entity_type: EntityType
  is_aggregate: boolean
  continent: string | null
  subregion: string | null
  csv_label: string | null
  sovereignty: { iso_alpha3: string | null; name: string } | null
  parent: { slug: string; name: string } | null
}

export interface NationalStatisticsOffice {
  office_name: string
  website_url: string | null
  data_portal_url: string | null
  has_api: boolean
  access_notes: string | null
}

export interface EntityDetail extends EntityRef {
  national_statistics_offices: NationalStatisticsOffice[]
}

export interface IndicatorRef {
  code: string
  name: string
  description: string | null
  definition: string | null
  module: 'demographics' | 'economy' | 'debt' | 'wealth'
  unit: string
  value_kind: string
  decimals: number
  built_in_frequency: string
  supports_disaggregation: boolean
  is_stock: boolean
  is_per_capita: boolean
  is_sensitive: boolean
  aggregation_method: string
  category: { code: string; name: string } | null
}

export interface SourceRef {
  code: string
  publisher?: string
  publisher_short: string | null
  source_type: string
  credibility_rank: number
  homepage_url?: string | null
  license?: string | null
}

export interface OtherSource {
  value: number | null
  status: string
  agrees: boolean
  observation_uuid: string
  source: SourceRef | null
  provenance_url: string
}

export interface SeriesPoint {
  period: string
  period_key: string
  period_type: string
  year: number
  quarter: number | null
  month: number | null
  value: number | null
  value_text: string | null
  unit: string
  status: string
  is_estimate: boolean
  is_projection: boolean
  is_missing: boolean
  missing_reason: string | null
  period_is_assumed: boolean
  is_curated: boolean
  is_derived: boolean
  raw_value: string | null
  reference_note: string | null
  revision: number
  retrieved_at: string | null
  observation_uuid: string
  source: SourceRef | null
  provenance_url: string
  has_conflict: boolean
  other_sources: OtherSource[]
}

export interface SeriesResponse {
  entity: EntityRef
  indicator: IndicatorRef
  frequency: string
  count: number
  conflicting_periods: number
  missing_periods: number
  points: SeriesPoint[]
}

export interface CompareSeries {
  entity: EntityRef
  points: SeriesPoint[]
}

export interface CompareResponse {
  indicator: IndicatorRef
  frequency: string
  data: CompareSeries[]
}

export interface LatestRow {
  entity: EntityRef
  indicator: IndicatorRef
  latest: SeriesPoint | null
}

export interface ProvenanceResponse {
  data: SeriesPoint & {
    entity: EntityRef | null
    indicator: { code: string; name: string; value_kind: string; module: string } | null
    period_range: { start: string | null; end: string | null }
    source_record_ref: string | null
    published_date: string | null
    retrieved_at: string | null
    notes: string | null
    quality_score: number | null
    import_batch_id: number | null
  }
  snapshot: {
    id: number
    url: string | null
    content_type: string | null
    byte_size: number | null
    content_hash: string
    storage_path: string | null
    fetched_at: string | null
  } | null
  revisions: {
    this_is_current: boolean
    superseded_by: { observation_uuid: string; value: number | null; revision: number; retrieved_at: string | null } | null
    replaced: { observation_uuid: string; value: number | null; revision: number; retrieved_at: string | null } | null
  }
  conflicts: Array<{
    id: number
    significance: string
    resolution_status: string
    value_delta: number | null
    pct_delta: number | null
    primary: { observation_uuid: string; value: number | null; source: SourceRef | null }
    conflicting: { observation_uuid: string; value: number | null; source: SourceRef | null }
  }>
}

export interface CoverageRow {
  indicator: { code: string; name: string; unit: string; module: string } | null
  observations: number
  span: { from: number; to: number }
  missing_periods: number
  projected_periods: number
  estimated_periods: number
  source_count: number
  last_retrieved_at: string | null
  days_since_retrieval: number | null
}

export interface ConflictRow {
  id: number
  entity: { iso_alpha3: string | null; name: string } | null
  indicator: { code: string; name: string } | null
  period: string
  period_type: string
  significance: 'minor' | 'notable' | 'major'
  resolution_status: string
  value_delta: number | null
  pct_delta: number | null
  primary: { observation_uuid: string; value: number | null; source: SourceRef | null }
  conflicting: { observation_uuid: string; value: number | null; source: SourceRef | null }
  resolution_note: string | null
  resolved_at: string | null
}

export interface SourceDetail {
  code: string
  publisher: string
  publisher_short: string | null
  source_type: string
  credibility_rank: number
  homepage_url: string | null
  license: string | null
  license_url: string | null
  terms_note: string | null
  is_active: boolean
  dataset_count?: number
  datasets?: SourceDataset[]
}

export interface SourceDataset {
  code: string
  name: string
  description: string | null
  access_method: string
  update_frequency: string
  expected_latency_days: number | null
  adapter_key: string | null
  endpoint_url: string | null
  documentation_url: string | null
  enabled: boolean
  last_checked_at: string | null
  last_success_at: string | null
  next_run_at: string | null
  source?: SourceRef | null
}

export interface FetchRun {
  id: number
  status: string
  started_at: string | null
  finished_at: string | null
  duration_ms: number | null
  http_status: number | null
  bytes: number | null
  records_seen: number
  records_new: number
  records_revised: number
  records_unchanged: number
  records_rejected: number
  triggered_by: string
  error_class: string | null
  error_message: string | null
  dataset: { code: string; name: string } | null
}

export interface QuarantineRow {
  id: number
  batch_id: number | null
  dataset: string | null
  sub_table: string | null
  row_number: number | null
  reason_code: string
  reason_detail: string | null
  raw_row: unknown
  resolved: boolean
  resolved_at: string | null
}

export interface IngestionBatchRow {
  id: number
  kind: string
  status: string
  file_name: string | null
  dataset: string | null
  rows_seen: number
  observations_created: number
  observations_unchanged: number
  observations_superseded: number
  quarantined: number
  conflicts: number
  started_at: string | null
  finished_at: string | null
}

export interface HealthResponse {
  status: string
  time: string
  observations: number
}

/** One flat row of the per-country "all data" table. */
export interface EntityObservationRow {
  observation_uuid: string
  indicator: {
    code: string
    name: string
    unit: string
    decimals: number
    module: string
    category: string | null
  } | null
  period: string
  period_year: number
  period_is_assumed: boolean
  value: number | null
  status: string
  is_estimate: boolean
  is_projection: boolean
  is_missing: boolean
  is_curated: boolean
  is_derived: boolean
  missing_reason: string | null
  reference_note: string | null
  /** How many other sources also hold this period (reachable via provenance). */
  alternatives: number
  source: { code: string | null; publisher_short: string | null; credibility_rank: number | null }
  retrieved_at: string | null
}

export interface EntityObservationsResponse {
  entity: EntityRef
  data: EntityObservationRow[]
  meta: { count: number; assumed_periods: number; curated: number }
}

/** A source found by web search, awaiting a human decision. */
export interface SourceCandidate {
  id: number
  entity: { iso_alpha3: string | null; name: string } | null
  query?: string
  title: string | null
  url: string
  host: string | null
  snippet: string | null
  status: 'pending' | 'accepted' | 'rejected' | 'duplicate'
  reviewed_at?: string | null
}

/** A cell in the countries × indicators table: the latest known value. */
export interface MatrixCell {
  value: number | null
  period: string
  year: number
  unit: string
  status: string
  is_estimate: boolean
  is_projection: boolean
  is_missing: boolean
  period_is_assumed: boolean
  is_curated: boolean
  is_derived: boolean
  missing_reason: string | null
  observation_uuid: string
  source: { code: string | null; publisher_short: string | null; credibility_rank: number | null }
}

export interface MatrixResponse {
  indicators: IndicatorRef[]
  entities: EntityRef[]
  rows: Array<{ entity: EntityRef; values: Record<string, MatrixCell> }>
  meta: {
    entity_count: number
    indicator_count: number
    cells: number
    include_projections: boolean
  }
}

/** One year of a country's matrix: every indicator's value for that period. */
export interface AnalysisIndicator {
  code: string
  name: string
  unit: string
  decimals: number
  module?: string
}

export interface SourceGapRow {
  entity: { name: string; iso_alpha3: string | null; slug: string; continent: string | null }
  values: Record<string, { value: number; derived: boolean }>
  spread: number
  pct: number
  sources: number
}

export interface SourceGapResponse {
  indicator: AnalysisIndicator
  year: number
  rows: SourceGapRow[]
  meta: {
    comparable_entities: number
    returned: number
    median_pct: number | null
    max_pct: number | null
    derived_included: boolean
    sources_available: { code: string; publisher: string | null }[]
    note: string
  }
}

export interface CorrelationPoint {
  entity: { name: string; iso_alpha3: string | null; slug: string; continent: string | null }
  x: number
  y: number
}

export interface CorrelationResponse {
  x: AnalysisIndicator
  y: AnalysisIndicator
  year: number | null
  continent?: string | null
  n: number
  r: number | null
  points: CorrelationPoint[]
  meta: {
    note: string
    continents: { continent: string; n: number; r: number | null }[]
  }
}

export interface PeerMember {
  entity: { name: string; iso_alpha3: string | null; slug: string; continent: string | null }
  peer_value: number
  metric_value: number
  is_target: boolean
}

export interface PeersResponse {
  entity: { name: string; iso_alpha3: string | null; slug: string; continent: string | null }
  peer_indicator: AnalysisIndicator
  metric_indicator: AnalysisIndicator
  year: number
  group: {
    group_by: string
    tolerance: number
    n: number
    target_peer_value: number
    target_metric_value: number
    mean: number | null
    median: number | null
    min: number | null
    max: number | null
    target_percentile: number | null
  }
  members: PeerMember[]
  meta: { note: string }
}

export interface MoverRow {
  entity: { name: string; iso_alpha3: string | null; slug: string; continent: string | null }
  from_value: number
  to_value: number
  change: number
  pct: number
  suspect: boolean
}

export interface MoversResponse {
  indicator: AnalysisIndicator
  from: number
  to: number
  rows: MoverRow[]
  meta: { comparable_entities: number; flagged_for_review: number; note: string }
}

export interface EntityMatrixRow {
  period: string
  year: number
  values: Record<string, EntityObservationRow>
}

export interface EntityMatrixResponse {
  entity: EntityRef
  indicators: IndicatorRef[]
  rows: EntityMatrixRow[]
  meta: {
    year_count: number
    indicator_count: number
    cells: number
    assumed_cells: number
  }
}

export interface CountryFetchResponse {
  entity: { iso_alpha3: string | null; name: string }
  run: FetchRun | null
  observations_created: number
  observations_unchanged: number
  searched_web: boolean
  candidates: Array<{
    id: number
    title: string | null
    url: string
    host: string | null
    snippet: string | null
    status: string
  }>
}
