import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

import { statusMeta } from '../lib/format'

export function Card({
  title,
  subtitle,
  actions,
  children,
  className = '',
}: {
  title?: ReactNode
  subtitle?: ReactNode
  actions?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <section className={`rounded-lg border border-ink-200 bg-white shadow-sm ${className}`}>
      {(title !== undefined || actions !== undefined) && (
        <header className="flex flex-wrap items-start justify-between gap-3 border-b border-ink-100 px-4 py-3">
          <div>
            {title !== undefined && <h2 className="text-sm font-semibold text-ink-900">{title}</h2>}
            {subtitle !== undefined && <p className="mt-0.5 text-xs text-ink-500">{subtitle}</p>}
          </div>
          {actions}
        </header>
      )}
      <div className="px-4 py-3">{children}</div>
    </section>
  )
}

export function Stat({ label, value, hint }: { label: string; value: ReactNode; hint?: ReactNode }) {
  return (
    <div className="rounded-lg border border-ink-200 bg-white px-4 py-3 shadow-sm">
      <div className="text-[11px] font-medium uppercase tracking-wide text-ink-500">{label}</div>
      <div className="tnum mt-1 text-2xl font-semibold text-ink-900">{value}</div>
      {hint !== undefined && <div className="mt-0.5 text-xs text-ink-500">{hint}</div>}
    </div>
  )
}

export function StatusChip({
  point,
  compact = false,
}: {
  point: {
    status: string
    is_estimate: boolean
    is_projection: boolean
    is_missing: boolean
    missing_reason: string | null
  }
  compact?: boolean
}) {
  const meta = statusMeta(point)

  return (
    <span
      title={meta.title}
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${meta.className} ${
        compact ? '' : 'whitespace-nowrap'
      }`}
    >
      {meta.label}
    </span>
  )
}

export function Badge({
  children,
  tone = 'neutral',
  title,
}: {
  children: ReactNode
  tone?: 'neutral' | 'warn' | 'danger' | 'info'
  title?: string
}) {
  const tones = {
    neutral: 'bg-ink-100 text-ink-700 ring-ink-200',
    warn: 'bg-amber-50 text-amber-700 ring-amber-200',
    danger: 'bg-rose-50 text-rose-700 ring-rose-200',
    info: 'bg-sky-50 text-sky-700 ring-sky-200',
  } as const

  return (
    <span
      title={title}
      className={`inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset ${tones[tone]}`}
    >
      {children}
    </span>
  )
}

export function Button({
  children,
  onClick,
  variant = 'default',
  type = 'button',
  disabled = false,
  className = '',
  title,
}: {
  children: ReactNode
  onClick?: () => void
  variant?: 'default' | 'primary' | 'ghost'
  type?: 'button' | 'submit'
  disabled?: boolean
  className?: string
  title?: string
}) {
  const variants = {
    default: 'border border-ink-300 bg-white text-ink-800 hover:bg-ink-50',
    primary: 'border border-ink-900 bg-ink-900 text-white hover:bg-ink-800',
    ghost: 'border border-transparent text-ink-600 hover:bg-ink-100',
  } as const

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50 ${variants[variant]} ${className}`}
    >
      {children}
    </button>
  )
}

export function Select({
  value,
  onChange,
  options,
  label,
  className = '',
}: {
  value: string
  onChange: (value: string) => void
  options: Array<{ value: string; label: string }>
  label?: string
  className?: string
}) {
  return (
    <label className={`flex flex-col gap-1 ${className}`}>
      {label !== undefined && <span className="text-[11px] font-medium text-ink-500">{label}</span>}
      <select
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="rounded-md border border-ink-300 bg-white px-2 py-1.5 text-xs text-ink-800"
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  )
}

export function SortGlyph({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
  return <span className="text-[9px] leading-none text-ink-400">{active ? (dir === 'asc' ? '▲' : '▼') : '↕'}</span>
}

export function Spinner({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex items-center gap-2 py-6 text-xs text-ink-500">
      <span className="h-3 w-3 animate-spin rounded-full border-2 border-ink-300 border-t-ink-700" />
      {label}
    </div>
  )
}

export function ErrorState({ error }: { error: unknown }) {
  const message = error instanceof Error ? error.message : 'Something went wrong.'

  return (
    <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
      <span className="font-medium">Could not load data.</span> {message}
    </div>
  )
}

export function EmptyState({ children }: { children: ReactNode }) {
  return <p className="py-4 text-xs text-ink-500">{children}</p>
}

export function EntityLink({ entity }: { entity: { iso_alpha3: string | null; name: string; slug: string } }) {
  const key = entity.iso_alpha3 ?? entity.slug

  return (
    <Link to={`/countries/${key}`} className="font-medium text-ink-900 underline decoration-ink-300 hover:decoration-ink-600">
      {entity.name}
    </Link>
  )
}

export function defLinkClassName(): string {
  return 'underline decoration-dotted decoration-ink-400 underline-offset-2 hover:decoration-ink-700'
}
