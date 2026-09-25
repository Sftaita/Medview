/**
 * Dates of a planning period for the detail page (docs/Design/react_planning_detail/src/period.ts).
 * Calendar dates only ("YYYY-MM-DD"), computed in UTC so no timezone shifts a day.
 * Unlike the mockup, the API's `endsAt` is EXCLUSIVE: callers pass the last day
 * (see {@link lastDayOf}).
 */

const DAY = 86_400_000

const toUTC = (iso: string) => {
  const [y, m, d] = iso.split('-').map(Number)
  return Date.UTC(y, m - 1, d)
}
const toISO = (ms: number) => new Date(ms).toISOString().slice(0, 10)
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)

/** The inclusive last day of a period whose API end date is exclusive. */
export function lastDayOf(endsAtExclusive: string): string {
  return toISO(toUTC(endsAtExclusive) - DAY)
}

/** Today ("YYYY-MM-DD") in the planning's timezone. */
export function todayISO(timeZone: string, now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now)
}

/** "Jeu. 1 oct. 2026" */
export function formatLongDate(iso: string): string {
  const f = new Intl.DateTimeFormat('fr-BE', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
  })
  return cap(f.format(new Date(toUTC(iso))))
}

/** "30 sept." */
export function formatShortDate(iso: string): string {
  return new Intl.DateTimeFormat('fr-BE', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(
    new Date(toUTC(iso)),
  )
}

export function daysInclusive(start: string, lastDay: string): number {
  return Math.round((toUTC(lastDay) - toUTC(start)) / DAY) + 1
}

export type MonthSegment = { key: string; label: string; days: number }

/** The period cut into months, with how many of each month's days it covers. */
export function monthSegments(start: string, lastDay: string): MonthSegment[] {
  const s = new Date(toUTC(start))
  const e = toUTC(lastDay)
  const out: MonthSegment[] = []
  let y = s.getUTCFullYear()
  let m = s.getUTCMonth()
  const multiYear = new Date(e).getUTCFullYear() !== y
  const fmt = new Intl.DateTimeFormat('fr-BE', { month: 'short', timeZone: 'UTC' })
  for (;;) {
    const first = Math.max(Date.UTC(y, m, 1), toUTC(start))
    const last = Math.min(Date.UTC(y, m + 1, 0), e)
    if (first > e) break
    const label = cap(fmt.format(new Date(Date.UTC(y, m, 1))))
    out.push({
      key: `${y}-${m}`,
      // A January after the first segment carries its year, so a period across years reads unambiguously.
      label: multiYear && m === 0 && out.length ? `${label} ${y}` : label,
      days: Math.round((last - first) / DAY) + 1,
    })
    m++
    if (m > 11) {
      m = 0
      y++
    }
  }
  return out
}

export type PeriodState =
  | { kind: 'upcoming'; label: string }
  | { kind: 'running'; label: string; progress: number }
  | { kind: 'past'; label: string }

export function periodState(start: string, lastDay: string, today: string): PeriodState {
  const t = toUTC(today)
  const s = toUTC(start)
  const e = toUTC(lastDay)
  if (t < s) {
    const n = Math.round((s - t) / DAY)
    return { kind: 'upcoming', label: n === 1 ? 'Commence demain' : `Commence dans ${n} jours` }
  }
  if (t > e) return { kind: 'past', label: 'Période terminée' }
  const day = Math.round((t - s) / DAY) + 1
  const total = daysInclusive(start, lastDay)
  return { kind: 'running', label: `En cours · jour ${day} sur ${total}`, progress: (day - 0.5) / total }
}
