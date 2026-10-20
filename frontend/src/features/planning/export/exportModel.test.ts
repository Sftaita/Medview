import { describe, expect, it } from 'vitest'
import type { PlanningDetail, PlanningLineSummary } from '../types'
import {
  initialExportState,
  isExportableLine,
  moveLine,
  toExportRequest,
  validateExport,
} from './exportModel'

const PLANNING = {
  stableId: 'plan-1',
  name: 'Gardes',
  startsAt: '2026-10-01',
  endsAt: '2027-01-01',
  lines: [
    { stableId: 'b', name: 'B', position: 1, active: true },
    { stableId: 'a', name: 'A', position: 0, active: true },
    { stableId: 'z', name: 'Z', position: 2, active: false },
  ],
} as unknown as PlanningDetail

describe('isExportableLine (docs/decisions.md D200)', () => {
  const line = (status?: 'DRAFT' | 'PUBLISHED', active = true) =>
    ({
      active,
      periods:
        status === undefined
          ? undefined
          : [
              { periodStableId: 'c1', number: 1, planningPeriodStableId: 'p1', status: 'PUBLISHED' },
              { periodStableId: 'c2', number: 2, planningPeriodStableId: 'p2', status },
            ].filter((period) => status === 'PUBLISHED' || period.number === 2),
    }) as unknown as PlanningLineSummary

  it('lets a manager choose any active line', () => {
    expect(isExportableLine(line('DRAFT'), true)).toBe(true)
    expect(isExportableLine(line('PUBLISHED', false), true)).toBe(false)
  })

  it('lets anybody else choose only a line published in some period', () => {
    expect(isExportableLine(line('PUBLISHED'), false)).toBe(true)
    expect(isExportableLine(line('DRAFT'), false)).toBe(false)
    expect(isExportableLine({ active: true, periodStatus: 'GENERATED' } as PlanningLineSummary, false)).toBe(
      false,
    )
    expect(
      isExportableLine({ active: true } as PlanningLineSummary, false),
      'unknown: the server decides',
    ).toBe(true)
  })
})

describe('exportModel', () => {
  it('starts from the planning: active lines by position, whole period, last day inclusive', () => {
    const state = initialExportState(PLANNING)
    expect(state.lines.map((line) => line.stableId)).toEqual(['a', 'b'])
    expect(state.firstDay).toBe('2026-10-01')
    expect(state.lastDay).toBe('2026-12-31')
    expect(toExportRequest(state)).toEqual({
      format: 'pdf',
      title: 'Gardes',
      lines: [
        { stableId: 'a', label: 'A' },
        { stableId: 'b', label: 'B' },
      ],
    })
  })

  it('turns an inclusive custom period into the API half-open one, across a year end', () => {
    const state = {
      ...initialExportState(PLANNING),
      period: 'custom' as const,
      firstDay: '2026-12-31',
      lastDay: '2026-12-31',
    }
    expect(validateExport(state, PLANNING)).toEqual({})
    expect(toExportRequest(state)).toMatchObject({ from: '2026-12-31', to: '2027-01-01' })
  })

  it('accepts the first and the last day of the planning, nothing outside', () => {
    const base = { ...initialExportState(PLANNING), period: 'custom' as const }
    expect(validateExport({ ...base, firstDay: '2026-10-01', lastDay: '2026-12-31' }, PLANNING)).toEqual({})
    expect(
      validateExport({ ...base, firstDay: '2026-09-30', lastDay: '2026-12-31' }, PLANNING).period,
    ).toBeDefined()
    expect(
      validateExport({ ...base, firstDay: '2026-10-01', lastDay: '2027-01-01' }, PLANNING).period,
    ).toBeDefined()
  })

  it('crosses the DST change and a month end without losing or repeating a day', () => {
    const planning = { ...PLANNING, startsAt: '2027-03-01', endsAt: '2027-05-01' } as PlanningDetail
    const state = {
      ...initialExportState(planning),
      period: 'custom' as const,
      firstDay: '2027-03-28',
      lastDay: '2027-03-31',
    }
    expect(toExportRequest(state)).toMatchObject({ from: '2027-03-28', to: '2027-04-01' })
    const single = { ...state, firstDay: '2027-03-28', lastDay: '2027-03-28' }
    expect(toExportRequest(single)).toMatchObject({ from: '2027-03-28', to: '2027-03-29' })
  })

  it('moves lines within bounds only', () => {
    const lines = initialExportState(PLANNING).lines
    expect(moveLine(lines, 0, 1).map((line) => line.stableId)).toEqual(['b', 'a'])
    expect(moveLine(lines, 0, -1)).toBe(lines)
    expect(moveLine(lines, 1, 1)).toBe(lines)
  })
})
