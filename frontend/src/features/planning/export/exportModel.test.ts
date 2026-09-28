import { describe, expect, it } from 'vitest'
import type { PlanningDetail } from '../types'
import { initialExportState, moveLine, toExportRequest, validateExport } from './exportModel'

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

  it('moves lines within bounds only', () => {
    const lines = initialExportState(PLANNING).lines
    expect(moveLine(lines, 0, 1).map((line) => line.stableId)).toEqual(['b', 'a'])
    expect(moveLine(lines, 0, -1)).toBe(lines)
    expect(moveLine(lines, 1, 1)).toBe(lines)
  })
})
