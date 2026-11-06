import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../../../testUtils/stubApi'
import type {
  LiveCoverageState,
  LiveDutyDemand,
  PlanningResultDuty,
  PlanningResultLine,
  PublicationState,
  PublicationUncoveredUnit,
} from '../result/types'
import type { PlanningDetail } from '../types'
import { PlanningCalendar } from './PlanningCalendar'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-02T10:00:00Z'))
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const MANAGER: PlanningDetail = {
  stableId: 'plan-1',
  name: 'Gardes',
  creatorStableId: 'u0',
  canManage: true,
  canManageCalendar: true,
  canPublish: true,
  startsAt: '2026-10-01',
  endsAt: '2027-01-01',
  timezone: 'Europe/Brussels',
  createdAt: '',
  updatedAt: '',
  lines: [],
}

function demand(state: LiveCoverageState): LiveDutyDemand {
  return {
    state,
    required: state === 'UNDETERMINED' ? null : state.startsWith('REQUIRED'),
    reason: state.startsWith('REQUIRED') ? 'TRIGGERED' : 'HOLDER_HAS_NO_TRIGGER',
    superfluous: state === 'NOT_REQUIRED_ASSIGNED',
    triggeringDutyStableIds: [],
    triggeringDates: [],
    dayReason: 'HOLDER_HAS_NO_TRIGGER',
    weekday: 'MONDAY',
    sourceDutyStableId: 'src',
    sourceDate: '2026-10-05',
    sourceHolder: null,
    trigger: null,
  }
}

function duty(id: string, date: string, who: string | null, state?: LiveCoverageState): PlanningResultDuty {
  const [firstName, lastName] = (who ?? ' ').split(' ')
  return {
    dutyStableId: id,
    date,
    startsAt: `${date}T08:00:00+02:00`,
    endsAt: `${date}T20:00:00+02:00`,
    timezone: 'Europe/Brussels',
    dutyType: { stableId: 't', code: 'G', name: 'Garde' },
    required: state ? state.startsWith('REQUIRED') : true,
    grouped: false,
    groupInstanceStableId: null,
    groupLabel: null,
    groupDates: null,
    covered: who !== null,
    assignment: who
      ? {
          stableId: `a-${id}`,
          source: 'AUTO',
          locked: false,
          teamMemberStableId: `m-${firstName}`,
          user: { stableId: `u-${firstName}`, firstName, lastName },
        }
      : null,
    reasons: [],
    demand: state ? demand(state) : null,
  }
}

function line(id: string, name: string, duties: PlanningResultDuty[]): PlanningResultLine {
  return {
    lineStableId: id,
    lineName: name,
    lineType: id === 'l1' ? 'PRIMARY' : 'SECONDARY',
    generationStableId: `g-${id}`,
    generatedAt: null,
    coverageStatus: 'COMPLETE',
    requiredDutyCount: 0,
    coveredRequiredDutyCount: 0,
    uncoveredRequiredDutyCount: 0,
    undeterminedDutyCount: 0,
    superfluousDutyCount: 0,
    notRequiredDutyCount: 0,
    duties,
  }
}

const NOT_PUBLISHED: PublicationState = {
  published: false,
  firstPublishedAt: null,
  lastPublishedAt: null,
  lastPublishedBy: null,
  hasUnpublishedChanges: false,
  changes: [],
  history: [],
}

/** Main line fully covered; the reinforcement line « Fell/Ass » with the posts given. */
function result(renfort: PlanningResultDuty[]) {
  return {
    planningStableId: 'plan-1',
    lines: [
      line('l1', 'Seniors', [
        duty('p6', '2026-10-06', 'Anne Admin'),
        duty('p8', '2026-10-08', 'Anne Admin'),
        duty('p9', '2026-10-09', 'Bob Claes'),
      ]),
      line('l2', 'Fell/Ass', renfort),
    ],
  }
}

function unit(
  dutyId: string,
  date: string,
  vacancy: Partial<PublicationUncoveredUnit['vacancy']> & {
    code: PublicationUncoveredUnit['vacancy']['code']
  },
): PublicationUncoveredUnit {
  return {
    lineStableId: 'l2',
    lineName: 'Fell/Ass',
    sourceLineName: 'Seniors',
    conditional: true,
    materialized: true,
    linePublished: false,
    dates: [date],
    dutyStableIds: [dutyId],
    days: [
      {
        date,
        weekday: 'FRIDAY',
        reason: 'TRIGGERED',
        dutyStableId: dutyId,
        sourceDutyStableId: 'src',
        sourceHolder: null,
      },
    ],
    vacancy: {
      memberCount: 6,
      assignableCount: 0,
      firstMembershipStart: null,
      reasons: [],
      ...vacancy,
    },
  }
}

function preflight(units: PublicationUncoveredUnit[]) {
  return {
    publishable: false,
    republishable: false,
    lines: [],
    uncoveredDuties: units.map((u) => ({
      dutyStableId: u.dutyStableIds[0],
      date: u.dates[0],
      lineName: 'Fell/Ass',
    })),
    uncoveredUnits: units,
    inconsistentGroups: [],
    invalidAssignments: [],
    conflicts: [],
    undeterminedDuties: [],
    superfluousCoverages: [],
  }
}

/** The Trauma Delta case (D232): October posts of Fell/Ass, whose members join on 1 November. */
const OCTOBER = [
  unit('r6', '2026-10-06', {
    code: 'NO_MEMBER_ON_DATE',
    firstMembershipStart: '2026-11-01',
    unfillable: true,
  }),
  unit('r8', '2026-10-08', {
    code: 'NO_MEMBER_ON_DATE',
    firstMembershipStart: '2026-11-01',
    unfillable: true,
  }),
]

describe('PlanningCalendar — posts nobody can fill (docs/decisions.md D234)', () => {
  it('never offers « Compléter » for posts without any member on their dates, and opens the coverage start', async () => {
    stubApi({
      'GET /api/plannings/plan-1/result': () =>
        result([
          duty('r6', '2026-10-06', null, 'REQUIRED_UNASSIGNED'),
          duty('r8', '2026-10-08', null, 'REQUIRED_UNASSIGNED'),
        ]),
      'GET /api/plannings/plan-1/publication-state': () => NOT_PUBLISHED,
      'GET /api/plannings/plan-1/publication-preflight': () => preflight(OCTOBER),
    })
    const onCheckCoverage = vi.fn()
    render(<PlanningCalendar planning={MANAGER} onCheckCoverage={onCheckCoverage} />)

    const note = await screen.findByRole('note', { name: 'Postes impossibles à pourvoir — Fell/Ass' })
    expect(within(note).getByText('⚠ 2 renforts requis sans membre éligible')).toBeInTheDocument()
    expect(note).toHaveTextContent('du 6 octobre 2026 au 8 octobre 2026')
    expect(
      within(note).getByText(
        /Aucun membre de Fell\/Ass n’est éligible à ces dates \(adhésions à partir du 1 novembre 2026\)\./,
      ),
    ).toBeInTheDocument()
    expect(within(note).getByText(/La complétion ne peut pas les pourvoir/)).toBeInTheDocument()
    expect(screen.queryByText(/Compléter la période/)).not.toBeInTheDocument()
    expect(screen.queryByText(/requis non attribués?/)).not.toBeInTheDocument()

    fireEvent.click(within(note).getByRole('button', { name: 'Vérifier le début de couverture de la ligne' }))
    expect(onCheckCoverage).toHaveBeenCalledWith('l2')
  })

  it('completable posts next to impossible ones: both said, « Compléter » for the first only', async () => {
    stubApi({
      'GET /api/plannings/plan-1/result': () =>
        result([
          duty('r6', '2026-10-06', null, 'REQUIRED_UNASSIGNED'),
          duty('r8', '2026-10-08', null, 'REQUIRED_UNASSIGNED'),
          duty('r9', '2026-10-09', null, 'REQUIRED_UNASSIGNED'),
        ]),
      'GET /api/plannings/plan-1/publication-state': () => NOT_PUBLISHED,
      'GET /api/plannings/plan-1/publication-preflight': () =>
        preflight([
          ...OCTOBER,
          unit('r9', '2026-10-09', { code: 'ASSIGNABLE_MEMBERS', assignableCount: 2, unfillable: false }),
        ]),
    })
    render(<PlanningCalendar planning={MANAGER} onCheckCoverage={vi.fn()} />)

    expect(await screen.findByText('⚠ 1 renfort requis non attribué')).toBeInTheDocument()
    expect(screen.getByText(/ou utilisez « Compléter la période » en haut de page\.$/)).toBeInTheDocument()
    expect(screen.getByRole('note', { name: 'Postes impossibles à pourvoir — Fell/Ass' })).toHaveTextContent(
      '2 renforts requis sans membre éligible',
    )
  })

  it('members on the dates but none able: the cause, never the coverage start', async () => {
    stubApi({
      'GET /api/plannings/plan-1/result': () =>
        result([duty('r6', '2026-10-06', null, 'REQUIRED_UNASSIGNED')]),
      'GET /api/plannings/plan-1/publication-state': () => NOT_PUBLISHED,
      'GET /api/plannings/plan-1/publication-preflight': () =>
        preflight([
          unit('r6', '2026-10-06', {
            code: 'NO_ELIGIBLE_MEMBER',
            reasons: [{ code: 'UNAVAILABLE', label: 'indisponible', count: 6 }],
            unfillable: true,
          }),
        ]),
    })
    render(<PlanningCalendar planning={MANAGER} onCheckCoverage={vi.fn()} />)

    const note = await screen.findByRole('note', { name: 'Postes impossibles à pourvoir — Fell/Ass' })
    expect(note).toHaveTextContent('le 6 octobre 2026')
    expect(within(note).getByText(/ne peut assurer ces gardes à ces dates/)).toBeInTheDocument()
    expect(within(note).queryByRole('button')).not.toBeInTheDocument()
  })

  it('an older server that does not classify the posts: the usual sentence, nothing said impossible', async () => {
    stubApi({
      'GET /api/plannings/plan-1/result': () =>
        result([duty('r6', '2026-10-06', null, 'REQUIRED_UNASSIGNED')]),
      'GET /api/plannings/plan-1/publication-state': () => NOT_PUBLISHED,
      'GET /api/plannings/plan-1/publication-preflight': () =>
        preflight([unit('r6', '2026-10-06', { code: 'NO_MEMBER_ON_DATE' })]),
    })
    render(<PlanningCalendar planning={MANAGER} />)

    expect(await screen.findByText('⚠ 1 renfort requis non attribué')).toBeInTheDocument()
    expect(screen.queryByRole('note')).not.toBeInTheDocument()
  })
})
