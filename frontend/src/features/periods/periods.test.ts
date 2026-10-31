import { describe, expect, it } from 'vitest'
import { addDays, daysInclusive, endAfterMonths, formatDate, formatRange, timeState } from './dates'
import {
  buildNextPeriod,
  currentAndNext,
  currentIndex,
  friseWindow,
  nextPeriodStart,
  validateEnd,
} from './periods'
import { demoPeriods } from './mock'
import type { Period } from './types'

const period = (number: number, start: string, end: string): Period => ({
  id: `p${number}`,
  number,
  start,
  end,
  status: 'publiee',
  collecte: { state: 'cloturee' },
})

describe('dates', () => {
  it('counts days with both bounds included, across months and years', () => {
    expect(daysInclusive('2026-10-01', '2027-01-30')).toBe(122)
    expect(daysInclusive('2026-10-10', '2026-10-10')).toBe(1)
    // Changement d'heure (25 oct. 2026) : les dates sans fuseau ne se décalent pas.
    expect(addDays('2026-10-24', 2)).toBe('2026-10-26')
  })

  it('ends a k-month duration the day before the same date k months later', () => {
    expect(endAfterMonths('2027-05-01', 4)).toBe('2027-08-31')
    expect(endAfterMonths('2027-01-31', 3)).toBe('2027-04-30')
  })

  it('formats French dates and drops the first year only within the same year', () => {
    expect(formatDate('2026-10-01', 'card')).toBe('Jeu. 1 oct. 2026')
    expect(formatDate('2027-01-30', 'long')).toBe('samedi 30 janvier 2027')
    expect(formatRange('2026-10-01', '2027-01-30')).toBe('1 oct. 2026 → 30 janv. 2027')
    expect(formatRange('2027-01-31', '2027-04-30')).toBe('31 janv. → 30 avr. 2027')
  })

  it('places a period in time independently of its status', () => {
    const range = { start: '2026-10-01', end: '2027-01-30' }
    expect(timeState(range, '2026-10-10')).toMatchObject({
      kind: 'current',
      dayIndex: 10,
      days: 122,
      label: 'En cours · jour 10 sur 122',
    })
    expect(timeState(range, '2026-10-01').elapsedPct).toBe(0)
    expect(timeState(range, '2027-01-30')).toMatchObject({ kind: 'current', dayIndex: 122 })
    expect(timeState(range, '2027-01-31')).toMatchObject({
      kind: 'past',
      elapsedPct: 100,
      label: 'Terminée le 30 janv. 2027',
    })
    expect(timeState(range, '2026-09-28')).toMatchObject({
      kind: 'future',
      daysUntil: 3,
      label: 'Commence dans 3 jours',
    })
  })
})

describe('periods', () => {
  const periods = demoPeriods('8')

  it('finds the period containing today, else the first future one, else the last', () => {
    expect(periods[currentIndex(periods, '2026-10-10')].number).toBe(7)
    const gap = [period(1, '2026-01-01', '2026-01-31'), period(2, '2026-03-01', '2026-03-31')]
    expect(currentIndex(gap, '2026-02-10')).toBe(1)
    expect(currentIndex(gap, '2027-01-01')).toBe(1)
  })

  it('shows only the current and the next period on the planning card', () => {
    const { current, next } = currentAndNext(periods, '2026-10-10')
    expect([current?.number, next?.number]).toEqual([7, 8])
    expect(currentAndNext(demoPeriods('1'), '2026-10-10').next).toBeUndefined()
    expect(currentAndNext([], '2026-10-10')).toEqual({})
  })

  it('keeps up to 4 periods visible, then moves those before the previous one to the history', () => {
    expect(friseWindow(demoPeriods('4'), '2026-10-10')).toEqual({ visible: demoPeriods('4'), history: [] })
    const { visible, history } = friseWindow(periods, '2026-10-10')
    expect(visible.map((p) => p.number)).toEqual([6, 7, 8])
    expect(history.map((p) => p.number)).toEqual([1, 2, 3, 4, 5])
  })

  it('starts the next period the day after the last one and requires at least 7 days', () => {
    expect(nextPeriodStart(periods)).toBe('2027-05-01')
    expect(validateEnd('2027-05-01', '')).toEqual({ ok: false })
    expect(validateEnd('2027-05-01', '2027-05-06')).toEqual({
      ok: false,
      error: 'La fin doit être au moins 7 jours après le début.',
    })
    expect(validateEnd('2027-05-01', '2027-05-07')).toEqual({ ok: true })
  })

  it('builds the next period in preparation without touching the existing ones', () => {
    const before = JSON.stringify(periods)
    const next = buildNextPeriod(periods, '2027-08-31', 'new')
    expect(next).toEqual({
      id: 'new',
      number: 9,
      start: '2027-05-01',
      end: '2027-08-31',
      status: 'preparation',
      collecte: { state: 'non-ouverte' },
    })
    expect(JSON.stringify(periods)).toBe(before)
  })
})
