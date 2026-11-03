import { describe, expect, it } from 'vitest'
import { fillPlanText } from './fillText'
import type { FillLinePlan } from './types'

const plan = (overrides: Partial<FillLinePlan>): FillLinePlan => ({
  lineStableId: 'line',
  lineName: 'Fell/Ass',
  lineType: 'SECONDARY',
  periodStatus: 'GENERATED',
  generated: true,
  action: 'NOTHING',
  reason: null,
  selectable: false,
  selectedByDefault: false,
  requiredDutyCount: 9,
  coveredRequiredDutyCount: 9,
  uncoveredRequiredDutyCount: 0,
  protectedAssignmentCount: 9,
  blockers: [],
  warnings: [],
  afterSource: false,
  ...overrides,
})

describe('fillPlanText (docs/decisions.md D226, D228)', () => {
  it('a line with nothing to do says so', () => {
    expect(fillPlanText(plan({}))).toBe('Toutes les gardes obligatoires sont attribuées : rien à faire.')
  })

  it('a line that follows its source never reads as untouched: reinforcements may be added', () => {
    expect(fillPlanText(plan({ followsSource: true }))).toContain('Des renforts seront ajoutés')
    expect(fillPlanText(plan({ action: 'EXCLUDED', reason: 'published', followsSource: true }))).toContain(
      'Publiée : ses gardes ne changent pas',
    )
  })

  it('a published line receives the new dates only, and says what stays by hand', () => {
    const text = fillPlanText(
      plan({
        action: 'COMPLETE',
        reason: 'extension_days',
        extensionOnly: true,
        uncoveredRequiredDutyCount: 7,
        otherUncoveredCount: 2,
      }),
    )
    expect(text).toContain('7 gardes des nouvelles dates à attribuer')
    expect(text).toContain('2 anciens postes vacants restent à attribuer à la main')
    expect(text).toContain('À republier ensuite')
  })
})
