import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { ValueCell } from './ValueCell'
import type { SeriesPoint } from '../lib/types'

function point(overrides: Partial<SeriesPoint> = {}): SeriesPoint {
  return {
    period: '2024',
    period_key: '2024',
    period_type: 'annual',
    year: 2024,
    quarter: null,
    month: null,
    value: 1_450_935_791,
    value_text: null,
    unit: 'persons',
    status: 'reported',
    is_estimate: false,
    is_projection: false,
    is_missing: false,
    missing_reason: null,
    period_is_assumed: false,
    is_curated: false,
    is_derived: false,
    raw_value: '1450935791',
    reference_note: null,
    revision: 1,
    retrieved_at: '2026-01-01T00:00:00+00:00',
    observation_uuid: 'uuid-1',
    source: { code: 'world_bank', publisher_short: 'World Bank', source_type: 'world_bank', credibility_rank: 3 },
    provenance_url: '/api/v1/observations/uuid-1/provenance',
    has_conflict: false,
    other_sources: [],
    ...overrides,
  }
}

describe('ValueCell', () => {
  it('shows the value together with its status, never the number alone', () => {
    render(<ValueCell point={point()} decimals={0} />)

    expect(screen.getByText(/1,450,935,791/)).toBeInTheDocument()
    expect(screen.getByText('Reported')).toBeInTheDocument()
    // The unit travels with the number so it can never be read out of context.
    expect(screen.getByText(/people/)).toBeInTheDocument()
  })

  it('marks a projection as such rather than presenting it as an observation', () => {
    render(<ValueCell point={point({ is_projection: true, status: 'projected' })} decimals={0} />)

    expect(screen.getByText('Projection')).toBeInTheDocument()
  })

  it('renders missing data as a dash with the reason, not as zero', () => {
    render(
      <ValueCell
        point={point({ value: null, is_missing: true, status: 'missing', missing_reason: 'not_collected' })}
        decimals={0}
      />,
    )

    expect(screen.queryByText('0')).not.toBeInTheDocument()
    expect(screen.getByText('—')).toBeInTheDocument()
    expect(screen.getByText('Missing')).toBeInTheDocument()
  })

  it('flags a value another source disagrees with', () => {
    render(
      <ValueCell
        point={point({
          has_conflict: true,
          other_sources: [
            {
              value: 1_451_000_000,
              status: 'reported',
              agrees: false,
              observation_uuid: 'uuid-2',
              source: { code: 'manual_csv_v1', publisher_short: 'Manual spreadsheet', source_type: 'manual', credibility_rank: 5 },
              provenance_url: '/api/v1/observations/uuid-2/provenance',
            },
          ],
        })}
        decimals={0}
      />,
    )

    expect(screen.getByText('conflict')).toBeInTheDocument()
  })

  it('marks a value whose reference period the source did not state', () => {
    render(<ValueCell point={point({ period_is_assumed: true })} decimals={0} />)

    expect(screen.getByText('period assumed')).toBeInTheDocument()
  })

  it('marks a value a person has curated', () => {
    render(<ValueCell point={point({ is_curated: true })} decimals={0} />)

    expect(screen.getByText('curated')).toBeInTheDocument()
  })

  it('opens provenance for the exact observation on click', async () => {
    const onOpen = vi.fn()
    render(<ValueCell point={point()} decimals={0} onOpenProvenance={onOpen} />)

    await userEvent.click(screen.getByText('source'))

    expect(onOpen).toHaveBeenCalledWith('uuid-1')
  })

  it('says so when there is no value at all', () => {
    render(<ValueCell point={null} decimals={0} />)

    expect(screen.getByText('no data')).toBeInTheDocument()
  })
})
