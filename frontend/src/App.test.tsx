import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from './App'

/**
 * Smoke tests: every page is mounted against a stubbed API, so a runtime error in a
 * component (a bad destructure, a missing null check) fails here rather than in the
 * browser. Serendipity aside, these also pin the user-visible guarantees — that a
 * value is shown with its status, and that missing data reads as missing.
 */

const INDIA = {
  uuid: 'e-1',
  iso_alpha2: 'IN',
  iso_alpha3: 'IND',
  iso_numeric: '356',
  name: 'India',
  slug: 'india',
  entity_type: 'country',
  is_aggregate: false,
  continent: 'Asia',
  subregion: 'Southern Asia',
  csv_label: 'Asia(South)',
  sovereignty: null,
  parent: null,
}

const AFGHANISTAN = {
  uuid: 'e-2',
  iso_alpha2: 'AF',
  iso_alpha3: 'AFG',
  iso_numeric: '004',
  name: 'Afghanistan',
  slug: 'afghanistan',
  entity_type: 'country',
  is_aggregate: false,
  continent: 'Asia',
  subregion: 'Southern Asia',
  csv_label: 'Asia(South)',
  sovereignty: null,
  parent: null,
}

const POPULATION = {
  code: 'pop.total',
  name: 'Present population',
  description: 'Total population at the reference date.',
  definition: 'Total population at the reference date.',
  module: 'demographics',
  unit: 'persons',
  value_kind: 'level',
  decimals: 0,
  built_in_frequency: 'annual',
  supports_disaggregation: false,
  is_stock: false,
  is_per_capita: false,
  is_sensitive: false,
  aggregation_method: 'none',
  category: { code: 'population', name: 'Population' },
}

const POINT = {
  period: '2024',
  period_key: '2024',
  period_type: 'annual',
  year: 2024,
  quarter: null,
  month: null,
  value: 1450935791,
  value_text: null,
  unit: 'persons',
  status: 'reported',
  is_estimate: false,
  is_projection: false,
  is_missing: false,
  missing_reason: null,
  period_is_assumed: false,
  is_curated: false,
  raw_value: '1450935791',
  reference_note: 'World Bank WDI.',
  revision: 1,
  retrieved_at: '2026-01-01T00:00:00+00:00',
  observation_uuid: 'o-1',
  source: { code: 'world_bank', publisher_short: 'World Bank', source_type: 'world_bank', credibility_rank: 3 },
  provenance_url: '/api/v1/observations/o-1/provenance',
  has_conflict: false,
  other_sources: [],
}

function respond(url: string): unknown {
  if (url.includes('/health')) return { status: 'ok', time: '2026-01-01T00:00:00Z', observations: 142614 }
  if (url.includes('/sources')) {
    return {
      data: [
        {
          code: 'world_bank',
          publisher: 'World Bank',
          publisher_short: 'World Bank',
          source_type: 'world_bank',
          credibility_rank: 3,
          homepage_url: null,
          license: null,
          license_url: null,
          terms_note: 'Compiles national sources.',
          is_active: true,
          dataset_count: 1,
          datasets: [
            {
              code: 'world_bank_wdi_demographics',
              name: 'World Development Indicators',
              description: 'Demographic series',
              access_method: 'api_json',
              update_frequency: 'weekly',
              expected_latency_days: 30,
              adapter_key: 'world_bank',
              endpoint_url: null,
              documentation_url: null,
              enabled: true,
              last_checked_at: '2026-01-01T00:00:00+00:00',
              last_success_at: '2026-01-01T00:00:00+00:00',
              next_run_at: '2026-01-08T00:00:00+00:00',
              source: { code: 'world_bank', publisher_short: 'World Bank', source_type: 'world_bank', credibility_rank: 3 },
            },
          ],
        },
      ],
      meta: { count: 1 },
    }
  }
  if (url.includes('/coverage')) {
    return {
      entity: null,
      data: [
        {
          indicator: { code: 'pop.total', name: 'Present population', unit: 'persons', module: 'demographics' },
          observations: 65,
          span: { from: 1960, to: 2024 },
          missing_periods: 0,
          projected_periods: 0,
          estimated_periods: 0,
          source_count: 2,
          last_retrieved_at: '2026-01-01T00:00:00+00:00',
          days_since_retrieval: 1,
        },
      ],
      meta: { indicator_count: 1 },
    }
  }
  if (url.includes('/conflicts')) return { data: [], meta: { count: 0 } }
  if (url.includes('/series/latest')) {
    return { data: [{ entity: INDIA, indicator: POPULATION, latest: POINT }] }
  }
  if (url.includes('/series')) {
    return {
      entity: INDIA,
      indicator: POPULATION,
      frequency: 'all',
      count: 1,
      conflicting_periods: 0,
      missing_periods: 0,
      points: [POINT],
    }
  }
  if (url.includes('/entities/') && url.includes('/matrix')) {
    return {
      entity: INDIA,
      indicators: [POPULATION],
      rows: [
        {
          period: '2025',
          year: 2025,
          values: {
            'pop.total': {
              observation_uuid: 'o-2',
              indicator: { code: 'pop.total', name: 'Present population', unit: 'persons', decimals: 0, module: 'demographics', category: 'Population' },
              period: '2025',
              period_year: 2025,
              period_is_assumed: true,
              value: 1_460_000_000,
              status: 'reported',
              is_estimate: false,
              is_projection: false,
              is_missing: false,
              is_curated: false,
              missing_reason: null,
              reference_note: null,
              alternatives: 0,
              source: { code: 'manual_csv_v1', publisher_short: 'Manual spreadsheet', credibility_rank: 5 },
              retrieved_at: '2026-01-01T00:00:00+00:00',
            },
          },
        },
      ],
      meta: { year_count: 1, indicator_count: 1, cells: 1, assumed_cells: 1 },
    }
  }
  if (url.includes('/matrix')) {
    const INDIA_ROW = {
      entity: INDIA,
      values: {
        'pop.total': {
          value: 1_450_935_791,
          period: '2024',
          year: 2024,
          unit: 'persons',
          status: 'reported',
          is_estimate: false,
          is_projection: false,
          is_missing: false,
          period_is_assumed: false,
          is_curated: false,
          missing_reason: null,
          observation_uuid: 'o-3',
          source: { code: 'world_bank', publisher_short: 'World Bank', credibility_rank: 3 },
        },
      },
    }

    const AFGHANISTAN_ROW = {
      entity: AFGHANISTAN,
      values: {
        'pop.total': {
          value: 40_099_000,
          period: '2021',
          year: 2021,
          unit: 'persons',
          status: 'reported',
          is_estimate: false,
          is_projection: false,
          is_missing: false,
          period_is_assumed: false,
          is_curated: false,
          missing_reason: null,
          observation_uuid: 'o-4',
          source: { code: 'manual_csv_v1', publisher_short: 'Manual spreadsheet', credibility_rank: 5 },
        },
      },
    }

    return {
      indicators: [POPULATION],
      entities: [AFGHANISTAN, INDIA],
      rows: [AFGHANISTAN_ROW, INDIA_ROW],
      meta: { entity_count: 2, indicator_count: 1, cells: 2, include_projections: false },
    }
  }
  if (url.includes('/entities/') && url.includes('/observations')) {
    return {
      entity: INDIA,
      data: [
        {
          observation_uuid: 'o-2',
          indicator: { code: 'household.divorce_rate', name: 'Divorce rate', unit: 'percent', decimals: 1, module: 'demographics', category: 'Households & Family' },
          period: '2025',
          period_year: 2025,
          period_is_assumed: true,
          value: 12.5,
          status: 'reported',
          is_estimate: false,
          is_projection: false,
          is_missing: false,
          is_curated: false,
          missing_reason: null,
          reference_note: null,
          alternatives: 0,
          source: { code: 'manual_csv_v1', publisher_short: 'Manual spreadsheet', credibility_rank: 5 },
          retrieved_at: '2026-01-01T00:00:00+00:00',
        },
      ],
      meta: { count: 1, assumed_periods: 1, curated: 0 },
    }
  }
  if (url.includes('/entities/')) return { data: { ...INDIA, national_statistics_offices: [] } }
  if (url.includes('/entities')) return { data: [INDIA], meta: { count: 1 } }
  if (url.includes('/indicators/')) return { data: POPULATION }
  if (url.includes('/indicators')) return { data: [POPULATION], meta: { count: 1 } }
  if (url.includes('/compare')) return { indicator: POPULATION, frequency: 'annual', data: [{ entity: INDIA, points: [POINT] }] }

  return { data: [], meta: { count: 0 } }
}

function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <App />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)

      return new Response(JSON.stringify(respond(url)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }),
  )
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('pages render', () => {
  it('dashboard shows coverage and the missing-data note', async () => {
    renderAt('/')

    expect(screen.getByText('Overview')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Stored values')).toBeInTheDocument())
    expect(screen.getByText(/Blank cells in the source spreadsheet/)).toBeInTheDocument()
  })

  it('country profile shows the entity, its source authority and a latest value', async () => {
    renderAt('/countries/IND')

    await waitFor(() => expect(screen.getByRole('heading', { name: /India/ })).toBeInTheDocument())
    // The indicator appears both as a card and as a column in the all-data matrix.
    await waitFor(() => expect(screen.getAllByText('Present population').length).toBeGreaterThan(0))
    // Values appear both in the indicator cards and the all-data table.
    expect(screen.getAllByText('Reported').length).toBeGreaterThan(0)
  })

  it('country profile lists every stored value in the all-data table, flagged when the period is assumed', async () => {
    renderAt('/countries/IND')

    await waitFor(() => expect(screen.getByText('All data')).toBeInTheDocument())

    // Default view: one row per year, every indicator across.
    await waitFor(() => expect(screen.getAllByText('2025').length).toBeGreaterThan(0))
    expect(screen.getByRole('columnheader', { name: /Present population/ })).toBeInTheDocument()
    expect(screen.getByText(/with an assumed period/)).toBeInTheDocument()

    // The cell itself carries the value, not just the column heading.
    expect(screen.getByText('1,460,000,000')).toBeInTheDocument()
  })

  it('the country table can be switched to one row per indicator', async () => {
    renderAt('/countries/IND')

    await waitFor(() => expect(screen.getByText('By indicator')).toBeInTheDocument())
    await userEvent.click(screen.getByText('By indicator'))

    await waitFor(() => expect(screen.getByText('Divorce rate')).toBeInTheDocument())
    expect(screen.getAllByText('assumed').length).toBeGreaterThan(0)
  })

  it('table page shows countries as rows with each indicator as a column', async () => {
    renderAt('/table')

    await waitFor(() => expect(screen.getByRole('heading', { name: 'Table' })).toBeInTheDocument())
    await waitFor(() => expect(screen.getByRole('link', { name: 'India' })).toBeInTheDocument())
    expect(screen.getByRole('columnheader', { name: /Present population/ })).toBeInTheDocument()

    // Cells render the latest value in full, with thousands separators.
    expect(screen.getByText('1,450,935,791')).toBeInTheDocument()
  })

  it('the column picker hides a column and can bring it back', async () => {
    renderAt('/table')

    await waitFor(() => expect(screen.getByRole('columnheader', { name: /Present population/ })).toBeInTheDocument())

    await userEvent.click(screen.getByRole('button', { name: /Columns/ }))

    const checkbox = await screen.findByRole('checkbox', { name: 'Present population' })
    await userEvent.click(checkbox)

    await waitFor(() =>
      expect(screen.queryByRole('columnheader', { name: /Present population/ })).not.toBeInTheDocument(),
    )

    await userEvent.click(checkbox)

    await waitFor(() => expect(screen.getByRole('columnheader', { name: /Present population/ })).toBeInTheDocument())
  })

  it('clicking a column heading sorts the countries by that indicator', async () => {
    renderAt('/table')

    await waitFor(() => expect(screen.getByRole('link', { name: 'India' })).toBeInTheDocument())

    const order = () =>
      screen.getAllByRole('link', { name: /^(Afghanistan|India)$/ }).map((element) => element.textContent)

    // Default: countries A→Z.
    expect(order()).toEqual(['Afghanistan', 'India'])

    // A numeric column starts at the largest value. The heading also carries a "Hide"
    // button, so the sort control is matched from the start of its name.
    await userEvent.click(screen.getByRole('button', { name: /^Present population/ }))
    expect(order()).toEqual(['India', 'Afghanistan'])

    // Clicking the same heading again reverses it.
    await userEvent.click(screen.getByRole('button', { name: /^Present population/ }))
    expect(order()).toEqual(['Afghanistan', 'India'])
  })

  it('a column can be hidden straight from its heading with the ×', async () => {
    renderAt('/table')

    await waitFor(() => expect(screen.getByRole('link', { name: 'India' })).toBeInTheDocument())
    expect(screen.getByRole('columnheader', { name: /Present population/ })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Hide Present population' }))

    await waitFor(() =>
      expect(screen.queryByRole('columnheader', { name: /Present population/ })).not.toBeInTheDocument(),
    )

    // The hidden count is stated on the page, and one click brings the column back.
    expect(screen.getByText('1 hidden')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Show all' }))

    await waitFor(() => expect(screen.getByRole('columnheader', { name: /Present population/ })).toBeInTheDocument())
  })

  it('hiding a column removes its data cells as well as its heading', async () => {
    renderAt('/table')

    await waitFor(() => expect(screen.getByRole('link', { name: 'India' })).toBeInTheDocument())

    // A row is: the country (a th) + a continent cell + one cell per indicator column.
    const dataCells = () => {
      const row = screen.getByRole('link', { name: 'India' }).closest('tr')

      return row?.querySelectorAll('td').length ?? 0
    }

    expect(dataCells()).toBe(2)

    await userEvent.click(screen.getByRole('button', { name: 'Hide Present population' }))

    await waitFor(() =>
      expect(screen.queryByRole('columnheader', { name: /Present population/ })).not.toBeInTheDocument(),
    )

    // The body has to shrink with the header. Filtering only the heading leaves every
    // row one cell too wide, so each value slides under the wrong column.
    expect(dataCells()).toBe(1)
  })

  it('indicator explorer renders the series and table', async () => {
    renderAt('/indicators/pop.total')

    await waitFor(() => expect(screen.getByText('Historical table')).toBeInTheDocument())
    expect(screen.getAllByText('Present population').length).toBeGreaterThan(0)
  })

  it('compare page renders the selection controls', async () => {
    renderAt('/compare')

    expect(screen.getByText('Compare entities')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Indicator')).toBeInTheDocument())
  })

  it('sources page lists a source with its credibility rank', async () => {
    renderAt('/sources')

    // The dataset name is unique on the page, unlike the publisher name which
    // legitimately appears as both title and subtitle.
    await waitFor(() => expect(screen.getByText('World Development Indicators')).toBeInTheDocument())
    expect(screen.getByText(/rank 3/)).toBeInTheDocument()
  })

  it('data quality page is readable without a token', async () => {
    renderAt('/quality')

    expect(screen.getByRole('heading', { name: 'Data quality' })).toBeInTheDocument()
    expect(screen.getByText(/Reading only/)).toBeInTheDocument()
  })

  it('admin page asks for credentials when there is no token', async () => {
    renderAt('/admin')

    expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('an unknown route falls back to the dashboard', async () => {
    renderAt('/does-not-exist')

    expect(screen.getByText('Overview')).toBeInTheDocument()
  })
})
