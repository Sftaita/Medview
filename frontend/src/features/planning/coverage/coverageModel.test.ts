import { describe, expect, it } from 'vitest'
import { draftFromView, isDirty, matrixRows, setColumn, setDays, toggleDay, toInput } from './coverageModel'
import { AUTRE, conditionalView, DR_A, DR_B, DR_C, policyView, SENIORS } from './coverageTestData'
import { WEEKDAYS } from './weekdays'

describe('coverageModel (docs/decisions.md D167)', () => {
  it('maps the screen days to the API days explicitly, Monday first', () => {
    expect(WEEKDAYS.map((d) => d.short)).toEqual(['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'])
    expect(WEEKDAYS.map((d) => d.api)).toEqual(['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'])
  })

  it('starts from exactly the server state', () => {
    const draft = draftFromView(conditionalView())
    expect(draft.mode).toBe('CONDITIONAL_ON_SOURCE_ASSIGNMENT')
    expect(draft.sourceLineStableId).toBe(SENIORS)
    expect(draft.selection[DR_C.userStableId]).toEqual(['FRIDAY', 'SATURDAY', 'SUNDAY'])
    expect(isDirty(draft, conditionalView())).toBe(false)
  })

  it('never offers the conditional mode for the primary line', () => {
    const primary = policyView({ line: { stableId: SENIORS, name: 'Seniors', type: 'PRIMARY' }, mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT' })
    expect(draftFromView(primary).mode).toBe('INDEPENDENT')
  })

  it('lists each person (User) once, even with several membership stints, sorted by name', () => {
    const rows = matrixRows(conditionalView(), draftFromView(conditionalView()))
    expect(rows.map((r) => r.userStableId)).toEqual([DR_A.userStableId, DR_B.userStableId, DR_C.userStableId])
  })

  it('keeps a person still selected but no longer part of the source line, flagged — never dropped silently', () => {
    const view = conditionalView()
    const draft = { ...draftFromView(view), sourceLineStableId: AUTRE }
    const rows = matrixRows(view, draft)
    expect(rows.map((r) => [r.userStableId, r.outsideSource])).toEqual([
      [DR_C.userStableId, false],
      [DR_B.userStableId, true],
    ])
  })

  it('edits locally: one box, a whole row, a whole column', () => {
    let selection = toggleDay({}, DR_A.userStableId, 'TUESDAY')
    selection = toggleDay(selection, DR_A.userStableId, 'MONDAY')
    expect(selection[DR_A.userStableId]).toEqual(['MONDAY', 'TUESDAY'])
    selection = toggleDay(selection, DR_A.userStableId, 'MONDAY')
    expect(selection[DR_A.userStableId]).toEqual(['TUESDAY'])
    selection = setDays(selection, DR_B.userStableId, ['SUNDAY', 'MONDAY'])
    expect(selection[DR_B.userStableId]).toEqual(['MONDAY', 'SUNDAY'])
    selection = setColumn(selection, [DR_A.userStableId, DR_B.userStableId, DR_C.userStableId], 'FRIDAY', true)
    expect(selection[DR_C.userStableId]).toEqual(['FRIDAY'])
    selection = setColumn(selection, [DR_A.userStableId, DR_B.userStableId], 'FRIDAY', false)
    expect(selection[DR_A.userStableId]).toEqual(['TUESDAY'])
  })

  it('sends MONDAY…SUNDAY, one trigger per person with at least one day, increment 1', () => {
    const draft = {
      mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT' as const,
      sourceLineStableId: SENIORS,
      selection: {
        [DR_A.userStableId]: [],
        [DR_C.userStableId]: ['SUNDAY' as const, 'FRIDAY' as const, 'SATURDAY' as const],
      },
    }
    expect(toInput(draft)).toEqual({
      schemaVersion: 1,
      mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
      source: { lineStableId: SENIORS },
      triggers: [{ userStableId: DR_C.userStableId, weekdays: ['FRIDAY', 'SATURDAY', 'SUNDAY'], increment: 1 }],
    })
  })

  it('an independent line sends neither a source nor triggers', () => {
    const draft = { ...draftFromView(conditionalView()), mode: 'INDEPENDENT' as const }
    expect(toInput(draft)).toEqual({ schemaVersion: 1, mode: 'INDEPENDENT', source: null, triggers: [] })
    expect(isDirty(draft, conditionalView())).toBe(true)
  })
})
