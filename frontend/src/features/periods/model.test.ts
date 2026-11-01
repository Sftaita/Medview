import { describe, expect, it } from 'vitest'
import type { PlanningLineSummary, PlanningPeriodSummary, PlanningPeriodView } from '../planning/types'
import {
  defaultPeriodNumber,
  hasReadableCalendar,
  isGenerated,
  lineStates,
  roleOf,
  toCardData,
  toPeriod,
} from './model'

function summary(overrides: Partial<PlanningPeriodSummary> = {}): PlanningPeriodSummary {
  return {
    stableId: 'c2',
    number: 2,
    startsAt: '2027-01-31',
    endsAt: '2027-05-01',
    lastDay: '2027-04-30',
    status: 'collecting',
    timeState: 'future',
    lineCounts: { counting: 2, published: 0, incomplete: 0, withoutDemand: 1 },
    publishedAt: null,
    lastPublishedAt: null,
    generatedAt: '2027-01-10T09:30:00+00:00',
    collections: {
      open: 1,
      closed: 0,
      participants: 9,
      deadline: '2027-01-20',
      uncoveredDays: 0,
    },
    ...overrides,
  }
}

function line(stableId: string, name: string): PlanningLineSummary {
  return {
    stableId,
    name,
    type: 'SECONDARY',
    position: 0,
    active: true,
    team: { stableId: `t-${stableId}`, name },
    planningPeriodStableId: '',
    createdAt: '',
    updatedAt: '',
  }
}

describe('periods model (lot L6)', () => {
  it('maps a period of the API: the last day included, the server status, the participants and the dates', () => {
    expect(toPeriod(summary())).toEqual({
      id: 'c2',
      number: 2,
      start: '2027-01-31',
      end: '2027-04-30',
      status: 'collecte',
      collecte: {
        state: 'en-cours',
        participants: 9,
        closesOn: '2027-01-20',
        announceOn: undefined,
        uncoveredDays: 0,
      },
      targetGenerationOn: undefined,
      generatedOn: '2027-01-10',
      publishedOn: undefined,
      lastPublishedOn: undefined,
      lineCounts: { counting: 2, published: 0 },
    })
    expect(toPeriod(summary({ status: 'partially_published' })).status).toBe('partielle')
    expect(toPeriod(summary({ status: 'published' })).status).toBe('publiee')
    expect(toPeriod(summary({ status: 'generated' })).status).toBe('generee')
    expect(toPeriod(summary({ status: 'preparation' })).status).toBe('preparation')
    const closed = summary({
      collections: { open: 0, closed: 2, participants: 0, deadline: null, uncoveredDays: 3 },
    })
    expect(toPeriod(closed).collecte.state).toBe('cloturee')
    const none = summary({
      collections: { open: 0, closed: 0, participants: 0, deadline: null, uncoveredDays: 89 },
    })
    expect(toPeriod(none).collecte).toMatchObject({ state: 'non-ouverte', uncoveredDays: 89 })
  })

  it('never reads "collecte ouverte" while the announcement is still scheduled (Trauma Delta, D214)', () => {
    const scheduled = toPeriod(
      summary({
        status: 'preparation',
        preparation: {
          announceOn: '2027-01-17',
          announcementState: 'scheduled',
          declarationDeadlineOn: '2027-01-22',
          targetGenerationOn: '2027-01-24',
        },
      }),
    )
    expect(scheduled.collecte).toMatchObject({
      state: 'programmee',
      announceOn: '2027-01-17',
      closesOn: '2027-01-20',
    })
    expect(scheduled.targetGenerationOn).toBe('2027-01-24')

    const announced = toPeriod(
      summary({
        preparation: {
          announceOn: '2027-01-17',
          announcementState: 'executed',
          declarationDeadlineOn: null,
          targetGenerationOn: '2027-01-24',
        },
      }),
    )
    expect(announced.collecte).toMatchObject({ state: 'en-cours', announceOn: undefined })
  })

  it('derives the role from the rights, never from a name', () => {
    expect(roleOf({ canManage: true, canPublish: true })).toBe('Propriétaire')
    expect(roleOf({ canManage: false, canPublish: true })).toBe('Gestionnaire')
    expect(roleOf({ canManage: false })).toBe('Membre')
  })

  it('opens the current period, else the next, else the last', () => {
    const periods = [
      toPeriod(summary({ stableId: 'c1', number: 1, startsAt: '2026-06-01', lastDay: '2026-09-30' })),
      toPeriod(summary({ stableId: 'c2', number: 2, startsAt: '2026-10-01', lastDay: '2027-01-30' })),
    ]
    expect(defaultPeriodNumber(periods, '2026-10-01')).toBe(2)
    expect(defaultPeriodNumber(periods, '2026-09-30')).toBe(1)
    expect(defaultPeriodNumber(periods, '2026-01-01')).toBe(1)
    expect(defaultPeriodNumber(periods, '2028-01-01')).toBe(2)
    expect(defaultPeriodNumber([], '2028-01-01')).toBeUndefined()
  })

  it('builds a card: the current and the next period, else the next alone, else the last one', () => {
    const planning = {
      stableId: 'p',
      name: 'Trauma Delta',
      creatorStableId: 'u',
      canManage: false,
      canPublish: false,
      startsAt: '2026-06-01',
      endsAt: '2027-05-01',
      timezone: 'Europe/Brussels',
      createdAt: '',
      updatedAt: '',
      lineCount: 3,
      memberCount: 9,
      periodCount: 3,
    }
    const current = summary({ stableId: 'c2', number: 2 })
    const next = summary({ stableId: 'c3', number: 3 })
    const latest = summary({ stableId: 'c3', number: 3 })

    const both = toCardData({ ...planning, currentPeriod: current, nextPeriod: next, latestPeriod: latest })!
    expect([both.main.number, both.next?.number, both.role, both.periodCount]).toEqual([2, 3, 'Membre', 3])
    const before = toCardData({ ...planning, currentPeriod: null, nextPeriod: next, latestPeriod: latest })!
    expect([before.main.number, before.next]).toEqual([3, undefined])
    const over = toCardData({ ...planning, currentPeriod: null, nextPeriod: null, latestPeriod: latest })!
    expect(over.main.number).toBe(3)
    expect(toCardData(planning)).toBeNull()
  })

  it('says the state of each line in a period, and what an incomplete one misses', () => {
    const view: PlanningPeriodView = {
      ...summary(),
      lines: [
        {
          lineStableId: 'a',
          planningPeriodStableId: '',
          status: 'PUBLISHED',
          applicability: { state: 'APPLICABLE', reasons: [] },
        },
        {
          lineStableId: 'b',
          planningPeriodStableId: '',
          status: 'GENERATED',
          applicability: { state: 'APPLICABLE', reasons: [] },
        },
        {
          lineStableId: 'c',
          planningPeriodStableId: '',
          status: 'DRAFT',
          applicability: { state: 'APPLICABLE', reasons: [] },
        },
        {
          lineStableId: 'd',
          planningPeriodStableId: '',
          status: 'DRAFT',
          applicability: { state: 'NO_DEMAND', reasons: ['NO_DUTY'] },
        },
        {
          lineStableId: 'e',
          planningPeriodStableId: '',
          status: 'DRAFT',
          applicability: {
            state: 'INCOMPLETE',
            reasons: ['WEEKLY_STRUCTURE', 'NO_ACTIVE_RULE_SET', 'NO_MEMBER_IN_PERIOD'],
          },
        },
        {
          lineStableId: 'f',
          planningPeriodStableId: '',
          status: 'DRAFT',
          applicability: { state: 'INACTIVE', reasons: [] },
        },
        { lineStableId: 'gone', planningPeriodStableId: '', status: 'DRAFT', applicability: null },
      ],
      isLast: true,
      canEditStart: true,
      canEditEnd: true,
      canDelete: false,
    }
    const lines = ['a', 'b', 'c', 'd', 'e', 'f'].map((id) => line(id, id.toUpperCase()))

    expect(lineStates(view, lines).map((state) => [state.name, state.label, state.tone])).toEqual([
      ['A', 'Publiée', 'ok'],
      ['B', 'Générée, non publiée', 'info'],
      ['C', 'À générer', 'neutral'],
      ['D', 'Aucune garde demandée', 'neutral'],
      ['E', 'Configuration incomplète', 'warn'],
    ])
    expect(lineStates(view, lines)[4].detail).toBe(
      'À compléter : règles de génération non activées ; aucun membre sur la période.',
    )
    expect(isGenerated(view)).toBe(true)
    expect(isGenerated({ ...view, lines: view.lines.filter((l) => l.status === 'DRAFT') })).toBe(false)
    // docs/decisions.md D204: a member has a calendar once a line is published, a manager once one is generated.
    const generatedOnly = { ...view, lines: view.lines.filter((l) => l.status !== 'PUBLISHED') }
    expect(hasReadableCalendar(generatedOnly, true)).toBe(true)
    expect(hasReadableCalendar(generatedOnly, false)).toBe(false)
    expect(hasReadableCalendar(view, false)).toBe(true)
  })
})
