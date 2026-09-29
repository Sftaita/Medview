import { describe, expect, it } from 'vitest'
import { makeMyDuty } from '../../testUtils/myDuty'
import {
  dutyWhat,
  formatDutyDates,
  groupByMonth,
  isUpcoming,
  reinforcementLabel,
  splitDuties,
} from './dutyDates'

const TODAY = new Date(2026, 9, 7) // Wednesday 7 October 2026

describe('duty dates', () => {
  it('labels a single day and a block by its first and last day', () => {
    expect(formatDutyDates(makeMyDuty(['2026-10-13']))).toBe('Mar. 13 oct.')
    expect(formatDutyDates(makeMyDuty(['2026-10-17', '2026-10-18']))).toBe('Sam. 17 → dim. 18 oct.')
    expect(formatDutyDates(makeMyDuty(['2026-10-31', '2026-11-01']))).toBe('Sam. 31 oct. → dim. 1 nov.')
  })

  it('keeps a unit upcoming until its last day is over — a block under way included', () => {
    expect(isUpcoming(makeMyDuty(['2026-10-07']), TODAY)).toBe(true)
    expect(isUpcoming(makeMyDuty(['2026-10-06', '2026-10-07']), TODAY)).toBe(true)
    expect(isUpcoming(makeMyDuty(['2026-10-06']), TODAY)).toBe(false)
  })

  it('splits upcoming (soonest first) from past (latest first)', () => {
    const a = makeMyDuty(['2026-09-01'])
    const b = makeMyDuty(['2026-10-01'])
    const c = makeMyDuty(['2026-10-08'])
    const d = makeMyDuty(['2026-11-02'])
    const { upcoming, past } = splitDuties([a, b, c, d], TODAY)
    expect(upcoming).toEqual([c, d])
    expect(past).toEqual([b, a])
  })

  it('groups consecutive units by the month they start in', () => {
    const groups = groupByMonth([
      makeMyDuty(['2026-10-08']),
      makeMyDuty(['2026-10-31', '2026-11-01']),
      makeMyDuty(['2026-11-02']),
      makeMyDuty(['2027-01-05']),
    ])
    expect(groups.map((g) => [g.label, g.duties.length])).toEqual([
      ['Octobre 2026', 2],
      ['Novembre 2026', 1],
      ['Janvier 2027', 1],
    ])
  })

  it('names a block, otherwise the duty type', () => {
    expect(dutyWhat(makeMyDuty(['2026-10-17'], { blockName: 'Week-end' }))).toBe('Bloc Week-end')
    expect(dutyWhat(makeMyDuty(['2026-10-13'], { dutyTypeName: 'Nuit' }))).toBe('Nuit')
  })

  it('flags reinforcements with their live state, never an ordinary duty', () => {
    const renfort = (coverageState: 'REQUIRED_ASSIGNED' | 'NOT_REQUIRED_ASSIGNED' | 'UNDETERMINED') =>
      reinforcementLabel(makeMyDuty(['2026-10-13'], { conditional: true, coverageState }))
    expect(reinforcementLabel(makeMyDuty(['2026-10-13']))).toBeNull()
    expect(renfort('REQUIRED_ASSIGNED')).toEqual({ label: 'Renfort', tone: 'info' })
    expect(renfort('NOT_REQUIRED_ASSIGNED')).toEqual({
      label: 'Renfort non requis actuellement',
      tone: 'warn',
    })
    expect(renfort('UNDETERMINED')).toEqual({ label: 'Renfort à confirmer', tone: 'warn' })
  })
})
