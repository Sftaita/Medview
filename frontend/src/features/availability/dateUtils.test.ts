import { describe, expect, it } from 'vitest'
import { groupContiguousDateKeys, parseDateKey, startOfNextDay, toDateKey } from './dateUtils'

describe('toDateKey / parseDateKey', () => {
  it('round-trips a local date without any UTC shift', () => {
    const date = new Date(2026, 10, 5) // November 5th, 2026 (local)
    expect(toDateKey(date)).toBe('2026-11-05')
    expect(parseDateKey('2026-11-05')).toEqual(date)
  })
})

describe('groupContiguousDateKeys', () => {
  it('keeps a single day as its own run', () => {
    expect(groupContiguousDateKeys(['2026-11-10'])).toEqual([['2026-11-10']])
  })

  it('merges consecutive days into one run', () => {
    expect(groupContiguousDateKeys(['2026-11-10', '2026-11-11', '2026-11-12'])).toEqual([
      ['2026-11-10', '2026-11-11', '2026-11-12'],
    ])
  })

  it('splits non-consecutive days into separate runs', () => {
    expect(groupContiguousDateKeys(['2026-11-10', '2026-11-20'])).toEqual([['2026-11-10'], ['2026-11-20']])
  })

  it('sorts unordered input before grouping', () => {
    expect(groupContiguousDateKeys(['2026-11-12', '2026-11-10', '2026-11-11'])).toEqual([
      ['2026-11-10', '2026-11-11', '2026-11-12'],
    ])
  })

  it('handles a month boundary correctly', () => {
    expect(groupContiguousDateKeys(['2026-11-30', '2026-12-01'])).toEqual([['2026-11-30', '2026-12-01']])
  })
})

describe('startOfNextDay', () => {
  it('returns local midnight of the following day', () => {
    expect(startOfNextDay('2026-11-10')).toEqual(new Date(2026, 10, 11))
  })
})
