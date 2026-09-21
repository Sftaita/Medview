import { describe, expect, it } from 'vitest'
import { dayIndex } from './calendarAxis'
import {
  collectionDays,
  formatDateFull,
  formatDeadline,
  formatMoment,
  formatWindow,
  formatWindowShort,
} from './collectionFormat'

describe('formatWindow', () => {
  it('writes whole months of one year as a range', () => {
    expect(formatWindow('2027-01-01', '2027-03-31')).toBe('janvier–mars 2027')
  })

  it('writes a single month once', () => {
    expect(formatWindow('2026-09-01', '2026-09-30')).toBe('septembre 2026')
  })

  it('keeps both years when the window crosses New Year', () => {
    expect(formatWindow('2026-11-01', '2027-02-28')).toBe('novembre 2026–février 2027')
  })

  it('handles a leap-year February as a whole month', () => {
    expect(formatWindow('2028-02-01', '2028-02-29')).toBe('février 2028')
  })

  it('falls back to exact days when the window does not follow whole months', () => {
    expect(formatWindow('2027-01-15', '2027-03-20')).toBe('du 15 janvier au 20 mars 2027')
    expect(formatWindow('2026-12-20', '2027-01-10')).toBe('du 20 décembre 2026 au 10 janvier 2027')
  })

  it('capitalizes for a history row', () => {
    expect(formatWindowShort('2026-09-01', '2026-12-31')).toBe('Septembre–décembre 2026')
  })
})

describe('formatDeadline', () => {
  it('omits the year when it is the current one', () => {
    expect(formatDeadline('2026-12-20', new Date(2026, 11, 14))).toBe('20 décembre')
  })

  it('states the year otherwise', () => {
    expect(formatDeadline('2027-01-05', new Date(2026, 11, 14))).toBe('5 janvier 2027')
  })
})

describe('dates without timezone drift', () => {
  it('formats a calendar date exactly as written', () => {
    expect(formatDateFull('2026-12-20')).toBe('20/12/2026')
  })

  it('formats an instant as day/month, with the year only when it is not the current one', () => {
    const now = new Date(2026, 11, 20)
    expect(formatMoment(new Date(2026, 11, 14, 12).toISOString(), now)).toBe('14/12')
    expect(formatMoment(new Date(2025, 11, 14, 12).toISOString(), now)).toBe('14/12/2025')
  })
})

describe('collectionDays', () => {
  it('maps the window to inclusive absolute days for the calendar', () => {
    expect(collectionDays({ startsAt: '2027-01-01', lastDay: '2027-03-31' })).toEqual({
      start: dayIndex(2027, 0, 1),
      end: dayIndex(2027, 2, 31),
    })
  })
})
