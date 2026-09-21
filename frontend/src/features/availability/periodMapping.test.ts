import { describe, expect, it } from 'vitest'
import { dayIndex } from './calendarAxis'
import { periodToRange, periodsToRanges, planSave, rangeToInput } from './periodMapping'
import type { DayRange } from './selection'
import type { UserAvailabilityPeriod, UserAvailabilityType } from './types'

/** A stored period from local date-times, like the API returns instants. */
function period(
  id: string,
  type: UserAvailabilityType,
  from: [number, number, number, number?],
  to: [number, number, number, number?],
): UserAvailabilityPeriod {
  return {
    stableId: id,
    type,
    startsAt: new Date(from[0], from[1], from[2], from[3] ?? 0).toISOString(),
    endsAt: new Date(to[0], to[1], to[2], to[3] ?? 0).toISOString(),
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
  }
}

const d = (month: number, day: number, year = 2026) => dayIndex(year, month, day)

describe('periodToRange', () => {
  it('maps a half-open whole-day period to inclusive days', () => {
    // [12 Oct 00:00, 15 Oct 00:00[ → 12, 13, 14
    expect(periodToRange(period('a', 'UNAVAILABLE', [2026, 9, 12], [2026, 9, 15]))).toEqual({
      start: d(9, 12),
      end: d(9, 14),
      type: 'UNAVAILABLE',
    })
  })

  it('widens a legacy period with a time of day to the days it touches', () => {
    expect(periodToRange(period('a', 'PREFER_DUTY', [2026, 9, 12, 14], [2026, 9, 12, 18]))).toEqual({
      start: d(9, 12),
      end: d(9, 12),
      type: 'PREFER_DUTY',
    })
  })

  it('covers a period across a month and a year boundary', () => {
    const range = periodToRange(period('a', 'UNAVAILABLE', [2026, 11, 30], [2027, 0, 3]))
    expect(range.end - range.start).toBe(3)
  })
})

describe('periodsToRanges', () => {
  it('lets an unavailability win over an overlapping preference', () => {
    const ranges = periodsToRanges([
      period('u', 'UNAVAILABLE', [2026, 9, 12], [2026, 9, 15]),
      period('p', 'PREFER_DUTY', [2026, 9, 10], [2026, 9, 20]),
    ])
    expect(ranges).toEqual([
      { start: d(9, 10), end: d(9, 11), type: 'PREFER_DUTY' },
      { start: d(9, 12), end: d(9, 14), type: 'UNAVAILABLE' },
      { start: d(9, 15), end: d(9, 19), type: 'PREFER_DUTY' },
    ])
  })
})

describe('rangeToInput', () => {
  it('sends local midnight of the first day up to local midnight after the last', () => {
    const input = rangeToInput({ start: d(9, 15), end: d(9, 15), type: 'UNAVAILABLE' })
    expect(input.type).toBe('UNAVAILABLE')
    expect(new Date(input.startsAt)).toEqual(new Date(2026, 9, 15))
    expect(new Date(input.endsAt)).toEqual(new Date(2026, 9, 16))
  })

  it('round-trips with periodToRange', () => {
    const range: DayRange = { start: d(11, 30), end: d(0, 2, 2027), type: 'PREFER_DUTY' }
    const input = rangeToInput(range)
    expect(periodToRange({ ...period('x', 'PREFER_DUTY', [2026, 0, 1], [2026, 0, 2]), ...input })).toEqual(
      range,
    )
  })
})

describe('planSave', () => {
  const stored = [
    period('u1', 'UNAVAILABLE', [2026, 9, 12], [2026, 9, 15]),
    period('p1', 'PREFER_DUTY', [2026, 9, 6], [2026, 9, 8]),
  ]

  it('does nothing when the screen equals the stored data', () => {
    expect(planSave(stored, periodsToRanges(stored))).toEqual({ toDelete: [], toCreate: [] })
  })

  it('creates a new run and leaves untouched periods alone', () => {
    const screen = [
      ...periodsToRanges(stored),
      { start: d(9, 20), end: d(9, 22), type: 'UNAVAILABLE' as const },
    ]
    const plan = planSave(stored, screen)
    expect(plan.toDelete).toEqual([])
    expect(plan.toCreate).toEqual([{ start: d(9, 20), end: d(9, 22), type: 'UNAVAILABLE' }])
  })

  it('deletes a stored period removed from the screen', () => {
    const plan = planSave(stored, [{ start: d(9, 6), end: d(9, 7), type: 'PREFER_DUTY' }])
    expect(plan.toDelete.map((p) => p.stableId)).toEqual(['u1'])
    expect(plan.toCreate).toEqual([])
  })

  it('replaces a stored period whose days changed (delete + create)', () => {
    const screen: DayRange[] = [
      { start: d(9, 12), end: d(9, 16), type: 'UNAVAILABLE' },
      { start: d(9, 6), end: d(9, 7), type: 'PREFER_DUTY' },
    ]
    const plan = planSave(stored, screen)
    expect(plan.toDelete.map((p) => p.stableId)).toEqual(['u1'])
    expect(plan.toCreate).toEqual([{ start: d(9, 12), end: d(9, 16), type: 'UNAVAILABLE' }])
  })

  it('treats a day converted to the other nature as a delete and a create', () => {
    const screen: DayRange[] = [
      { start: d(9, 12), end: d(9, 14), type: 'PREFER_DUTY' },
      { start: d(9, 6), end: d(9, 7), type: 'PREFER_DUTY' },
    ]
    const plan = planSave(stored, screen)
    expect(plan.toDelete.map((p) => p.stableId)).toEqual(['u1'])
    expect(plan.toCreate).toEqual([{ start: d(9, 12), end: d(9, 14), type: 'PREFER_DUTY' }])
  })

  it('leaves a legacy period with a time of day untouched when its days are unchanged', () => {
    const legacy = [period('t1', 'UNAVAILABLE', [2026, 9, 12, 8], [2026, 9, 12, 18])]
    expect(planSave(legacy, periodsToRanges(legacy))).toEqual({ toDelete: [], toCreate: [] })
  })
})
