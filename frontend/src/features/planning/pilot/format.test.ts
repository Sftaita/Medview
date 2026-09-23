import { describe, expect, it } from 'vitest'
import {
  formatDateOnly,
  formatDateTime,
  formatLongDate,
  formatPeriod,
  formatRange,
  overdueMessage,
  plural,
} from './format'

const BRUSSELS = 'Europe/Brussels'

// Midnight in Brussels in winter is 23:00 UTC of the day before: the helpers must read the wall clock in the planning's zone.
describe('formatPeriod', () => {
  it('reads a multi-day period inside one month as "3–7 janvier"', () => {
    expect(formatPeriod('2027-01-02T23:00:00+00:00', '2027-01-07T23:00:00+00:00', BRUSSELS, 2027)).toBe(
      '3–7 janvier',
    )
  })

  it('reads a single day as "21 janvier"', () => {
    expect(formatPeriod('2027-01-20T23:00:00+00:00', '2027-01-21T23:00:00+00:00', BRUSSELS, 2027)).toBe(
      '21 janvier',
    )
  })

  it('reads a period across two months with both months', () => {
    expect(formatPeriod('2027-01-27T23:00:00+00:00', '2027-02-02T23:00:00+00:00', BRUSSELS, 2027)).toBe(
      '28 janvier → 2 février',
    )
  })

  it('reads a period that is not whole days with its times', () => {
    expect(formatPeriod('2027-02-14T07:00:00+00:00', '2027-02-15T11:00:00+00:00', BRUSSELS, 2027)).toBe(
      '14 février 08:00 → 15 février 12:00',
    )
  })

  it("adds the year only when it differs from the planning's", () => {
    expect(formatPeriod('2026-12-28T23:00:00+00:00', '2026-12-29T23:00:00+00:00', BRUSSELS, 2027)).toBe(
      '29 décembre 2026',
    )
    expect(formatPeriod('2026-12-28T23:00:00+00:00', '2027-01-03T23:00:00+00:00', BRUSSELS, 2027)).toBe(
      '29 décembre 2026 → 3 janvier 2027',
    )
  })

  it('follows the daylight-saving change (summer midnight is 22:00 UTC)', () => {
    expect(formatPeriod('2027-06-09T22:00:00+00:00', '2027-06-11T22:00:00+00:00', BRUSSELS, 2027)).toBe(
      '10–11 juin',
    )
  })
})

describe('dates', () => {
  it('formats calendar dates without going through the timezone', () => {
    expect(formatLongDate('2026-09-25')).toBe('25 septembre 2026')
    expect(formatRange('2027-01-01', '2027-03-31')).toBe('1 janvier 2027 → 31 mars 2027')
  })

  it('formats an instant in the given timezone', () => {
    expect(formatDateTime('2026-09-21T08:14:00+00:00', BRUSSELS)).toBe('21/09/2026 à 10:14')
    expect(formatDateOnly('2026-09-21T22:30:00+00:00', BRUSSELS)).toBe('22/09/2026')
  })
})

describe('wording', () => {
  it('says how long the informative deadline has been passed', () => {
    expect(overdueMessage(2)).toBe('Date souhaitée dépassée depuis 2 jours.')
    expect(overdueMessage(1)).toBe('Date souhaitée dépassée depuis 1 jour.')
  })

  it('pluralizes', () => {
    expect(plural(1, 'membre')).toBe('1 membre')
    expect(plural(3, 'membre')).toBe('3 membres')
  })
})
