import { describe, expect, it } from 'vitest'
import {
  buildMonths,
  dateOfDayIndex,
  dayIndex,
  dayIndexOfDate,
  formatDayFull,
  formatDayShort,
  holidayName,
  isWeekend,
  monthIndexOfDay,
  monthInfo,
  weekdayOf,
} from './calendarAxis'

describe('day axis', () => {
  it('numbers consecutive days consecutively across months and years', () => {
    expect(dayIndex(2026, 9, 1) - dayIndex(2026, 8, 30)).toBe(1)
    expect(dayIndex(2027, 0, 1) - dayIndex(2026, 11, 31)).toBe(1)
  })

  it('round-trips between a local Date and a day index', () => {
    const date = new Date(2026, 9, 12, 15, 30)
    const index = dayIndexOfDate(date)
    expect(dateOfDayIndex(index)).toEqual(new Date(2026, 9, 12))
  })

  it('is not shifted by a daylight-saving change', () => {
    // Belgium switches on 2026-03-29 and 2026-10-25.
    expect(dayIndex(2026, 2, 30) - dayIndex(2026, 2, 28)).toBe(2)
    expect(dateOfDayIndex(dayIndex(2026, 9, 26))).toEqual(new Date(2026, 9, 26))
  })

  it('knows the weekday, Monday first', () => {
    expect(weekdayOf(dayIndex(2026, 9, 12))).toBe(0) // Monday 12 October 2026
    expect(weekdayOf(dayIndex(2026, 9, 17))).toBe(5) // Saturday
    expect(isWeekend(dayIndex(2026, 9, 18))).toBe(true) // Sunday
    expect(isWeekend(dayIndex(2026, 9, 16))).toBe(false)
  })
})

describe('months', () => {
  it('computes length and Monday-first offset of a month', () => {
    const october = monthInfo(2026, 9)
    expect(october.days).toBe(31)
    expect(october.offset).toBe(3) // 1 October 2026 is a Thursday
    expect(monthInfo(2028, 1).days).toBe(29) // leap year
  })

  it('builds consecutive months, rolling over the year', () => {
    const months = buildMonths(2026, 10, 4)
    expect(months.map((m) => m.name)).toEqual([
      'Novembre 2026',
      'Décembre 2026',
      'Janvier 2027',
      'Février 2027',
    ])
    expect(months[1].start + months[1].days).toBe(months[2].start)
  })

  it('finds the month containing a day', () => {
    const months = buildMonths(2026, 8, 3)
    expect(monthIndexOfDay(months, dayIndex(2026, 9, 5))).toBe(1)
    expect(monthIndexOfDay(months, dayIndex(2027, 5, 5))).toBe(-1)
  })
})

describe('Belgian public holidays', () => {
  it('has the fixed ones', () => {
    expect(holidayName(dayIndex(2026, 10, 1))).toBe('Toussaint')
    expect(holidayName(dayIndex(2026, 11, 25))).toBe('Noël')
    expect(holidayName(dayIndex(2027, 0, 1))).toBe('Nouvel an')
    expect(holidayName(dayIndex(2027, 6, 21))).toBe('Fête nationale')
  })

  it('computes the ones that follow Easter', () => {
    // Easter 2027 is on 28 March; 2026 on 5 April.
    expect(holidayName(dayIndex(2026, 3, 6))).toBe('Lundi de Pâques')
    expect(holidayName(dayIndex(2027, 2, 29))).toBe('Lundi de Pâques')
    expect(holidayName(dayIndex(2027, 4, 6))).toBe('Ascension')
    expect(holidayName(dayIndex(2027, 4, 17))).toBe('Lundi de Pentecôte')
  })

  it('returns null on an ordinary day', () => {
    expect(holidayName(dayIndex(2026, 9, 14))).toBeNull()
  })
})

describe('labels', () => {
  it('formats days in French', () => {
    expect(formatDayShort(dayIndex(2026, 8, 5))).toBe('5 sept.')
    expect(formatDayFull(dayIndex(2026, 9, 12))).toBe('lundi 12 octobre 2026')
  })
})
