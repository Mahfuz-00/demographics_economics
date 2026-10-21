/**
 * Formatting helpers.
 *
 * The rule this module is built around is that a figure must never be presented
 * without its qualifiers, so `formatValue` takes the unit and precision from the
 * indicator definition, and the UI pairs every value with a status chip.
 */

export function formatNumber(value: number | null, decimals = 2): string {
  if (value === null || Number.isNaN(value)) return '—'

  return value.toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
}

export function formatCompact(value: number | null): string {
  if (value === null || Number.isNaN(value)) return '—'

  return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 2 }).format(value)
}

export function formatValue(value: number | null, unit: string, decimals = 2): string {
  if (value === null) return '—'

  // A year is a label, not a quantity: "2024", never "2,024".
  if (unit === 'year') return String(Math.round(value))

  const digits = unit === 'count' ? 0 : decimals

  return formatNumber(value, digits)
}

export function unitLabel(unit: string): string {
  const labels: Record<string, string> = {
    persons: 'people',
    percent: '%',
    per_1000: 'per 1,000',
    years: 'years',
    children_per_woman: 'per woman',
    currency_usd: 'US$',
    currency_usd_bn: 'US$ bn',
    count: '',
    year: '',
    ratio: '',
    index: '',
    text: '',
  }

  return labels[unit] ?? unit
}

export function missingLabel(reason: string | null): string {
  const labels: Record<string, string> = {
    not_published: 'not published by the source',
    not_collected: 'not collected in the source',
    suppressed: 'suppressed',
    not_applicable: 'not applicable',
    pending: 'pending publication',
    unknown: 'reason unknown',
  }

  return labels[reason ?? ''] ?? 'not available'
}

export interface StatusMeta {
  label: string
  className: string
  title: string
}

export function statusMeta(point: {
  status: string
  is_estimate: boolean
  is_projection: boolean
  is_missing: boolean
  missing_reason: string | null
}): StatusMeta {
  if (point.is_missing) {
    return {
      label: 'Missing',
      className: 'bg-ink-100 text-ink-600 ring-ink-200',
      title: `No value: ${missingLabel(point.missing_reason)}.`,
    }
  }

  if (point.is_projection) {
    return {
      label: 'Projection',
      className: 'bg-amber-50 text-amber-700 ring-amber-200',
      title: 'A modelled projection, not an observed figure. Do not compare directly with actuals.',
    }
  }

  if (point.is_estimate) {
    return {
      label: 'Estimate',
      className: 'bg-sky-50 text-sky-700 ring-sky-200',
      title: 'An estimate rather than a direct measurement or census count.',
    }
  }

  if (point.status === 'preliminary') {
    return {
      label: 'Preliminary',
      className: 'bg-violet-50 text-violet-700 ring-violet-200',
      title: 'Flagged as preliminary by the source and may be revised.',
    }
  }

  if (point.status === 'revised') {
    return {
      label: 'Revised',
      className: 'bg-violet-50 text-violet-700 ring-violet-200',
      title: 'A revision of a previously published figure.',
    }
  }

  return {
    label: 'Reported',
    className: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    title: 'Reported as an observed figure by the source.',
  }
}

export function formatDate(iso: string | null): string {
  if (!iso) return '—'

  return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

export function formatDateTime(iso: string | null): string {
  if (!iso) return '—'

  return new Date(iso).toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function formatRelativeDays(days: number | null): string {
  if (days === null) return 'never'
  if (days < 1) return 'today'
  if (days < 2) return 'yesterday'
  if (days < 45) return `${Math.round(days)} days ago`
  if (days < 400) return `${Math.round(days / 30)} months ago`

  return `${(days / 365).toFixed(1)} years ago`
}

export function formatBytes(bytes: number | null): string {
  if (bytes === null) return '—'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`

  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
