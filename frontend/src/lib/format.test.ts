import { describe, expect, it } from 'vitest'

import { formatBytes, formatCompact, formatRelativeDays, formatValue, missingLabel, statusMeta, unitLabel } from './format'

describe('formatValue', () => {
  it('prints years as labels, never with thousands separators', () => {
    expect(formatValue(2024, 'year', 2)).toBe('2024')
    expect(formatValue(12, 'count', 2)).toBe('12')
  })

  it('keeps the declared precision for rates', () => {
    expect(formatValue(1.975, 'children_per_woman', 3)).toBe('1.975')
  })

  it('never invents a value for missing data', () => {
    expect(formatValue(null, 'persons', 0)).toBe('—')
  })
})

describe('unitLabel', () => {
  it('uses a readable label where one exists', () => {
    expect(unitLabel('per_1000')).toBe('per 1,000')
    expect(unitLabel('children_per_woman')).toBe('per woman')
  })

  it('falls back to the raw unit rather than dropping it', () => {
    expect(unitLabel('furlongs_per_fortnight')).toBe('furlongs_per_fortnight')
  })
})

describe('statusMeta', () => {
  const base = { status: 'reported', is_estimate: false, is_projection: false, is_missing: false, missing_reason: null }

  it('labels a plain observation as reported', () => {
    expect(statusMeta(base).label).toBe('Reported')
  })

  it('separates projections from reported values', () => {
    expect(statusMeta({ ...base, is_projection: true }).label).toBe('Projection')
  })

  it('separates estimates from reported values', () => {
    expect(statusMeta({ ...base, is_estimate: true }).label).toBe('Estimate')
  })

  it('flags missing values with their reason', () => {
    const meta = statusMeta({ ...base, is_missing: true, missing_reason: 'not_published' })
    expect(meta.label).toBe('Missing')
    expect(meta.title).toContain('not published')
  })
})

describe('missingLabel', () => {
  it('explains known reasons', () => {
    expect(missingLabel('suppressed')).toBe('suppressed')
  })

  it('does not imply a reason it does not have', () => {
    expect(missingLabel(null)).toBe('not available')
  })
})

describe('formatCompact and formatBytes', () => {
  it('abbreviates large counts', () => {
    expect(formatCompact(1_450_000_000)).toBe('1.45B')
  })

  it('scales byte sizes', () => {
    expect(formatBytes(2048)).toBe('2.0 KB')
  })

  it('handles absent values', () => {
    expect(formatCompact(null)).toBe('—')
    expect(formatBytes(null)).toBe('—')
  })
})

describe('formatRelativeDays', () => {
  it('describes a missing retrieval time as never', () => {
    expect(formatRelativeDays(null)).toBe('never')
  })

  it('describes very recent retrieval as today', () => {
    expect(formatRelativeDays(0.4)).toBe('today')
  })
})
