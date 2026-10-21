import { useEffect, useMemo, useState } from 'react'
import { Columns3 } from 'lucide-react'

import type { IndicatorRef } from '../lib/types'
import { Button } from './ui'

/**
 * Chooses which columns are on screen.
 *
 * The full set of indicators makes a very wide table, so the default is everything and
 * the user narrows it down.
 *
 * The panel expands *in the document flow* rather than as a floating popover. A popover
 * here would sit inside the page's flex row and fight the table's sticky headers for
 * z-index, and any ancestor with overflow would clip it — all silent failures that are
 * invisible until you open it in a real browser. Inline, there is nothing to clip or
 * cover.
 *
 * State is the set of columns that are *hidden*, not the ones shown, so switching module
 * or category cannot leave the selection pointing at indicators that are no longer in
 * view.
 */
export function ColumnPicker({
  indicators,
  hidden,
  onHiddenChange,
  storageKey,
}: {
  indicators: IndicatorRef[]
  hidden: string[]
  onHiddenChange: (codes: string[]) => void
  storageKey?: string
}) {
  const [open, setOpen] = useState(false)

  const grouped = useMemo(() => {
    const groups = new Map<string, { name: string; entries: IndicatorRef[] }>()

    indicators.forEach((indicator) => {
      const key = indicator.category?.code ?? 'other'

      if (!groups.has(key)) {
        groups.set(key, { name: indicator.category?.name ?? 'Other', entries: [] })
      }

      groups.get(key)?.entries.push(indicator)
    })

    return Array.from(groups.values())
  }, [indicators])

  const shown = indicators.filter((indicator) => !hidden.includes(indicator.code))

  const toggle = (code: string) => {
    onHiddenChange(hidden.includes(code) ? hidden.filter((entry) => entry !== code) : [...hidden, code])
  }

  /** Toggle a whole category at once — quicker than 12 individual clicks. */
  const toggleCategory = (entries: IndicatorRef[]) => {
    const codes = entries.map((entry) => entry.code)
    const allHidden = codes.every((code) => hidden.includes(code))

    onHiddenChange(allHidden ? hidden.filter((code) => !codes.includes(code)) : [...new Set([...hidden, ...codes])])
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        <Button onClick={() => setOpen((current) => !current)}>
          <Columns3 size={12} /> Columns ({shown.length}/{indicators.length})
        </Button>

        {hidden.length > 0 && (
          <span className="rounded bg-ink-100 px-2 py-1 text-[11px] text-ink-700">
            {hidden.length} hidden
          </span>
        )}

        {hidden.length > 0 && (
          <button type="button" className="text-[11px] text-sky-700 underline" onClick={() => onHiddenChange([])}>
            Show all
          </button>
        )}

        {open && (
          <>
            <button
              type="button"
              className="text-[11px] text-sky-700 underline"
              onClick={() => onHiddenChange(indicators.map((indicator) => indicator.code))}
            >
              Hide all
            </button>
            {storageKey !== undefined && (
              <button
                type="button"
                className="text-[11px] text-sky-700 underline"
                onClick={() => {
                  try {
                    localStorage.removeItem(storageKey)
                  } catch {
                    /* storage can be unavailable; the selection still works for this session */
                  }
                }}
              >
                Forget saved selection
              </button>
            )}
          </>
        )}
      </div>

      {open && (
        <div className="rounded-md border border-ink-200 bg-white p-3">
          <p className="mb-2 text-[11px] text-ink-500">
            Untick a column to hide it. You can also hide one straight from the table by pressing the × on its heading.
          </p>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {grouped.map((group) => (
              <div key={group.name}>
                <label className="flex cursor-pointer items-center gap-2 border-b border-ink-100 pb-1 text-[10px] font-semibold uppercase tracking-wide text-ink-400">
                  <input
                    type="checkbox"
                    checked={group.entries.every((entry) => !hidden.includes(entry.code))}
                    onChange={() => toggleCategory(group.entries)}
                  />
                  {group.name}
                </label>

                {group.entries.map((indicator) => (
                  <label
                    key={indicator.code}
                    title={indicator.definition ?? indicator.code}
                    className="flex cursor-pointer items-start gap-2 rounded px-1 py-1 text-[11px] text-ink-700 hover:bg-ink-50"
                  >
                    <input
                      type="checkbox"
                      className="mt-0.5"
                      checked={!hidden.includes(indicator.code)}
                      onChange={() => toggle(indicator.code)}
                    />
                    <span>{indicator.name}</span>
                  </label>
                ))}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * Remembers a column selection per module, so a reload does not put every column back.
 * Storage failures are swallowed: an unavailable localStorage must not break the table.
 */
export function useSavedHidden(key: string): [string[], (codes: string[]) => void] {
  const [hidden, setHidden] = useState<string[]>(() => {
    try {
      const stored = localStorage.getItem(key)

      return stored === null ? [] : (JSON.parse(stored) as string[])
    } catch {
      return []
    }
  })

  useEffect(() => {
    try {
      localStorage.setItem(key, JSON.stringify(hidden))
    } catch {
      /* see above */
    }
  }, [key, hidden])

  return [hidden, setHidden]
}
