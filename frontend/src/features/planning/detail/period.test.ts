import { describe, expect, it } from 'vitest'
import {
  daysInclusive,
  formatLongDate,
  formatShortDate,
  lastDayOf,
  monthSegments,
  periodState,
  todayISO,
} from './period'

describe('period', () => {
  it('turns the exclusive API end into the inclusive last day, across months and years', () => {
    expect(lastDayOf('2027-02-01')).toBe('2027-01-31')
    expect(lastDayOf('2027-01-01')).toBe('2026-12-31')
    expect(lastDayOf('2028-03-01')).toBe('2028-02-29')
  })

  it('counts days inclusively', () => {
    expect(daysInclusive('2026-10-01', '2027-01-31')).toBe(123)
    expect(daysInclusive('2026-10-01', '2026-10-01')).toBe(1)
  })

  it('formats dates in fr-BE without shifting the day', () => {
    expect(formatLongDate('2026-10-01')).toMatch(/^Jeu\. 1 oct\. 2026$/)
    expect(formatShortDate('2026-09-30')).toMatch(/^30 sept\.$/)
  })

  it('cuts the period into months and labels the new year', () => {
    const segs = monthSegments('2026-10-15', '2027-01-31')
    expect(segs.map((s) => s.days)).toEqual([17, 30, 31, 31])
    expect(segs.map((s) => s.label)).toEqual(['Oct.', 'Nov.', 'Déc.', 'Janv. 2027'])
    expect(segs.reduce((a, s) => a + s.days, 0)).toBe(daysInclusive('2026-10-15', '2027-01-31'))
  })

  it('says whether the period is upcoming, running or past', () => {
    expect(periodState('2026-10-01', '2026-10-31', '2026-09-30').label).toBe('Commence demain')
    expect(periodState('2026-10-01', '2026-10-31', '2026-09-25').label).toBe('Commence dans 6 jours')
    const running = periodState('2026-10-01', '2026-10-31', '2026-10-01')
    expect(running).toMatchObject({ kind: 'running', label: 'En cours · jour 1 sur 31' })
    expect(periodState('2026-10-01', '2026-10-31', '2026-10-31').label).toBe('En cours · jour 31 sur 31')
    expect(periodState('2026-10-01', '2026-10-31', '2026-11-01')).toEqual({
      kind: 'past',
      label: 'Période terminée',
    })
  })

  it('takes today in the planning timezone, not UTC', () => {
    // 23:30 UTC on 30 Sept. is already 1 Oct. in Brussels.
    expect(todayISO('Europe/Brussels', new Date('2026-09-30T23:30:00Z'))).toBe('2026-10-01')
    expect(todayISO('UTC', new Date('2026-09-30T23:30:00Z'))).toBe('2026-09-30')
  })
})
