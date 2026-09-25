import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../../testUtils/stubApi'
import { PersonalPlanningView } from './PersonalPlanningView'
import type { PlanningResultCandidateReason, PlanningResultDuty, PlanningResultLine } from './result/types'
import type { PlanningAssignment, PlanningAssignments, PlanningDetail } from './types'

beforeEach(() => {
  // The view opens on the current month, kept inside the planning: freeze it inside February 2027.
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2027-02-10T09:00:00Z'))
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const PLANNING: PlanningDetail = {
  stableId: 'plan-1',
  name: 'Gardes',
  creatorStableId: 'u0',
  canManage: false,
  startsAt: '2027-01-01',
  endsAt: '2027-05-01',
  timezone: 'Europe/Brussels',
  createdAt: '',
  updatedAt: '',
  lines: [
    {
      stableId: 'l1',
      name: 'Seniors',
      type: 'PRIMARY',
      position: 1,
      active: true,
      team: { stableId: 't1', name: 'Seniors' },
      planningPeriodStableId: 'pp1',
      createdAt: '',
      updatedAt: '',
    },
  ],
}

const MEMBERS = [
  {
    stableId: 'm1',
    userStableId: 'u-alice',
    firstName: 'Alice',
    lastName: 'Martin',
    role: 'MEMBER',
    membershipStart: '2027-01-01',
    membershipEnd: null,
    active: true,
  },
  {
    stableId: 'm2',
    userStableId: 'u-bob',
    firstName: 'Bob',
    lastName: 'Durand',
    role: 'MEMBER',
    membershipStart: '2027-01-01',
    membershipEnd: null,
    active: true,
  },
]

function assignment(id: string, date: string, user: 'alice' | 'bob', type = 'Jour'): PlanningAssignment {
  return {
    stableId: id,
    dutyStableId: `d-${id}`,
    date,
    startsAt: `${date}T07:00:00+00:00`,
    endsAt: `${date}T19:00:00+00:00`,
    timezone: 'Europe/Brussels',
    dutyType: { stableId: 'dt', code: type.toUpperCase(), name: type },
    lineStableId: 'l1',
    lineName: 'Seniors',
    source: 'AUTO',
    locked: false,
    user: {
      stableId: `u-${user}`,
      firstName: user === 'alice' ? 'Alice' : 'Bob',
      lastName: user === 'alice' ? 'Martin' : 'Durand',
    },
  }
}

const ALL: PlanningAssignment[] = [
  assignment('a1', '2027-02-05', 'alice', 'Nuit'),
  assignment('a2', '2027-02-06', 'alice'),
  assignment('b1', '2027-02-07', 'bob'),
  assignment('a3', '2027-03-02', 'alice'),
]

/** A backend that filters by `userStableId` and by [from, to) like the real one — unchanged, person mode only. */
function stubAssignments(options: { generations?: number } = {}) {
  return stubApi({
    'GET /api/plannings/plan-1/teams/t1/members': () => MEMBERS,
    // These tests only ever look at the person-mode screen, but the component always fetches the
    // whole-team result on mount too (it opens on "Toute l'équipe"): a harmless, empty stub for it.
    'GET /api/plannings/plan-1/result': () => ({ planningStableId: 'plan-1', lines: [makeLine()] }),
    'GET /api/plannings/plan-1/assignments': ({ query }) => {
      const user = query.get('userStableId')
      const from = query.get('from') ?? '0000-00-00'
      const to = query.get('to') ?? '9999-99-99'
      const assignments = ALL.filter(
        (a) => (!user || a.user.stableId === user) && a.date >= from && a.date < to,
      )
      const result: PlanningAssignments = {
        generations:
          (options.generations ?? 1) === 0
            ? []
            : [
                {
                  stableId: 'g1',
                  lineStableId: 'l1',
                  lineName: 'Seniors',
                  generatedAt: null,
                  coverageStatus: 'COMPLETE',
                },
              ],
        assignments,
        summary: user
          ? {
              userStableId: user,
              totalDuties: ALL.filter((a) => a.user.stableId === user).length,
              weightedWorkload: 3.5,
              fridays: 1,
              saturdays: 1,
              sundays: 0,
              weekendDays: 1,
              byDutyType: [
                { dutyTypeStableId: 'dt1', code: 'NUIT', name: 'Nuit', count: 1 },
                { dutyTypeStableId: 'dt2', code: 'JOUR', name: 'Jour', count: 2 },
              ],
            }
          : null,
      }
      return result
    },
  })
}

function resultDuty(
  id: string,
  date: string,
  overrides: Partial<PlanningResultDuty> = {},
): PlanningResultDuty {
  return {
    dutyStableId: id,
    date,
    startsAt: `${date}T07:00:00+00:00`,
    endsAt: `${date}T19:00:00+00:00`,
    timezone: 'Europe/Brussels',
    dutyType: { stableId: 'dt', code: 'JOUR', name: 'Jour' },
    required: true,
    grouped: false,
    covered: true,
    assignment: {
      stableId: `dr-${id}`,
      source: 'AUTO',
      locked: false,
      user: { stableId: 'u-alice', firstName: 'Alice', lastName: 'Martin' },
    },
    reasons: [],
    ...overrides,
  }
}

const REASONS: PlanningResultCandidateReason[] = [
  { candidateFirstName: 'Alice', candidateLastName: 'Martin', reasons: ['indisponible'] },
  {
    candidateFirstName: 'Bob',
    candidateLastName: 'Durand',
    reasons: ['indisponible', "repos d'équipe insuffisant"],
  },
]

/** GET /api/plannings/{id}/result, the whole-team read model (docs/decisions.md D130). */
function makeLine(overrides: Partial<PlanningResultLine> = {}): PlanningResultLine {
  return {
    lineStableId: 'l1',
    lineName: 'Seniors',
    lineType: 'PRIMARY',
    generationStableId: 'g1',
    generatedAt: '2027-02-01T08:00:00+00:00',
    coverageStatus: 'COMPLETE',
    requiredDutyCount: 0,
    coveredRequiredDutyCount: 0,
    uncoveredRequiredDutyCount: 0,
    duties: [],
    ...overrides,
  }
}

function stubResult(lines: PlanningResultLine[]) {
  return stubApi({
    'GET /api/plannings/plan-1/teams/t1/members': () => MEMBERS,
    'GET /api/plannings/plan-1/result': () => ({ planningStableId: 'plan-1', lines }),
  })
}

function renderView() {
  render(<PersonalPlanningView planning={PLANNING} />)
}

describe('PersonalPlanningView — whole team (generated result)', () => {
  it('shows the coverage badge and the day-by-day list for a complete generation', async () => {
    const api = stubResult([
      makeLine({
        requiredDutyCount: 3,
        coveredRequiredDutyCount: 3,
        duties: [
          resultDuty('a1', '2027-02-05', { dutyType: { stableId: 'dt1', code: 'NUIT', name: 'Nuit' } }),
          resultDuty('a2', '2027-02-06'),
          resultDuty('b1', '2027-02-07', {
            assignment: {
              stableId: 'dr-b1',
              source: 'AUTO',
              locked: false,
              user: { stableId: 'u-bob', firstName: 'Bob', lastName: 'Durand' },
            },
          }),
        ],
      }),
    ])
    renderView()

    expect(await screen.findByText('Février 2027')).toBeInTheDocument()
    expect(await screen.findByText('Couverture complète')).toBeInTheDocument()
    expect(screen.getByText('3 / 3 gardes couvertes')).toBeInTheDocument()
    expect(screen.queryByText(/non couverte/i)).not.toBeInTheDocument()

    const list = await screen.findByRole('list', { name: 'Gardes du mois' })
    expect(within(list).getAllByText(/Martin|Durand/).length).toBe(3)
    expect(within(list).getByText('Bob Durand')).toBeInTheDocument()
    expect(api.requests('GET', '/api/plannings/plan-1/result')[0].query.get('from')).toBe('2027-02-01')
    expect(api.requests('GET', '/api/plannings/plan-1/result')[0].query.get('to')).toBe('2027-03-01')
  })

  it('offers no edit control to a plain member, but opens the reassignment modal for a manager (docs/decisions.md D131)', async () => {
    stubApi({
      'GET /api/plannings/plan-1/teams/t1/members': () => MEMBERS,
      'GET /api/plannings/plan-1/result': () => ({
        planningStableId: 'plan-1',
        lines: [
          makeLine({
            requiredDutyCount: 1,
            coveredRequiredDutyCount: 1,
            duties: [resultDuty('a1', '2027-02-05')],
          }),
        ],
      }),
      'GET /api/plannings/plan-1/duties/a1/reassignment-candidates': () => ({
        groupInstanceStableId: null,
        blockDuties: [
          { dutyStableId: 'a1', date: '2027-02-05', startsAt: '', endsAt: '', dutyTypeName: 'Jour' },
        ],
        generationStableId: 'g1',
        currentTeamMemberStableId: 'm1',
        candidates: [],
      }),
    })
    render(<PersonalPlanningView planning={{ ...PLANNING, canManageCalendar: false }} />)

    await screen.findByText('Couverture complète')
    expect(screen.queryByRole('button', { name: 'Réattribuer' })).not.toBeInTheDocument()
    cleanup()

    stubApi({
      'GET /api/plannings/plan-1/teams/t1/members': () => MEMBERS,
      'GET /api/plannings/plan-1/result': () => ({
        planningStableId: 'plan-1',
        lines: [
          makeLine({
            requiredDutyCount: 1,
            coveredRequiredDutyCount: 1,
            duties: [resultDuty('a1', '2027-02-05')],
          }),
        ],
      }),
      'GET /api/plannings/plan-1/duties/a1/reassignment-candidates': () => ({
        groupInstanceStableId: null,
        blockDuties: [
          { dutyStableId: 'a1', date: '2027-02-05', startsAt: '', endsAt: '', dutyTypeName: 'Jour' },
        ],
        generationStableId: 'g1',
        currentTeamMemberStableId: 'm1',
        candidates: [],
      }),
    })
    render(<PersonalPlanningView planning={{ ...PLANNING, canManageCalendar: true }} />)

    const editButton = await screen.findByRole('button', { name: 'Réattribuer' })
    fireEvent.click(editButton)
    expect(await screen.findByText('Modifier la garde')).toBeInTheDocument()
  })

  it('shows the real publication status, offers the publish button only to a manager, and opens the modal (docs/decisions.md D133)', async () => {
    stubApi({
      'GET /api/plannings/plan-1/teams/t1/members': () => MEMBERS,
      'GET /api/plannings/plan-1/result': () => ({ planningStableId: 'plan-1', lines: [makeLine()] }),
      'GET /api/plannings/plan-1/publication-preflight': () => ({
        publishable: true,
        lines: [{ lineStableId: 'l1', lineName: 'Seniors', periodStatus: 'GENERATED', hasGeneration: true }],
        uncoveredDuties: [],
        inconsistentGroups: [],
        invalidAssignments: [],
        conflicts: [],
      }),
    })
    render(<PersonalPlanningView planning={{ ...PLANNING, canPublish: false }} />)

    expect(await screen.findByText('Statut : Non publié')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Publier le planning' })).not.toBeInTheDocument()
    cleanup()

    stubApi({
      'GET /api/plannings/plan-1/teams/t1/members': () => MEMBERS,
      'GET /api/plannings/plan-1/result': () => ({ planningStableId: 'plan-1', lines: [makeLine()] }),
      'GET /api/plannings/plan-1/publication-preflight': () => ({
        publishable: true,
        lines: [{ lineStableId: 'l1', lineName: 'Seniors', periodStatus: 'GENERATED', hasGeneration: true }],
        uncoveredDuties: [],
        inconsistentGroups: [],
        invalidAssignments: [],
        conflicts: [],
      }),
    })
    render(<PersonalPlanningView planning={{ ...PLANNING, canPublish: true }} />)

    const publishButton = await screen.findByRole('button', { name: 'Publier le planning' })
    fireEvent.click(publishButton)
    expect(await screen.findByText('Publier ce planning ?')).toBeInTheDocument()
  })

  it('shows an incomplete coverage and an uncovered duty with its real reasons', async () => {
    stubResult([
      makeLine({
        coverageStatus: 'INCOMPLETE',
        requiredDutyCount: 2,
        coveredRequiredDutyCount: 1,
        uncoveredRequiredDutyCount: 1,
        duties: [
          resultDuty('a1', '2027-02-05'),
          resultDuty('b1', '2027-02-07', {
            dutyType: { stableId: 'dt2', code: 'NUIT', name: 'Nuit' },
            covered: false,
            assignment: null,
            reasons: REASONS,
          }),
        ],
      }),
    ])
    renderView()

    expect(await screen.findByText('Couverture incomplète')).toBeInTheDocument()
    expect(screen.getByText('1 / 2 gardes couvertes')).toBeInTheDocument()
    expect(screen.getByText('1 garde non couverte')).toBeInTheDocument()
    expect(await screen.findByText('NON COUVERTE')).toBeInTheDocument()

    fireEvent.click(screen.getByText('Pourquoi ?'))
    expect(screen.getByText('Alice Martin : indisponible')).toBeInTheDocument()
    expect(screen.getByText(/Bob Durand : indisponible, repos d.équipe insuffisant/)).toBeInTheDocument()
  })

  it('shows a neutral message instead of inventing a reason when none is known', async () => {
    stubResult([
      makeLine({
        coverageStatus: 'INCOMPLETE',
        requiredDutyCount: 1,
        coveredRequiredDutyCount: 0,
        uncoveredRequiredDutyCount: 1,
        duties: [resultDuty('a1', '2027-02-05', { covered: false, assignment: null, reasons: [] })],
      }),
    ])
    renderView()

    expect(await screen.findByText('Raison non disponible.')).toBeInTheDocument()
    expect(screen.queryByText('Pourquoi ?')).not.toBeInTheDocument()
  })

  it('explains that no generation exists yet, never a fake 0/0', async () => {
    stubResult([makeLine({ generationStableId: null, generatedAt: null, coverageStatus: null })])
    renderView()

    expect(
      await screen.findByText("Aucun planning n'a encore été généré pour cette période."),
    ).toBeInTheDocument()
    expect(screen.queryByText(/Couverture/)).not.toBeInTheDocument()
  })

  it('reports each line independently when there are several', async () => {
    const multiLine: PlanningDetail = {
      ...PLANNING,
      lines: [
        ...PLANNING.lines,
        {
          stableId: 'l2',
          name: 'Renfort',
          type: 'SECONDARY',
          position: 2,
          active: true,
          team: { stableId: 't2', name: 'Renfort' },
          planningPeriodStableId: 'pp2',
          createdAt: '',
          updatedAt: '',
        },
      ],
    }
    stubApi({
      'GET /api/plannings/plan-1/teams/t1/members': () => MEMBERS,
      'GET /api/plannings/plan-1/teams/t2/members': () => [],
      'GET /api/plannings/plan-1/result': () => ({
        planningStableId: 'plan-1',
        lines: [
          makeLine({
            requiredDutyCount: 1,
            coveredRequiredDutyCount: 1,
            duties: [resultDuty('a1', '2027-02-05')],
          }),
          makeLine({
            lineStableId: 'l2',
            lineName: 'Renfort',
            generationStableId: null,
            generatedAt: null,
            coverageStatus: null,
            duties: [],
          }),
        ],
      }),
    })
    render(<PersonalPlanningView planning={multiLine} />)

    expect(await screen.findByText(/Seniors : 1 \/ 1/)).toBeInTheDocument()
    expect(screen.getByText('Renfort : aucune génération pour le moment.')).toBeInTheDocument()
    // The duty itself carries its line's name once several lines exist.
    expect(screen.getByText('Seniors', { selector: '.duty__line' })).toBeInTheDocument()
  })

  it('says so when there is nothing this month, distinctly from "no generation at all"', async () => {
    stubResult([makeLine({ requiredDutyCount: 1, coveredRequiredDutyCount: 1, duties: [] })])
    renderView()

    await screen.findByText('Couverture complète')
    expect(await screen.findByText('Aucune garde en février 2027.')).toBeInTheDocument()
  })
})

describe('PersonalPlanningView — one person (unchanged)', () => {
  it("shows only the selected person's duties and their summary", async () => {
    const api = stubAssignments()
    renderView()
    await screen.findByRole('option', { name: 'Alice Martin' })

    fireEvent.change(screen.getByLabelText('Afficher'), { target: { value: 'u-alice' } })

    await waitFor(() => expect(screen.getByText('Gardes', { selector: 'dt' })).toBeInTheDocument())
    const list = await screen.findByRole('list', { name: 'Gardes du mois' })
    expect(within(list).queryByText('Bob Durand')).not.toBeInTheDocument()
    // Two of Alice's three duties are in February; her own name is not repeated on each row.
    expect(within(list).getAllByText(/Nuit|Jour/)).toHaveLength(2)
    const summary = screen.getByText('Gardes', { selector: 'dt' }).closest('dl') as HTMLElement
    expect(within(summary).getByText('3')).toBeInTheDocument() // whole planning, not the month
    expect(within(summary).getByText('3.5')).toBeInTheDocument()
    expect(screen.getByText(/Sur l.ensemble du planning/)).toBeInTheDocument()
    expect(api.requests('GET', '/api/plannings/plan-1/assignments').at(-1)?.query.get('userStableId')).toBe(
      'u-alice',
    )
  })

  it('goes back to the whole team', async () => {
    stubApi({
      'GET /api/plannings/plan-1/teams/t1/members': () => MEMBERS,
      'GET /api/plannings/plan-1/assignments': ({ query }) => {
        const user = query.get('userStableId')
        const assignments = ALL.filter((a) => !user || a.user.stableId === user)
        return {
          generations: [
            {
              stableId: 'g1',
              lineStableId: 'l1',
              lineName: 'Seniors',
              generatedAt: null,
              coverageStatus: 'COMPLETE',
            },
          ],
          assignments,
          summary: user
            ? {
                userStableId: user,
                totalDuties: assignments.length,
                weightedWorkload: 3.5,
                fridays: 1,
                saturdays: 1,
                sundays: 0,
                weekendDays: 1,
                byDutyType: [],
              }
            : null,
        }
      },
      'GET /api/plannings/plan-1/result': () => ({
        planningStableId: 'plan-1',
        lines: [
          makeLine({
            requiredDutyCount: 1,
            coveredRequiredDutyCount: 1,
            duties: [
              resultDuty('b1', '2027-02-07', {
                assignment: {
                  stableId: 'dr-b1',
                  source: 'AUTO',
                  locked: false,
                  user: { stableId: 'u-bob', firstName: 'Bob', lastName: 'Durand' },
                },
              }),
            ],
          }),
        ],
      }),
    })
    renderView()
    await screen.findByRole('option', { name: 'Alice Martin' })
    fireEvent.change(screen.getByLabelText('Afficher'), { target: { value: 'u-alice' } })
    await screen.findByRole('button', { name: "Voir toute l'équipe" })

    fireEvent.click(screen.getByRole('button', { name: "Voir toute l'équipe" }))

    await waitFor(() => expect(screen.getByLabelText('Afficher')).toHaveValue(''))
    const list = await screen.findByRole('list', { name: 'Gardes du mois' })
    await waitFor(() => expect(within(list).getByText('Bob Durand')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: "Voir toute l'équipe" })).not.toBeInTheDocument()
  })

  it('navigates month by month and stays inside the planning', async () => {
    const api = stubAssignments()
    renderView()
    await screen.findByRole('option', { name: 'Alice Martin' })
    fireEvent.change(screen.getByLabelText('Afficher'), { target: { value: 'u-alice' } })
    await screen.findByText('Février 2027')

    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))
    expect(await screen.findByText('Mars 2027')).toBeInTheDocument()
    await waitFor(() => {
      const last = api.requests('GET', '/api/plannings/plan-1/assignments').at(-1)
      expect(last?.query.get('from')).toBe('2027-03-01')
      expect(last?.query.get('to')).toBe('2027-04-01')
    })

    fireEvent.click(screen.getByRole('button', { name: 'Mois précédent' }))
    fireEvent.click(screen.getByRole('button', { name: 'Mois précédent' }))
    expect(await screen.findByText('Janvier 2027')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Mois précédent' })).toBeDisabled()

    // The planning ends on 1 May (exclusive): April is its last month.
    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))
    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))
    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))
    expect(await screen.findByText('Avril 2027')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Mois suivant' })).toBeDisabled()
  })

  it('says so when a person has no duty this month', async () => {
    stubAssignments()
    renderView()
    await screen.findByRole('option', { name: 'Bob Durand' })
    fireEvent.change(screen.getByLabelText('Afficher'), { target: { value: 'u-bob' } })
    await screen.findByRole('button', { name: "Voir toute l'équipe" })

    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))

    expect(await screen.findByText(/Bob Durand n'a aucune garde en mars 2027/)).toBeInTheDocument()
  })

  it('explains that there is nothing to show before a generation is completed', async () => {
    stubAssignments({ generations: 0 })
    renderView()
    await screen.findByRole('option', { name: 'Alice Martin' })
    fireEvent.change(screen.getByLabelText('Afficher'), { target: { value: 'u-alice' } })

    expect(await screen.findByText(/Aucune génération terminée/)).toBeInTheDocument()
  })
})
