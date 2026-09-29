import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../testUtils/stubApi'
import { PlanningsPage } from './PlanningsPage'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function openForm() {
  const api = stubApi({
    'GET /api/plannings': () => [],
    'POST /api/plannings': () => ({ stableId: 'new' }),
  })
  render(
    <MemoryRouter>
      <PlanningsPage />
    </MemoryRouter>,
  )
  return api
}

async function fillAndSubmit() {
  fireEvent.click(await screen.findByRole('button', { name: 'Créer un planning' }))
  fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Gardes 2027' } })
  fireEvent.change(screen.getByLabelText('Début'), { target: { value: '2027-01-01' } })
  fireEvent.change(screen.getByLabelText('Fin'), { target: { value: '2027-05-01' } })
  fireEvent.change(screen.getByLabelText("Nom de l'équipe principale"), { target: { value: 'Seniors' } })
}

describe('PlanningsPage — creator participation', () => {
  it('offers "M\'inclure dans le planning", ticked, independent of the management rights', async () => {
    openForm()
    await fillAndSubmit()

    const box = screen.getByRole('checkbox', { name: /M'inclure dans le planning/ })
    expect(box).toBeChecked()
    expect(screen.getByText(/Cela ne change rien à vos droits de gestion/)).toBeInTheDocument()
  })

  it('creates the planning with the creator included by default', async () => {
    const api = openForm()
    await fillAndSubmit()

    fireEvent.click(screen.getByRole('button', { name: 'Créer' }))

    await waitFor(() => expect(api.requests('POST', '/api/plannings')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings')[0].body).toMatchObject({
      name: 'Gardes 2027',
      primaryTeam: { name: 'Seniors' },
      includeMe: true,
    })
  })

  it('creates the planning without the creator when the box is unticked', async () => {
    const api = openForm()
    await fillAndSubmit()

    fireEvent.click(screen.getByRole('checkbox', { name: /M'inclure dans le planning/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Créer' }))

    await waitFor(() => expect(api.requests('POST', '/api/plannings')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings')[0].body).toMatchObject({ includeMe: false })
  })

  it('states that the end date is exclusive', async () => {
    openForm()
    await fillAndSubmit()

    expect(screen.getByText(/Date exclue/)).toBeInTheDocument()
  })
})

function summary(overrides: Record<string, unknown>) {
  return {
    stableId: String(overrides.name),
    creatorStableId: 'c',
    canManage: true,
    lineCount: 2,
    memberCount: 17,
    published: false,
    collecting: false,
    ...overrides,
  }
}

function renderList(plannings: unknown[]) {
  stubApi({ 'GET /api/plannings': () => plannings })
  render(
    <MemoryRouter>
      <PlanningsPage />
    </MemoryRouter>,
  )
}

describe('PlanningsPage — list (maquette react_mes_plannings)', () => {
  afterEach(() => vi.useRealTimers())

  function at(date: Date) {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(date)
  }

  it('shows the empty state when there is no planning', async () => {
    renderList([])

    expect(await screen.findByText('Aucun planning')).toBeInTheDocument()
  })

  it('describes a single upcoming planning, its end date shown inclusively, without group titles', async () => {
    at(new Date(2026, 8, 26, 9, 0))
    // endsAt is exclusive: the planning ends on 31 January.
    renderList([
      summary({ name: 'Trauma Delta', startsAt: '2026-10-01', endsAt: '2027-02-01', collecting: true }),
    ])

    const card = await screen.findByRole('link', { name: /Trauma Delta/ })
    expect(card).toHaveAttribute('href', '/plannings/Trauma Delta')
    expect(card).toHaveTextContent('Commence dans 5 jours')
    expect(card).toHaveTextContent('J-5')
    expect(card).toHaveTextContent('Jeu. 1 oct. 2026 → dim. 31 janv. 2027')
    expect(card).toHaveTextContent('123 jours')
    expect(card).toHaveTextContent('2 lignes')
    expect(card).toHaveTextContent('17 membres')
    expect(card).toHaveTextContent('Collecte des indispos ouverte')
    expect(screen.queryByRole('heading', { name: /À venir/ })).not.toBeInTheDocument()
  })

  it('groups several plannings into En cours / À venir / Terminés, finished ones most recent first', async () => {
    at(new Date(2026, 8, 26, 9, 0))
    renderList([
      summary({ name: 'Trauma Gamma', startsAt: '2026-04-01', endsAt: '2026-07-01', published: true }),
      summary({ name: 'Ancien', startsAt: '2026-01-01', endsAt: '2026-03-01', published: true }),
      summary({
        name: 'Urgences Nord',
        startsAt: '2026-07-01',
        endsAt: '2027-01-01',
        published: true,
        lineCount: 1,
      }),
      summary({ name: 'Trauma Delta', startsAt: '2026-10-01', endsAt: '2027-02-01' }),
    ])

    const live = await screen.findByRole('region', { name: 'En cours' })
    expect(live).toHaveTextContent(/En cours1/)
    expect(live).toHaveTextContent('En cours · jour 88 sur 184')
    expect(live).toHaveTextContent('97jours restants')
    expect(live).toHaveTextContent('1 ligne')
    expect(live).toHaveTextContent('Publié')

    expect(screen.getByRole('region', { name: 'À venir' })).toHaveTextContent('À générer')

    const done = screen.getByRole('region', { name: 'Terminés' })
    const names = Array.from(done.querySelectorAll('.mp-card-name')).map((n) => n.textContent)
    expect(names).toEqual(['Trauma Gamma', 'Ancien'])
    expect(done).toHaveTextContent('Terminé')
  })

  it('says "Commence demain" the day before the start', async () => {
    at(new Date(2026, 8, 30, 9, 0))
    renderList([summary({ name: 'Demain', startsAt: '2026-10-01', endsAt: '2026-11-01' })])

    expect(await screen.findByRole('link', { name: /Demain/ })).toHaveTextContent('Commence demain')
  })
})
