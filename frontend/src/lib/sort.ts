/**
 * Shared sorting for the tabular views.
 *
 * Two rules that matter for a data table:
 *  - missing values always sink to the bottom, whichever direction is active, so a
 *    descending sort shows the largest figures first rather than a screen of blanks;
 *  - clicking the same column twice flips direction, clicking a different column starts
 *    from the more useful end (largest first for numbers, A→Z for text).
 */

export type SortDir = 'asc' | 'desc'

export interface SortState {
  key: string
  dir: SortDir
}

function isMissing(value: number | null | undefined): boolean {
  return value === null || value === undefined || Number.isNaN(value)
}

export function compareNullable(
  a: number | null | undefined,
  b: number | null | undefined,
  dir: SortDir,
): number {
  const aMissing = isMissing(a)
  const bMissing = isMissing(b)

  if (aMissing && bMissing) return 0
  if (aMissing) return 1
  if (bMissing) return -1

  return dir === 'asc' ? (a as number) - (b as number) : (b as number) - (a as number)
}

export function compareText(a: string, b: string, dir: SortDir): number {
  return dir === 'asc' ? a.localeCompare(b) : b.localeCompare(a)
}

export function nextSort(current: SortState, key: string, numeric: boolean): SortState {
  if (current.key === key) {
    return { key, dir: current.dir === 'asc' ? 'desc' : 'asc' }
  }

  return { key, dir: numeric ? 'desc' : 'asc' }
}
