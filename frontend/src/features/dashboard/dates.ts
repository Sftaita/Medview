/**
 * Date labels of the dashboard (docs/Design/react_dashboard/src/dates.ts):
 * « Jeu. 1 oct. 2026 », « Ven. 20 → dim. 22 nov. », durations counted inclusively.
 * Local calendar days only — never `new Date('yyyy-mm-dd')`, which is UTC.
 */

const WD = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.']
const MO = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
const MO_CAP = [
  'Janv.',
  'Févr.',
  'Mars',
  'Avr.',
  'Mai',
  'Juin',
  'Juil.',
  'Août',
  'Sept.',
  'Oct.',
  'Nov.',
  'Déc.',
]

/** "yyyy-mm-dd" → local midnight. */
export function parseISO(s: string): Date {
  const [y, m, d] = s.split('-').map(Number)
  return new Date(y, m - 1, d)
}

export function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

export function addDays(date: Date, n: number): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate() + n)
}

/** Calendar days from a to b (b − a). */
export function daysBetween(a: Date, b: Date): number {
  return Math.round((b.getTime() - a.getTime()) / 864e5)
}

/** Days from a to b, both included. */
export function inclusiveDays(a: Date, b: Date): number {
  return daysBetween(a, b) + 1
}

export const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)

/** "oct" — for the date tile. */
export function monthShort(d: Date): string {
  return MO[d.getMonth()].replace('.', '')
}

/** « jeu. 1 oct. », optionally with the year or without the weekday. */
export function formatDay(d: Date, opts: { year?: boolean; weekday?: boolean } = {}): string {
  const { year = false, weekday = true } = opts
  return `${weekday ? WD[d.getDay()] + ' ' : ''}${d.getDate()} ${MO[d.getMonth()]}${year ? ' ' + d.getFullYear() : ''}`
}

/** « Samedi 26 septembre 2026 » */
export function formatLongDate(d: Date): string {
  return cap(
    new Intl.DateTimeFormat('fr-BE', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(d),
  )
}

/** « Sam. 3 oct. », « Ven. 20 → dim. 22 nov. », « Lun. 30 nov. → mer. 2 déc. » */
export function formatUnavailability(a: Date, b: Date): string {
  if (daysBetween(a, b) === 0) return cap(formatDay(a))
  if (a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear())
    return `${cap(WD[a.getDay()])} ${a.getDate()} → ${formatDay(b)}`
  return `${cap(formatDay(a))} → ${formatDay(b)}`
}

export function relativeDays(n: number): string {
  if (n <= 0) return "Aujourd'hui"
  if (n === 1) return 'Demain'
  return `Dans ${n} jours`
}

export type MonthSegment = { key: string; label: string; days: number }

/** [start, end] cut into calendar months, with how many days each one covers. */
export function monthSegments(start: Date, end: Date): MonthSegment[] {
  const out: MonthSegment[] = []
  for (let d = new Date(start); d <= end; d = new Date(d.getFullYear(), d.getMonth() + 1, 1)) {
    const last = new Date(d.getFullYear(), d.getMonth() + 1, 0)
    out.push({
      key: `${d.getFullYear()}-${d.getMonth()}`,
      label: MO_CAP[d.getMonth()],
      days: inclusiveDays(d, last < end ? last : end),
    })
  }
  return out
}
