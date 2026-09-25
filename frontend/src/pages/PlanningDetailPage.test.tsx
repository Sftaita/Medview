import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { PlanningDetail, PlanningLineSummary } from '../features/planning/types'
import { makePreflight, makeRow, makeStatus } from '../testUtils/pilotFixtures'
import { status as httpStatus, stubApi, type Route as ApiRoute } from '../testUtils/stubApi'
import { PlanningDetailPage } from './PlanningDetailPage'

vi.mock('../features/auth/useAuth', () => ({
  useAuth: () => ({ user: { stableId: 'u-1', firstName: 'Camille', lastName: 'Dupont' } }),
}))

beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function line(overrides: Partial<PlanningLineSummary> = {}): PlanningLineSummary {
  return {
    stableId: 'line-1',
    name: 'Première ligne',
    type: 'PRIMARY',
    position: 0,
    active: true,
    team: { stableId: 'team-1', name: 'Seniors', canInvite: true },
    memberCount: 3,
    planningPeriodStableId: 'period-1',
    periodStatus: 'DRAFT',
    createdAt: '',
    updatedAt: '',
    ...overrides,
  }
}

function planning(overrides: Partial<PlanningDetail> = {}): PlanningDetail {
  return {
    stableId: 'plan-1',
    name: 'Trauma Delta',
    creatorStableId: 'u-1',
    canManage: true,
    canManageAvailability: true,
    canGenerate: true,
    participating: true,
    startsAt: '2026-10-01',
    // Exclusive: the last day is 31 January.
    endsAt: '2027-02-01',
    timezone: 'Europe/Brussels',
    createdAt: '',
    updatedAt: '',
    lines: [
      line(),
      line({
        stableId: 'line-2',
        name: 'Assistant',
        type: 'SECONDARY',
        team: { stableId: 'team-2', name: 'Assistants' },
        memberCount: 1,
      }),
    ],
    ...overrides,
  }
}

function renderPage(detail: PlanningDetail = planning(), extra: Record<string, ApiRoute> = {}) {
  const api = stubApi({
    'GET /api/plannings/plan-1': () => detail,
    'GET /api/plannings/plan-1/collection-status': () => makeStatus(),
    'GET /api/plannings/plan-1/availability-collections': () => [],
    ...extra,
  })
  render(
    <MemoryRouter initialEntries={['/plannings/plan-1']}>
      <Routes>
        <Route path="/plannings/:planningId" element={<PlanningDetailPage />} />
      </Routes>
    </MemoryRouter>,
  )
  return api
}

describe('PlanningDetailPage — header and period', () => {
  it('shows the name, the real status, participation and the inclusive last day', async () => {
    renderPage()

    expect(await screen.findByRole('heading', { level: 1, name: 'Trauma Delta' })).toBeInTheDocument()
    expect(screen.getByText('Brouillon', { selector: '.pd-badge' })).toBeInTheDocument()
    expect(screen.getByText('Vous en faites partie')).toBeInTheDocument()

    const period = screen.getByRole('region', { name: 'Période du planning' })
    expect(within(period).getByText(/1 oct\. 2026/)).toBeInTheDocument()
    // endsAt 2027-02-01 is exclusive: the planning stops on 31 January.
    expect(within(period).getByText(/31 janv\. 2027/)).toBeInTheDocument()
    expect(within(period).getByText('123 jours')).toBeInTheDocument()
    expect(within(period).getByText('Fuseau horaire : Europe/Brussels')).toBeInTheDocument()
  })

  it('shows the published status, and offers to include a creator who does not take part', async () => {
    const api = renderPage(planning({ participating: false, lines: [line({ periodStatus: 'PUBLISHED' })] }), {
      'POST /api/plannings/plan-1/teams/team-1/members': () => ({}),
    })

    expect(await screen.findByText('Publié', { selector: '.pd-badge' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: "M'inclure dans ce planning" }))
    await waitFor(() =>
      expect(api.requests('POST', '/api/plannings/plan-1/teams/team-1/members')).toHaveLength(1),
    )
    expect(api.requests('POST', '/api/plannings/plan-1/teams/team-1/members')[0].body).toMatchObject({
      userStableId: 'u-1',
      role: 'OWNER',
    })
  })

  it('renames the planning from the ⋯ menu', async () => {
    const api = renderPage(planning(), {
      'PATCH /api/plannings/plan-1': () => planning({ name: 'Trauma Omega' }),
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Plus d’actions' }))
    fireEvent.click(screen.getByRole('menuitem', { name: 'Modifier le nom' }))
    const dialog = screen.getByRole('dialog', { name: 'Modifier le nom' })
    fireEvent.change(within(dialog).getByLabelText('Nouveau nom'), { target: { value: 'Trauma Omega' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(api.requests('PATCH', '/api/plannings/plan-1')[0].body).toEqual({ name: 'Trauma Omega' })
  })

  it('opens the extension in a dialog from the period card', async () => {
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Prolonger la période' }))
    const dialog = screen.getByRole('dialog', { name: 'Prolonger la période' })
    expect(within(dialog).getByLabelText('Nouvelle fin')).toBeInTheDocument()
    fireEvent.keyDown(window, { key: 'Escape' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('gives a plain member no management action at all', async () => {
    renderPage(planning({ canManage: false, canManageAvailability: false, canGenerate: false }), {
      'GET /api/me/availability-collections': () => [],
    })

    await screen.findByRole('heading', { level: 1, name: 'Trauma Delta' })
    expect(screen.queryByRole('button', { name: 'Générer le planning' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Plus d’actions' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Prolonger la période' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ajouter une ligne' })).not.toBeInTheDocument()
  })
})

describe('PlanningDetailPage — steps and tabs', () => {
  it('summarises team, collection and generation, and each step opens its tab', async () => {
    renderPage()

    const steps = await screen.findByRole('region', { name: 'Étapes du planning' })
    await within(steps).findByText(/1\/3 confirmés/)
    expect(within(steps).getByText('2 lignes · 4 membres')).toBeInTheDocument()
    expect(within(steps).getByText('Pas encore lancée')).toBeInTheDocument()

    fireEvent.click(within(steps).getByRole('button', { name: /Indisponibilités/ }))
    expect(screen.getByRole('tab', { name: /Indisponibilités/ })).toHaveAttribute('aria-selected', 'true')
    expect(await screen.findByText('1 / 3 membres ont confirmé leurs disponibilités.')).toBeInTheDocument()

    fireEvent.click(within(steps).getByRole('button', { name: /Génération/ }))
    expect(screen.getByRole('tab', { name: /Planning/ })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByText('Pas encore de planning')).toBeInTheDocument()
  })

  it('names each tab the same way at every width (the short label is only visual)', async () => {
    renderPage()

    await screen.findByRole('heading', { level: 1, name: 'Trauma Delta' })
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Indisponibilités (3)' })).toBeInTheDocument())
    expect(screen.getByRole('tab', { name: 'Lignes de garde (2)' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Planning' })).toBeInTheDocument()
  })

  it('shows the per-person planning once a line is generated, instead of the empty state', async () => {
    const failing = () => httpStatus(500)
    renderPage(planning({ lines: [line({ periodStatus: 'GENERATED' })] }), {
      'GET /api/plannings/plan-1/result': failing,
      'GET /api/plannings/plan-1/assignments': failing,
      'GET /api/plannings/plan-1/statistics': failing,
      'GET /api/plannings/plan-1/teams/team-1/members': () => [],
    })

    expect(await screen.findByText('Généré', { selector: '.pd-badge' })).toBeInTheDocument()
    const steps = screen.getByRole('region', { name: 'Étapes du planning' })
    expect(within(steps).getByText('Planning généré')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: 'Planning' }))
    expect(await screen.findByRole('region', { name: 'Planning par personne' })).toBeInTheDocument()
    expect(screen.queryByText('Pas encore de planning')).not.toBeInTheDocument()
  })

  it('opens the same generation dialog from the empty Planning tab', async () => {
    renderPage(planning(), { 'GET /api/plannings/plan-1/generation-preflight': () => makePreflight() })

    fireEvent.click(await screen.findByRole('tab', { name: /Planning/ }))
    const empty = screen.getByText('Pas encore de planning').closest('div')!
    fireEvent.click(within(empty).getByRole('button', { name: 'Générer le planning' }))

    expect(await screen.findByRole('dialog')).toBeInTheDocument()
  })

  it('opens the planning settings from "Fixer une date souhaitée"', async () => {
    renderPage()

    fireEvent.click(await screen.findByRole('tab', { name: /Indisponibilités/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Fixer une date souhaitée' }))

    expect(await screen.findByLabelText('Fin souhaitée d’encodage des indisponibilités')).toBeInTheDocument()
  })

  it('shows the collection history on demand', async () => {
    const api = renderPage()

    fireEvent.click(await screen.findByRole('tab', { name: /Indisponibilités/ }))
    const toggle = await screen.findByRole('button', { name: /Collectes par fenêtre/ })
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    fireEvent.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
    await waitFor(() =>
      expect(api.requests('GET', '/api/plannings/plan-1/availability-collections')).toHaveLength(1),
    )
  })
})

describe('PlanningDetailPage — lines', () => {
  it('lists the lines with their kind and members; only a secondary line can be deleted', async () => {
    const api = renderPage(planning(), { 'DELETE /api/plannings/plan-1/lines/line-2': () => null })

    const lines = await screen.findByRole('list', { name: 'Lignes de garde' })
    expect(within(lines).getByText('Principale')).toBeInTheDocument()
    expect(within(lines).getByText('Secondaire')).toBeInTheDocument()
    expect(within(lines).getByText('Seniors · 3 membres')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Actions pour Première ligne' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Actions pour Assistant' }))
    fireEvent.click(screen.getByRole('menuitem', { name: 'Supprimer la ligne' }))
    await waitFor(() => expect(api.requests('DELETE', '/api/plannings/plan-1/lines/line-2')).toHaveLength(1))
  })

  it('adds a line with a name', async () => {
    const api = renderPage(planning(), {
      'POST /api/plannings/plan-1/lines': () => line({ stableId: 'line-3' }),
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter une ligne' }))
    fireEvent.change(screen.getByLabelText('Nom de la ligne'), { target: { value: 'Astreinte' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/lines')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/lines')[0].body).toEqual({ name: 'Astreinte' })
    await waitFor(() => expect(screen.getByRole('button', { name: 'Ajouter une ligne' })).toBeInTheDocument())
  })

  it('explains a refused line', async () => {
    renderPage(planning(), { 'POST /api/plannings/plan-1/lines': () => httpStatus(409) })

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter une ligne' }))
    fireEvent.change(screen.getByLabelText('Nom de la ligne'), { target: { value: 'Astreinte' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Cette équipe a déjà un planning sur cette période.',
    )
    expect(screen.getByLabelText('Nom de la ligne')).toHaveValue('Astreinte')
  })

  it('opens the members of a line, and ends a membership', async () => {
    const api = renderPage(planning(), {
      'GET /api/plannings/plan-1/teams/team-1/members': () => [
        {
          stableId: 'mem-1',
          firstName: 'Camille',
          lastName: 'Dupont',
          role: 'OWNER',
          membershipStart: '2026-10-01',
          membershipEnd: null,
        },
      ],
      'GET /api/plannings/plan-1/teams/team-1/invitations': () => [],
      'POST /api/plannings/plan-1/teams/team-1/members/mem-1/end': () => ({}),
    })

    const lines = await screen.findByRole('list', { name: 'Lignes de garde' })
    fireEvent.click(within(lines).getAllByRole('button', { name: 'Membres' })[0])

    const dialog = screen.getByRole('dialog', { name: 'Membres · Première ligne' })
    expect(await within(dialog).findByText('Camille Dupont')).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: "Terminer l'adhésion" }))
    await waitFor(() =>
      expect(api.requests('POST', '/api/plannings/plan-1/teams/team-1/members/mem-1/end')).toHaveLength(1),
    )
  })

  it("draws the current user's initials apart among a line's members", async () => {
    renderPage(planning(), {
      'GET /api/plannings/plan-1/collection-status': () =>
        makeStatus({ members: [makeRow('1', 'Dupont'), makeRow('2', 'Martin')] }),
    })

    const lines = await screen.findByRole('list', { name: 'Lignes de garde' })
    await waitFor(() => expect(lines.querySelectorAll('.pd-face')).toHaveLength(2))
    expect(lines.querySelector('.pd-face.pd-avatar-me')).toHaveTextContent('CD')
  })
})

describe('PlanningDetailPage — loading errors', () => {
  it('says so when the planning does not exist', async () => {
    renderPage(planning(), { 'GET /api/plannings/plan-1': () => httpStatus(404) })
    expect(await screen.findByRole('alert')).toHaveTextContent('Planning introuvable.')
  })

  it('says so when access is refused', async () => {
    renderPage(planning(), { 'GET /api/plannings/plan-1': () => httpStatus(403) })
    expect(await screen.findByRole('alert')).toHaveTextContent("Vous n'avez pas accès à ce planning.")
  })
})
