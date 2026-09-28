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
  ReassignmentCandidatesView,
} from '../result/types'
import type { PlanningDetail } from '../types'
import { presentDuty } from './calendarModel'
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

/** Main line fully covered; the reinforcement line in each of the five live states. */
function result(renfort: PlanningResultDuty[]) {
  return {
    planningStableId: 'plan-1',
    lines: [
      line('l1', 'Seniors', [
        duty('p5', '2026-10-05', 'Anne Admin'),
        duty('p6', '2026-10-06', 'Anne Admin'),
        duty('p7', '2026-10-07', 'Anne Admin'),
        duty('p8', '2026-10-08', 'Alice Bernard'),
        duty('p9', '2026-10-09', 'Bob Claes'),
      ]),
      line('l2', 'Renfort', renfort),
    ],
  }
}

const FIVE_STATES = [
  duty('r5', '2026-10-05', 'Carol Dubois', 'REQUIRED_ASSIGNED'),
  duty('r6', '2026-10-06', null, 'REQUIRED_UNASSIGNED'),
  duty('r7', '2026-10-07', null, 'NOT_REQUIRED_UNASSIGNED'),
  duty('r8', '2026-10-08', 'Dave Evrard', 'NOT_REQUIRED_ASSIGNED'),
  duty('r9', '2026-10-09', null, 'UNDETERMINED'),
]

function renfortCell(table: HTMLElement, day: string): HTMLElement {
  const row = within(table).getByRole('rowheader', { name: new RegExp(`${day}/10`) }).closest('tr')!
  return row.querySelectorAll('td')[1] as HTMLElement
}

describe('PlanningCalendar — reinforcement line (docs/decisions.md D167)', () => {
  it('shows the five live states, each in words, never by colour alone', async () => {
    stubApi({
      'GET /api/plannings/plan-1/result': () => result(FIVE_STATES),
      'GET /api/plannings/plan-1/publication-state': () => NOT_PUBLISHED,
    })
    render(<PlanningCalendar planning={MANAGER} />)
    const table = await screen.findByRole('table')

    expect(within(renfortCell(table, '05')).getByText('Carol Dubois')).toBeInTheDocument()
    expect(within(renfortCell(table, '06')).getByText('⚠ Renfort requis — non attribué')).toBeInTheDocument()
    // Not required, nobody on it: the calendar's usual discreet "no duty" mark — never a gap.
    expect(renfortCell(table, '07')).toHaveTextContent(/^—$/)
    expect(within(renfortCell(table, '07')).getByLabelText('Pas de garde')).toBeInTheDocument()
    expect(within(renfortCell(table, '08')).getByText('Dave Evrard')).toBeInTheDocument()
    expect(within(renfortCell(table, '08')).getByText('Renfort non requis')).toBeInTheDocument()
    expect(within(renfortCell(table, '09')).getByText('? Renfort non évalué')).toBeInTheDocument()
    expect(within(table).queryByText(/Non attribué$/)).not.toBeInTheDocument()

    expect(screen.getByText('⚠ 1 renfort requis non attribué')).toBeInTheDocument()
    expect(screen.getByText('? 1 renfort non évalué')).toBeInTheDocument()
    expect(screen.getByText(/1 renfort attribué mais plus nécessaire/)).toBeInTheDocument()
    expect(within(renfortCell(table, '08')).getByRole('button')).toHaveAccessibleName(
      /Dave Evrard \(renfort non requis, encore attribué\)/,
    )
  })

  it('"Compléter automatiquement" is offered for a missing required reinforcement only', async () => {
    const onRequestCompletion = vi.fn().mockResolvedValue(undefined)
    stubApi({
      'GET /api/plannings/plan-1/result': () =>
        result([duty('r7', '2026-10-07', null, 'NOT_REQUIRED_UNASSIGNED'), duty('r9', '2026-10-09', null, 'UNDETERMINED')]),
      'GET /api/plannings/plan-1/publication-state': () => NOT_PUBLISHED,
    })
    const { unmount } = render(<PlanningCalendar planning={MANAGER} onRequestCompletion={onRequestCompletion} />)
    await screen.findByRole('table')
    expect(screen.getByRole('button', { name: 'Compléter automatiquement' })).toBeDisabled()
    unmount()

    stubApi({
      'GET /api/plannings/plan-1/result': () => result([duty('r6', '2026-10-06', null, 'REQUIRED_UNASSIGNED')]),
      'GET /api/plannings/plan-1/publication-state': () => NOT_PUBLISHED,
    })
    render(<PlanningCalendar planning={MANAGER} onRequestCompletion={onRequestCompletion} />)
    await screen.findByRole('table')
    fireEvent.click(screen.getByRole('button', { name: 'Compléter automatiquement' }))
    expect(onRequestCompletion).toHaveBeenCalledOnce()
  })

  it('after a source replacement, tells what it did to the reinforcements (dependentImpacts)', async () => {
    const view: ReassignmentCandidatesView = {
      groupInstanceStableId: null,
      groupLabel: null,
      blockDuties: [{ dutyStableId: 'p9', date: '2026-10-09', startsAt: '', endsAt: '', dutyTypeName: 'Garde' }],
      generationStableId: 'g-l1',
      currentTeamMemberStableId: 'm-Bob',
      currentAssignee: { teamMemberStableId: 'm-Bob', firstName: 'Bob', lastName: 'Claes' },
      candidates: [{ teamMemberStableId: 'm-Alice', firstName: 'Alice', lastName: 'Bernard' }],
      assignable: true,
      notAssignableReason: null,
      demand: null,
    }
    stubApi({
      'GET /api/plannings/plan-1/result': () => result([]),
      'GET /api/plannings/plan-1/publication-state': () => NOT_PUBLISHED,
      'GET /api/plannings/plan-1/duties/p9/reassignment-candidates': () => view,
      'POST /api/plannings/plan-1/duties/p9/reassign': () => ({
        status: 'reassigned',
        dependentImpacts: [
          {
            lineStableId: 'l2',
            lineName: 'Renfort',
            unitStableKey: 'r9',
            groupInstanceStableId: null,
            dutyStableIds: ['r9'],
            dates: ['2026-10-09'],
            previousState: 'NOT_REQUIRED_UNASSIGNED',
            newState: 'REQUIRED_UNASSIGNED',
            changed: true,
            required: true,
            assigned: false,
            assignee: null,
            reason: 'TRIGGERED',
            triggeringDates: ['2026-10-09'],
          },
        ],
      }),
    })
    render(<PlanningCalendar planning={MANAGER} />)
    const table = await screen.findByRole('table')

    const main = within(table).getByRole('rowheader', { name: /09\/10/ }).closest('tr')!.querySelectorAll('td')[0]
    fireEvent.click(within(main as HTMLElement).getByRole('button'))
    fireEvent.click(await screen.findByRole('button', { name: 'Choisir' }))
    fireEvent.click(screen.getByRole('button', { name: 'Remplacer' }))

    expect(await screen.findByText(/Conséquence sur les renforts :/)).toBeInTheDocument()
    expect(screen.getAllByText(/Renfort, ven\. 9 oct\. : renfort désormais requis — non attribué/).length).toBeGreaterThanOrEqual(1)
  })
})

describe('presentDuty', () => {
  it('reads each state from the backend — an intrinsic duty as before', () => {
    expect(presentDuty(duty('x', '2026-10-05', null))).toMatchObject({ gap: 'Non attribué', tone: 'missing' })
    expect(presentDuty(duty('x', '2026-10-05', 'Anne Admin'))).toMatchObject({ gap: null, tag: null, tone: 'normal' })
    expect(presentDuty(duty('x', '2026-10-05', null, 'UNDETERMINED'))).toMatchObject({ gap: 'Renfort non évalué', tone: 'undetermined' })
    expect(presentDuty(duty('x', '2026-10-05', 'Dave Evrard', 'UNDETERMINED'))).toMatchObject({ tag: 'Renfort non évalué' })
  })
})
