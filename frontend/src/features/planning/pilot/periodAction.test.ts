import { describe, expect, it } from 'vitest'
import { periodAction } from './periodAction'
import type { FillLinePlan } from './types'

function plan(overrides: Partial<FillLinePlan>): FillLinePlan {
  return {
    lineStableId: 'line',
    lineName: 'Ligne',
    lineType: 'PRIMARY',
    periodStatus: 'GENERATED',
    generated: true,
    action: 'NOTHING',
    reason: null,
    selectable: false,
    selectedByDefault: false,
    requiredDutyCount: 10,
    coveredRequiredDutyCount: 10,
    uncoveredRequiredDutyCount: 0,
    protectedAssignmentCount: 10,
    blockers: [],
    warnings: [],
    afterSource: false,
    ...overrides,
  }
}

const run = (hasGeneratedLines: boolean, plans?: FillLinePlan[], busy = false) =>
  periodAction({ number: 2, hasGeneratedLines, plans, busy })

describe('periodAction (docs/decisions.md D228)', () => {
  it('a period never generated reads "Générer", whatever the analysis says', () => {
    expect(run(false)).toEqual({ kind: 'generate', label: 'Générer la période 2' })
    expect(run(false, [plan({ action: 'GENERATE', generated: false })]).kind).toBe('generate')
  })

  it('a generated period reads "Compléter" before the analysis comes back: the label never jumps', () => {
    expect(run(true)).toEqual({ kind: 'complete', label: 'Compléter la période 2' })
  })

  it('at least one line with something to receive: "Compléter" — a new line, a hole, or new dates of a published line', () => {
    expect(run(true, [plan({}), plan({ action: 'GENERATE', generated: false })]).kind).toBe('complete')
    expect(run(true, [plan({ action: 'COMPLETE', uncoveredRequiredDutyCount: 3 })]).kind).toBe('complete')
    expect(
      run(true, [plan({ action: 'COMPLETE', reason: 'extension_days', extensionOnly: true })]).kind,
    ).toBe('complete')
  })

  it('a line blocked keeps "Compléter": the dialog says why, it is never read as complete', () => {
    expect(run(true, [plan({}), plan({ action: 'EXCLUDED', reason: 'blocked' })]).kind).toBe('complete')
  })

  it('everything covered: "Période complète", nothing to launch', () => {
    expect(run(true, [plan({}), plan({ action: 'EXCLUDED', reason: 'nothing_demanded' })])).toEqual({
      kind: 'done',
      label: 'Période complète',
    })
    expect(run(true, [plan({ action: 'EXCLUDED', reason: 'published' })]).kind).toBe('done')
  })

  it('only older holes of published lines: never launched, said in the same place', () => {
    expect(
      run(true, [plan({ action: 'EXCLUDED', reason: 'published', uncoveredRequiredDutyCount: 2 })]),
    ).toEqual({ kind: 'manual', label: '2 gardes à attribuer à la main', holes: 2 })
  })

  it('a calculation running comes first', () => {
    expect(run(true, undefined, true)).toEqual({ kind: 'busy', label: 'Calcul en cours…' })
    expect(run(false, undefined, true).kind).toBe('busy')
  })
})
