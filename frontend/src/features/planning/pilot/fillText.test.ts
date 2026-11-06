import { describe, expect, it } from 'vitest'
import { fillPlanText, unfillableText } from './fillText'
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
  it('posts without any member on their dates: the cause, and that the completion cannot fill them (D234)', () => {
    const text = fillPlanText(
      plan({
        action: 'NOTHING',
        reason: 'no_eligible_member',
        uncoveredRequiredDutyCount: 19,
        unfillable: {
          dutyCount: 19,
          noMemberOnDateCount: 19,
          noEligibleMemberCount: 0,
          firstMembershipStart: '2026-11-01',
          from: '2026-10-02',
          to: '2026-10-29',
        },
      }),
    )
    expect(text).toBe(
      'Aucun membre de « Fell/Ass » n’est éligible pour 19 gardes (du 2 octobre 2026 au 29 octobre 2026) : ses membres n’y sont rattachés qu’à partir du 1 novembre 2026. La complétion ne peut pas les pourvoir : rien n’est lancé pour cette ligne.',
    )
  })

  it('posts members cannot take: said without any membership date (D234)', () => {
    expect(
      unfillableText(
        plan({
          unfillable: {
            dutyCount: 1,
            noMemberOnDateCount: 0,
            noEligibleMemberCount: 1,
            firstMembershipStart: null,
            from: '2027-02-03',
            to: '2027-02-03',
          },
        }),
      ),
    ).toBe('Aucun membre de « Fell/Ass » ne peut assurer 1 garde (le 3 février 2027).')
  })

  it('a mixed line: the posts to complete, then those that will stay vacant (D234)', () => {
    const text = fillPlanText(
      plan({
        action: 'COMPLETE',
        uncoveredRequiredDutyCount: 21,
        unfillable: {
          dutyCount: 19,
          noMemberOnDateCount: 19,
          noEligibleMemberCount: 0,
          firstMembershipStart: '2026-11-01',
          from: '2026-10-02',
          to: '2026-10-29',
        },
      }),
    )
    expect(text).toMatch(/^2 gardes non pourvues à compléter, sans toucher aux gardes attribuées./)
    expect(text).toContain('n’est éligible pour 19 gardes')
    expect(text).toMatch(/Elles resteront vacantes.$/)
  })

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
