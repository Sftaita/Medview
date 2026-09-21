import { dayIndex, MONTH_NAMES } from './calendarAxis'

/** "YYYY-MM-DD" → its civil parts. Never goes through `Date`, so no timezone can shift the day. */
function civil(date: string): { year: number; month: number; day: number } {
  const [year, month, day] = date.split('-').map(Number)
  return { year, month: month - 1, day }
}

function daysInMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month + 1, 0)).getUTCDate()
}

function monthName(month: number): string {
  return MONTH_NAMES[month].toLowerCase()
}

/**
 * "janvier–mars 2027", "septembre 2026", "novembre 2026–février 2027", or
 * — when the window does not follow whole months — "du 15 janvier au 20 mars 2027".
 */
export function formatWindow(startsAt: string, lastDay: string): string {
  const from = civil(startsAt)
  const to = civil(lastDay)
  const wholeMonths = from.day === 1 && to.day === daysInMonth(to.year, to.month)

  if (!wholeMonths) {
    const start =
      from.year === to.year
        ? `${from.day} ${monthName(from.month)}`
        : `${from.day} ${monthName(from.month)} ${from.year}`
    return `du ${start} au ${to.day} ${monthName(to.month)} ${to.year}`
  }
  if (from.year === to.year && from.month === to.month) {
    return `${monthName(from.month)} ${from.year}`
  }
  if (from.year === to.year) {
    return `${monthName(from.month)}–${monthName(to.month)} ${to.year}`
  }
  return `${monthName(from.month)} ${from.year}–${monthName(to.month)} ${to.year}`
}

/** "20 décembre" (the year only when it is not the current one). */
export function formatDeadline(deadline: string, now: Date = new Date()): string {
  const { year, month, day } = civil(deadline)
  return year === now.getFullYear() ? `${day} ${monthName(month)}` : `${day} ${monthName(month)} ${year}`
}

const SHORT_DATE = new Intl.DateTimeFormat('fr-BE', { day: '2-digit', month: '2-digit' })
const SHORT_DATE_YEAR = new Intl.DateTimeFormat('fr-BE', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})

/** "14/12" for an instant, with the year when it is not the current one. */
export function formatMoment(iso: string, now: Date = new Date()): string {
  const date = new Date(iso)
  return (date.getFullYear() === now.getFullYear() ? SHORT_DATE : SHORT_DATE_YEAR).format(date)
}

/** "10/12/2026" — always with the year, for history. */
export function formatMomentFull(iso: string): string {
  return SHORT_DATE_YEAR.format(new Date(iso))
}

/** "20/12/2026" for a calendar date. */
export function formatDateFull(date: string): string {
  const { year, month, day } = civil(date)
  return `${String(day).padStart(2, '0')}/${String(month + 1).padStart(2, '0')}/${year}`
}

/** Compact "janv.–mars 2027"-like label for history rows. */
export function formatWindowShort(startsAt: string, lastDay: string): string {
  return formatWindow(startsAt, lastDay).replace(/^./, (c) => c.toUpperCase())
}

/** The window as inclusive absolute calendar days — what the calendar axis and `DayRange` speak. */
export function collectionDays(collection: { startsAt: string; lastDay: string }): {
  start: number
  end: number
} {
  const from = civil(collection.startsAt)
  const to = civil(collection.lastDay)
  return { start: dayIndex(from.year, from.month, from.day), end: dayIndex(to.year, to.month, to.day) }
}
