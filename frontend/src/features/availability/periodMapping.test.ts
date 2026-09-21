import { describe, expect, it } from 'vitest'
import { dayIndex } from './calendarAxis'
import { periodToRange, periodsToRanges, planSync, rangeToInput } from './periodMapping'
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

describe('planSync', () => {
  const stored = [
    period('u1', 'UNAVAILABLE', [2026, 9, 12], [2026, 9, 15]),
    period('p1', 'PREFER_DUTY', [2026, 9, 6], [2026, 9, 8]),
  ]
  const none = { toDelete: [], toUpdate: [], toCreate: [] }

  it('does nothing when the screen equals the stored data', () => {
    expect(planSync(stored, periodsToRanges(stored))).toEqual(none)
  })

  it('creates a new run and leaves untouched periods alone', () => {
    const screen = [
      ...periodsToRanges(stored),
      { start: d(9, 20), end: d(9, 22), type: 'UNAVAILABLE' as const },
    ]
    const plan = planSync(stored, screen)
    expect(plan.toDelete).toEqual([])
    expect(plan.toUpdate).toEqual([])
    expect(plan.toCreate).toEqual([{ start: d(9, 20), end: d(9, 22), type: 'UNAVAILABLE' }])
  })

  it('deletes a stored period removed from the screen', () => {
    const plan = planSync(stored, [{ start: d(9, 6), end: d(9, 7), type: 'PREFER_DUTY' }])
    expect(plan.toDelete.map((p) => p.stableId)).toEqual(['u1'])
    expect(plan.toUpdate).toEqual([])
    expect(plan.toCreate).toEqual([])
  })

  it('edits a stored period in place when a run is extended (one PATCH, same identifier)', () => {
    const screen: DayRange[] = [
      { start: d(9, 12), end: d(9, 16), type: 'UNAVAILABLE' },
      { start: d(9, 6), end: d(9, 7), type: 'PREFER_DUTY' },
    ]
    const plan = planSync(stored, screen)
    expect(plan.toDelete).toEqual([])
    expect(plan.toCreate).toEqual([])
    expect(plan.toUpdate.map((u) => [u.period.stableId, u.range])).toEqual([
      ['u1', { start: d(9, 12), end: d(9, 16), type: 'UNAVAILABLE' }],
    ])
  })

  it('edits a stored period in place when a run is shortened', () => {
    const plan = planSync(stored, [
      { start: d(9, 12), end: d(9, 13), type: 'UNAVAILABLE' },
      { start: d(9, 6), end: d(9, 7), type: 'PREFER_DUTY' },
    ])
    expect(plan.toUpdate.map((u) => u.period.stableId)).toEqual(['u1'])
    expect(plan.toDelete).toEqual([])
    expect(plan.toCreate).toEqual([])
  })

  it('splits a run in two: the first half is edited, the second one is created', () => {
    const plan = planSync(stored, [
      { start: d(9, 6), end: d(9, 7), type: 'PREFER_DUTY' },
      { start: d(9, 12), end: d(9, 12), type: 'UNAVAILABLE' },
      { start: d(9, 14), end: d(9, 14), type: 'UNAVAILABLE' },
    ])
    expect(plan.toUpdate.map((u) => [u.period.stableId, u.range.start, u.range.end])).toEqual([
      ['u1', d(9, 12), d(9, 12)],
    ])
    expect(plan.toCreate).toEqual([{ start: d(9, 14), end: d(9, 14), type: 'UNAVAILABLE' }])
    expect(plan.toDelete).toEqual([])
  })

  it('merges two stored runs bridged on screen: one is edited, the other deleted', () => {
    const two = [
      period('a', 'UNAVAILABLE', [2026, 9, 1], [2026, 9, 4]),
      period('b', 'UNAVAILABLE', [2026, 9, 6], [2026, 9, 9]),
    ]
    const plan = planSync(two, [{ start: d(9, 1), end: d(9, 8), type: 'UNAVAILABLE' }])
    expect(plan.toUpdate).toHaveLength(1)
    expect(plan.toDelete).toHaveLength(1)
    expect(plan.toCreate).toEqual([])
    // Deleting the leftover happens before the edit, so the edited period never touches it.
    expect([plan.toUpdate[0].period.stableId, plan.toDelete[0].stableId].sort()).toEqual(['a', 'b'])
  })

  it('orders edits so that shrinking always comes before growing', () => {
    const two = [
      period('a', 'UNAVAILABLE', [2026, 9, 1], [2026, 9, 6]), // 1–5
      period('b', 'UNAVAILABLE', [2026, 9, 10], [2026, 9, 13]), // 10–12
    ]
    const plan = planSync(two, [
      { start: d(9, 1), end: d(9, 9), type: 'UNAVAILABLE' }, // a grows into 6–9
      { start: d(9, 11), end: d(9, 12), type: 'UNAVAILABLE' }, // b shrinks
    ])
    expect(plan.toUpdate.map((u) => u.period.stableId)).toEqual(['b', 'a'])
  })

  it('treats a day converted to the other nature as an edit of the run it was cut from plus a create', () => {
    const screen: DayRange[] = [
      { start: d(9, 12), end: d(9, 14), type: 'PREFER_DUTY' },
      { start: d(9, 6), end: d(9, 7), type: 'PREFER_DUTY' },
    ]
    const plan = planSync(stored, screen)
    // The stored UNAVAILABLE run has no run of its own nature left on screen…
    expect(plan.toDelete.map((p) => p.stableId)).toEqual(['u1'])
    // …and the preference that now covers those days is a new one.
    expect(plan.toCreate).toEqual([{ start: d(9, 12), end: d(9, 14), type: 'PREFER_DUTY' }])
    expect(plan.toUpdate).toEqual([])
  })

  it('never turns a stored period of one nature into the other nature', () => {
    const plan = planSync(stored, [{ start: d(9, 12), end: d(9, 14), type: 'PREFER_DUTY' }])
    expect(plan.toUpdate.every((u) => u.period.type === u.range.type)).toBe(true)
  })

  it('leaves a legacy period with a time of day untouched when its days are unchanged', () => {
    const legacy = [period('t1', 'UNAVAILABLE', [2026, 9, 12, 8], [2026, 9, 12, 18])]
    expect(planSync(legacy, periodsToRanges(legacy))).toEqual(none)
  })
})
