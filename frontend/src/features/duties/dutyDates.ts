import { cap, formatUnavailability, parseISO } from '../dashboard/dates'
import type { MyDuty } from './types'

/** First and last calendar day of a unit, as local dates. */
export function dutySpan(duty: MyDuty): { first: Date; last: Date } {
  return { first: parseISO(duty.dates[0]), last: parseISO(duty.dates[duty.dates.length - 1]) }
}

/** « Mar. 5 janv. », « Sam. 9 → dim. 10 janv. » — a block's days are consecutive. */
export function formatDutyDates(duty: MyDuty): string {
  const { first, last } = dutySpan(duty)
  return formatUnavailability(first, last)
}

/** Still to come (or under way today): its last day is today or later. Compared on calendar days, never instants. */
export function isUpcoming(duty: MyDuty, today: Date): boolean {
  return dutySpan(duty).last.getTime() >= today.getTime()
}

/** Upcoming units first-to-last, past ones last-to-first. */
export function splitDuties(duties: MyDuty[], today: Date): { upcoming: MyDuty[]; past: MyDuty[] } {
  const upcoming = duties.filter((duty) => isUpcoming(duty, today))
  const past = duties.filter((duty) => !isUpcoming(duty, today)).reverse()
  return { upcoming, past }
}

/** « Janvier 2027 » — the month a unit starts in. */
export function monthLabel(duty: MyDuty): string {
  return cap(
    new Intl.DateTimeFormat('fr-BE', { month: 'long', year: 'numeric' }).format(dutySpan(duty).first),
  )
}

/** Consecutive units grouped by the month they start in, order kept. */
export function groupByMonth(duties: MyDuty[]): { label: string; duties: MyDuty[] }[] {
  const groups: { label: string; duties: MyDuty[] }[] = []
  for (const duty of duties) {
    const label = monthLabel(duty)
    const last = groups[groups.length - 1]
    if (last?.label === label) last.duties.push(duty)
    else groups.push({ label, duties: [duty] })
  }
  return groups
}

/** « Bloc Week-end » or the duty type — what the unit is, beside its line. */
export function dutyWhat(duty: MyDuty): string {
  return duty.blockName !== null ? `Bloc ${duty.blockName}` : duty.dutyTypeName
}

/** The reinforcement badge, or null for a duty of an ordinary line. */
export function reinforcementLabel(duty: MyDuty): { label: string; tone: 'info' | 'warn' } | null {
  if (!duty.conditional) return null
  if (duty.coverageState === 'NOT_REQUIRED_ASSIGNED')
    return { label: 'Renfort non requis actuellement', tone: 'warn' }
  if (duty.coverageState === 'UNDETERMINED') return { label: 'Renfort à confirmer', tone: 'warn' }
  return { label: 'Renfort', tone: 'info' }
}
