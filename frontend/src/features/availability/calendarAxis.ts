/**
 * The calendar works on an *absolute day axis*: a day is an integer, the
 * number of local calendar days since 1970-01-01. Periods crossing a month or
 * a year boundary are then plain integer ranges, and dates are only converted
 * to `Date` at the edges (display, API).
 */

const MS_PER_DAY = 86_400_000

export const MONTH_NAMES = [
  'Janvier',
  'Février',
  'Mars',
  'Avril',
  'Mai',
  'Juin',
  'Juillet',
  'Août',
  'Septembre',
  'Octobre',
  'Novembre',
  'Décembre',
]
export const MONTH_SHORT = [
  'janv.',
  'févr.',
  'mars',
  'avr.',
  'mai',
  'juin',
  'juil.',
  'août',
  'sept.',
  'oct.',
  'nov.',
  'déc.',
]
export const WEEKDAY_SHORT = ['Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.']
export const WEEKDAY_LONG = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']

/** Day index of a calendar date (year, 0-based month, day). Day 0 is 1970-01-01. */
export function dayIndex(year: number, month: number, day: number): number {
  return Math.round(Date.UTC(year, month, day) / MS_PER_DAY)
}

/** Day index of the *local* calendar day a Date falls on. */
export function dayIndexOfDate(date: Date): number {
  return dayIndex(date.getFullYear(), date.getMonth(), date.getDate())
}

/** Local midnight at the start of a day index. */
export function dateOfDayIndex(index: number): Date {
  const utc = new Date(index * MS_PER_DAY)
  return new Date(utc.getUTCFullYear(), utc.getUTCMonth(), utc.getUTCDate())
}

type CivilDate = { year: number; month: number; day: number }

function civilOf(index: number): CivilDate {
  const utc = new Date(index * MS_PER_DAY)
  return { year: utc.getUTCFullYear(), month: utc.getUTCMonth(), day: utc.getUTCDate() }
}

/** 0 = Monday … 6 = Sunday (1970-01-01, day 0, was a Thursday). */
export function weekdayOf(index: number): number {
  return (((index + 3) % 7) + 7) % 7
}

export function isWeekend(index: number): boolean {
  return weekdayOf(index) >= 5
}

export type MonthInfo = {
  year: number
  /** 0-based. */
  month: number
  name: string
  /** Day index of the 1st. */
  start: number
  days: number
  /** Monday-first column (0-6) of the 1st. */
  offset: number
}

export function monthInfo(year: number, month: number): MonthInfo {
  const start = dayIndex(year, month, 1)
  return {
    year,
    month,
    name: `${MONTH_NAMES[month]} ${year}`,
    start,
    days: dayIndex(year, month + 1, 1) - start,
    offset: weekdayOf(start),
  }
}

/** `count` consecutive months starting at (year, month); month may overflow (12 → next January). */
export function buildMonths(year: number, month: number, count: number): MonthInfo[] {
  return Array.from({ length: count }, (_, k) => {
    const total = year * 12 + month + k
    return monthInfo(Math.floor(total / 12), total % 12)
  })
}

/** Index in `months` of the month containing the day, or -1. */
export function monthIndexOfDay(months: MonthInfo[], index: number): number {
  return months.findIndex((m) => index >= m.start && index < m.start + m.days)
}

/** Western Easter Sunday (anonymous Gregorian algorithm). */
function easterIndex(year: number): number {
  const a = year % 19
  const b = Math.floor(year / 100)
  const c = year % 100
  const d = Math.floor(b / 4)
  const e = b % 4
  const f = Math.floor((b + 8) / 25)
  const g = Math.floor((b - f + 1) / 3)
  const h = (19 * a + b - d - g + 15) % 30
  const i = Math.floor(c / 4)
  const k = c % 4
  const l = (32 + 2 * e + 2 * i - h - k) % 7
  const m = Math.floor((a + 11 * h + 22 * l) / 451)
  const month = Math.floor((h + l - 7 * m + 114) / 31) - 1
  const day = ((h + l - 7 * m + 114) % 31) + 1
  return dayIndex(year, month, day)
}

const holidayCache = new Map<number, Map<number, string>>()

/**
 * Belgian public holidays of a year, by day index. Informative only: the
 * calendar tints them but every day stays selectable.
 */
export function holidaysOfYear(year: number): Map<number, string> {
  const cached = holidayCache.get(year)
  if (cached) {
    return cached
  }
  const easter = easterIndex(year)
  const holidays = new Map<number, string>([
    [dayIndex(year, 0, 1), 'Nouvel an'],
    [easter + 1, 'Lundi de Pâques'],
    [dayIndex(year, 4, 1), 'Fête du travail'],
    [easter + 39, 'Ascension'],
    [easter + 50, 'Lundi de Pentecôte'],
    [dayIndex(year, 6, 21), 'Fête nationale'],
    [dayIndex(year, 7, 15), 'Assomption'],
    [dayIndex(year, 10, 1), 'Toussaint'],
    [dayIndex(year, 10, 11), 'Armistice'],
    [dayIndex(year, 11, 25), 'Noël'],
  ])
  holidayCache.set(year, holidays)
  return holidays
}

export function holidayName(index: number): string | null {
  return holidaysOfYear(civilOf(index).year).get(index) ?? null
}

/** "12 oct." */
export function formatDayShort(index: number): string {
  const { day, month } = civilOf(index)
  return `${day} ${MONTH_SHORT[month]}`
}

/** "12 octobre" */
export function formatDayLong(index: number): string {
  const { day, month } = civilOf(index)
  return `${day} ${MONTH_NAMES[month].toLowerCase()}`
}

/** "lundi 12 octobre 2026" */
export function formatDayFull(index: number): string {
  const { day, month, year } = civilOf(index)
  return `${WEEKDAY_LONG[weekdayOf(index)]} ${day} ${MONTH_NAMES[month].toLowerCase()} ${year}`
}

export function dayOfMonth(index: number): number {
  return civilOf(index).day
}
