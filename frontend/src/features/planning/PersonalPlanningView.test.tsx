import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../../testUtils/stubApi'
import { PersonalPlanningView } from './PersonalPlanningView'
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

/** A backend that filters by `userStableId` and by [from, to) like the real one. */
function stubPlanning(options: { generations?: number } = {}) {
  return stubApi({
    'GET /api/plannings/plan-1/teams/t1/members': () => MEMBERS,
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

function renderView() {
  render(<PersonalPlanningView planning={PLANNING} />)
}

describe('PersonalPlanningView', () => {
  it('opens on the whole team for the current month', async () => {
    const api = stubPlanning()
    renderView()

    expect(await screen.findByText('Février 2027')).toBeInTheDocument()
    const list = await screen.findByRole('list', { name: 'Gardes du mois' })
    expect(within(list).getAllByText(/Martin|Durand/).length).toBe(3)
    expect(within(list).getByText('Bob Durand')).toBeInTheDocument()
    expect(screen.queryByText('Gardes', { selector: 'dt' })).not.toBeInTheDocument()
    expect(api.requests('GET', '/api/plannings/plan-1/assignments')[0].query.get('from')).toBe('2027-02-01')
    expect(api.requests('GET', '/api/plannings/plan-1/assignments')[0].query.get('to')).toBe('2027-03-01')
  })

  it("shows only the selected person's duties and their summary", async () => {
    const api = stubPlanning()
    renderView()
    await screen.findByRole('list', { name: 'Gardes du mois' })
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
    stubPlanning()
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
    const api = stubPlanning()
    renderView()
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
    stubPlanning()
    renderView()
    await screen.findByRole('option', { name: 'Bob Durand' })
    fireEvent.change(screen.getByLabelText('Afficher'), { target: { value: 'u-bob' } })
    await screen.findByRole('button', { name: "Voir toute l'équipe" })

    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))

    expect(await screen.findByText(/Bob Durand n'a aucune garde en mars 2027/)).toBeInTheDocument()
  })

  it('explains that there is nothing to show before a generation is completed', async () => {
    stubPlanning({ generations: 0 })
    renderView()

    expect(await screen.findByText(/Aucune génération terminée/)).toBeInTheDocument()
  })
})
