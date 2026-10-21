import { AlertTriangle, ShieldCheck } from 'lucide-react'

import type { SeriesPoint } from '../lib/types'
import { formatValue, unitLabel } from '../lib/format'
import { Badge, StatusChip } from './ui'

/**
 * A single stored value, presented with the qualifiers it cannot be separated from:
 * its status (reported / estimated / projected / missing), its source, and whether
 * another source disagrees. Clicking opens the full provenance.
 */
export function ValueCell({
  point,
  decimals,
  onOpenProvenance,
  showUnit = true,
}: {
  point: SeriesPoint | null
  decimals: number
  onOpenProvenance?: (uuid: string) => void
  showUnit?: boolean
}) {
  if (point === null) {
    return <span className="text-xs text-ink-400">no data</span>
  }

  const unit = showUnit && unitLabel(point.unit) !== '' ? ` ${unitLabel(point.unit)}` : ''

  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      {point.is_missing ? (
        <span className="text-xs text-ink-500" title={`No value recorded: ${point.missing_reason ?? 'unknown'}`}>
          —
        </span>
      ) : (
        <span className="tnum text-sm font-medium text-ink-900">
          {formatValue(point.value, point.unit, decimals)}
          {unit}
        </span>
      )}

      <StatusChip point={point} compact />

      {point.is_curated && (
        <Badge tone="info" title="Reviewed and corrected by a project editor. This value is served ahead of the ingested sources.">
          curated
        </Badge>
      )}

      {point.period_is_assumed && (
        <Badge
          tone="warn"
          title="The source states this figure without a reference year, so it is dated to the compilation year of the document. Treat the period as assumed."
        >
          period assumed
        </Badge>
      )}

      {point.has_conflict ? (
        <button
          type="button"
          title="Another source reports a different value for this period"
          onClick={() => onOpenProvenance?.(point.observation_uuid)}
          className="inline-flex items-center gap-0.5 rounded px-1 py-0.5 text-[11px] font-medium text-amber-700 hover:bg-amber-50"
        >
          <AlertTriangle size={11} /> conflict
        </button>
      ) : (
        point.other_sources.length > 0 && (
          <span
            title="Another source records the same value"
            className="inline-flex items-center gap-0.5 text-[11px] text-ink-400"
          >
            <ShieldCheck size={11} /> corroborated
          </span>
        )
      )}

      {onOpenProvenance !== undefined && (
        <button
          type="button"
          onClick={() => onOpenProvenance(point.observation_uuid)}
          className="text-[11px] text-sky-700 underline decoration-dotted underline-offset-2 hover:decoration-solid"
        >
          source
        </button>
      )}
    </span>
  )
}
