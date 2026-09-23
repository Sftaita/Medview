import { MONTH_NAMES } from '../../availability/calendarAxis'

const MS_PER_DAY = 86_400_000

function monthName(month0: number): string {
  return MONTH_NAMES[month0].toLowerCase()
}

type ZonedParts = { year: number; month: number; day: number; hour: number; minute: number }

/** The wall-clock parts of an instant in a given IANA timezone (month is 0-based). */
function partsInZone(iso: string, timeZone: string): ZonedParts {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone,
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    hourCycle: 'h23',
  }).formatToParts(new Date(iso))
  const get = (type: string) => Number(parts.find((part) => part.type === type)?.value)

  return {
    year: get('year'),
    month: get('month') - 1,
    day: get('day'),
    hour: get('hour'),
    minute: get('minute'),
  }
}

const pad = (value: number) => String(value).padStart(2, '0')

/** "25 septembre 2026" for a calendar date "YYYY-MM-DD" — never goes through `Date`, so no timezone shifts the day. */
export function formatLongDate(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  return `${day} ${monthName(month - 1)} ${year}`
}

/** "1 janvier 2027 → 30 avril 2027" for a planning's inclusive range. */
export function formatRange(startsAt: string, lastDay: string): string {
  return `${formatLongDate(startsAt)} → ${formatLongDate(lastDay)}`
}

/** "21/09/2026 à 10:14" for an instant, in the planning's timezone. */
export function formatDateTime(iso: string, timeZone: string): string {
  const { year, month, day, hour, minute } = partsInZone(iso, timeZone)
  return `${pad(day)}/${pad(month + 1)}/${year} à ${pad(hour)}:${pad(minute)}`
}

/** "21/09/2026" for an instant, in the planning's timezone. */
export function formatDateOnly(iso: string, timeZone: string): string {
  const { year, month, day } = partsInZone(iso, timeZone)
  return `${pad(day)}/${pad(month + 1)}/${year}`
}

/**
 * How an unavailability reads in the drawer, in the planning's timezone:
 * "3–7 janvier", "21 janvier", "28 janvier → 2 février", and — for a
 * period that does not follow whole days — "14 février 08:00 → 15 février 12:00".
 * The year is added only when it is not `referenceYear` (the planning's).
 */
export function formatPeriod(
  startsAt: string,
  endsAt: string,
  timeZone: string,
  referenceYear: number,
): string {
  const start = partsInZone(startsAt, timeZone)
  const end = partsInZone(endsAt, timeZone)

  const wholeDays = start.hour === 0 && start.minute === 0 && end.hour === 0 && end.minute === 0
  if (!wholeDays) {
    return `${withYear(start, referenceYear, start.year !== end.year)} ${pad(start.hour)}:${pad(start.minute)} → ${withYear(end, referenceYear, start.year !== end.year)} ${pad(end.hour)}:${pad(end.minute)}`
  }

  // The period ends at midnight, exclusive: the last day it covers is the day before.
  const last = new Date(Date.UTC(end.year, end.month, end.day) - MS_PER_DAY)
  const lastDay = { year: last.getUTCFullYear(), month: last.getUTCMonth(), day: last.getUTCDate() }
  const crossesYears = start.year !== lastDay.year
  const yearSuffix = !crossesYears && start.year !== referenceYear ? ` ${start.year}` : ''

  if (start.year === lastDay.year && start.month === lastDay.month && start.day === lastDay.day) {
    return `${start.day} ${monthName(start.month)}${yearSuffix}`
  }
  if (start.year === lastDay.year && start.month === lastDay.month) {
    return `${start.day}–${lastDay.day} ${monthName(start.month)}${yearSuffix}`
  }
  return `${withYear(start, referenceYear, crossesYears)} → ${withYear(lastDay, referenceYear, crossesYears)}`
}

function withYear(
  parts: { year: number; month: number; day: number },
  referenceYear: number,
  force: boolean,
): string {
  const base = `${parts.day} ${monthName(parts.month)}`
  return force || parts.year !== referenceYear ? `${base} ${parts.year}` : base
}

/** "Date souhaitée dépassée depuis 2 jours." — a visual warning only. */
export function overdueMessage(days: number): string {
  return `Date souhaitée dépassée depuis ${days} ${days > 1 ? 'jours' : 'jour'}.`
}

export function plural(count: number, singular: string, pluralForm = `${singular}s`): string {
  return `${count} ${count > 1 ? pluralForm : singular}`
}
