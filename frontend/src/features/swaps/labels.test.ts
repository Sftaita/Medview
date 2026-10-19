import { describe, expect, it } from 'vitest'
import { periodLabel } from '../duties/periodLabel'
import { unitLabel } from './labels'
import type { SwapUnit } from './types'

function unit(overrides: Partial<SwapUnit> = {}): SwapUnit {
  return {
    dutyStableId: 'd1',
    planningStableId: 'p1',
    planningName: 'Trauma Delta',
    lineStableId: 'l1',
    lineName: 'Seniors',
    dutyTypeName: 'Garde',
    blockName: null,
    dates: ['2027-01-05'],
    startsAt: '2027-01-05T08:00:00+01:00',
    endsAt: '2027-01-06T08:00:00+01:00',
    period: { stableId: 'c1', number: 1, count: 1 },
    ...overrides,
  }
}

describe('unitLabel (lots L6/L7)', () => {
  it('names the period once the planning has several — period 1 included (D198)', () => {
    expect(unitLabel(unit())).toBe('Mar. 5 janv. · Garde')
    expect(unitLabel(unit({ period: { stableId: 'c1', number: 1, count: 2 } }))).toBe(
      'Mar. 5 janv. · Garde · Période 1',
    )
    expect(unitLabel(unit({ period: { stableId: 'c2', number: 2, count: 2 } }))).toBe(
      'Mar. 5 janv. · Garde · Période 2',
    )
    expect(unitLabel(unit({ period: null }))).toBe('Mar. 5 janv. · Garde')
  })
})

describe('periodLabel (lot L7)', () => {
  it('says nothing for a single-period planning, the number otherwise', () => {
    expect(periodLabel({ number: 1, count: 1 })).toBeNull()
    expect(periodLabel({ number: 1, count: 3 })).toBe('Période 1')
    expect(periodLabel({ number: 3, count: 3 })).toBe('Période 3')
    expect(periodLabel(null)).toBeNull()
    // An answer without the count (older server): only a later period can be told apart.
    expect(periodLabel({ number: 1 })).toBeNull()
    expect(periodLabel({ number: 2 })).toBe('Période 2')
  })
})
