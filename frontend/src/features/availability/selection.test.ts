import { describe, expect, it } from 'vitest'
import {
  addRange,
  countDays,
  cutRange,
  mergeRanges,
  rangeAt,
  sameRanges,
  toggleDay,
  type DayRange,
} from './selection'

const U = 'UNAVAILABLE' as const
const P = 'PREFER_DUTY' as const
const r = (start: number, end: number, type: DayRange['type'] = U): DayRange => ({ start, end, type })

describe('mergeRanges', () => {
  it('merges adjacent ranges of the same type', () => {
    expect(mergeRanges([r(5, 7), r(8, 9)])).toEqual([r(5, 9)])
  })

  it('merges overlapping ranges of the same type', () => {
    expect(mergeRanges([r(5, 9), r(7, 12)])).toEqual([r(5, 12)])
  })

  it('keeps adjacent ranges of different types apart', () => {
    expect(mergeRanges([r(5, 7, U), r(8, 9, P)])).toEqual([r(5, 7, U), r(8, 9, P)])
  })

  it('does not let a range of the other type in between prevent a merge', () => {
    // U 1-3, P 2-2 (overlapping data), U 4-5 → the two U runs still join.
    expect(mergeRanges([r(1, 3, U), r(2, 2, P), r(4, 5, U)])).toEqual([r(1, 5, U), r(2, 2, P)])
  })

  it('sorts by start and is idempotent', () => {
    const once = mergeRanges([r(20, 22), r(1, 2), r(10, 11)])
    expect(once).toEqual([r(1, 2), r(10, 11), r(20, 22)])
    expect(mergeRanges(once)).toEqual(once)
  })

  it('does not mutate its input', () => {
    const input = [r(5, 7), r(8, 9)]
    mergeRanges(input)
    expect(input).toEqual([r(5, 7), r(8, 9)])
  })
})

describe('cutRange', () => {
  it('splits a range when a day in the middle is removed', () => {
    expect(cutRange([r(5, 9)], 7, 7)).toEqual([r(5, 6), r(8, 9)])
  })

  it('trims the ends and drops fully covered ranges', () => {
    expect(cutRange([r(1, 3), r(5, 9), r(12, 14)], 3, 12)).toEqual([r(1, 2), r(13, 14)])
  })

  it('leaves untouched ranges as they are', () => {
    expect(cutRange([r(1, 3)], 10, 12)).toEqual([r(1, 3)])
  })
})

describe('addRange', () => {
  it('accepts its bounds in any order (dragging backwards)', () => {
    expect(addRange([], 9, 5, U)).toEqual([r(5, 9)])
  })

  it('lets the new range win over the old one on the shared days', () => {
    expect(addRange([r(5, 9, U)], 7, 8, P)).toEqual([r(5, 6, U), r(7, 8, P), r(9, 9, U)])
  })

  it('adds a period without replacing the existing selection', () => {
    expect(addRange([r(1, 3)], 10, 12, U)).toEqual([r(1, 3), r(10, 12)])
  })

  it('joins the new days to a neighbouring period of the same type', () => {
    expect(addRange([r(1, 3)], 4, 6, U)).toEqual([r(1, 6)])
  })

  it('keeps a period crossing a month or year boundary as a single range', () => {
    // Day indexes are absolute: crossing 31 Dec → 1 Jan is no special case.
    expect(addRange([], 20_818, 20_822, U)).toEqual([r(20_818, 20_822)])
  })

  it('includes weekend days and public holidays like any other day', () => {
    expect(addRange([], 100, 108, U)).toEqual([r(100, 108)])
  })
})

describe('toggleDay', () => {
  it('adds a day that is not selected', () => {
    expect(toggleDay([], 4, U)).toEqual([r(4, 4)])
  })

  it('removes a day already selected with the same nature', () => {
    expect(toggleDay([r(3, 5, U)], 4, U)).toEqual([r(3, 3), r(5, 5)])
  })

  it('converts a day selected with the other nature instead of removing it', () => {
    expect(toggleDay([r(3, 5, P)], 4, U)).toEqual([r(3, 3, P), r(4, 4, U), r(5, 5, P)])
  })
})

describe('helpers', () => {
  it('finds the range covering a day', () => {
    expect(rangeAt([r(1, 3), r(7, 9)], 8)).toEqual(r(7, 9))
    expect(rangeAt([r(1, 3)], 5)).toBeNull()
  })

  it('counts days across ranges', () => {
    expect(countDays([r(1, 3), r(10, 10)])).toBe(4)
  })

  it('compares selections regardless of how they are split', () => {
    expect(sameRanges([r(1, 2), r(3, 4)], [r(1, 4)])).toBe(true)
    expect(sameRanges([r(1, 4, U)], [r(1, 4, P)])).toBe(false)
    expect(sameRanges([r(1, 4)], [r(1, 5)])).toBe(false)
  })
})
