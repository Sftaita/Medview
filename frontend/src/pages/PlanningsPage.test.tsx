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
    periodCount: 1,
    currentPeriod: null,
    nextPeriod: null,
    ...overrides,
  }
}

/** A period as GET /api/plannings summarises it (docs/planning-cycles.md §7.1); `endsAt` exclusive. */
function period(
  number: number,
  startsAt: string,
  endsAt: string,
  status: string,
  overrides: Record<string, unknown> = {},
) {
  const last = new Date(`${endsAt}T00:00:00Z`)
  last.setUTCDate(last.getUTCDate() - 1)
  return {
    stableId: `c${number}`,
    number,
    startsAt,
    endsAt,
    lastDay: last.toISOString().slice(0, 10),
    status,
    timeState: 'current',
    lineCounts: { counting: 2, published: status === 'published' ? 2 : 0, incomplete: 0, withoutDemand: 0 },
    publishedAt: null,
    lastPublishedAt: null,
    generatedAt: null,
    collections: { open: 0, closed: 0, acknowledged: 0, expected: 0, deadline: null, uncoveredDays: 0 },
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

function cardOf(name: string): HTMLElement {
  return screen.getByRole('heading', { name }).closest('article')!
}

describe('PlanningsPage — list (one card per planning, its periods — lot L6)', () => {
  afterEach(() => vi.useRealTimers())

  function at(date: Date) {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(date)
  }

  it('shows the empty state when there is no planning', async () => {
    renderList([])

    expect(await screen.findByText('Aucun planning')).toBeInTheDocument()
  })

  it('keeps a single-period planning simple: its period, its status, its last day included, no period count', async () => {
    at(new Date(2026, 8, 26, 9, 0))
    const p1 = period(1, '2026-10-01', '2027-02-01', 'collecting', {
      timeState: 'future',
      collections: { open: 1, closed: 0, acknowledged: 3, expected: 17, deadline: null, uncoveredDays: 0 },
    })
    renderList([
      summary({
        name: 'Trauma Delta',
        startsAt: '2026-10-01',
        endsAt: '2027-02-01',
        today: '2026-09-26',
        nextPeriod: p1,
        latestPeriod: p1,
      }),
    ])

    const link = await screen.findByRole('link', { name: 'Ouvrir le planning Trauma Delta' })
    expect(link).toHaveAttribute('href', '/plannings/Trauma Delta')
    const card = cardOf('Trauma Delta')
    expect(card).toHaveTextContent('Propriétaire')
    expect(card).toHaveTextContent('2 lignes de garde · 17 membres')
    expect(card).not.toHaveTextContent(/périodes?\b.*·|1 période/)
    expect(card).toHaveTextContent('Période à venir')
    expect(card).toHaveTextContent('1 oct. 2026 → 31 janv. 2027')
    expect(card).toHaveTextContent('Collecte en cours')
    expect(card).toHaveTextContent('Commence dans 5 jours')
    expect(screen.queryByRole('heading', { name: /À venir/ })).not.toBeInTheDocument()
  })

  it('shows one card for a planning of several periods: the current one and the next, each with its own status', async () => {
    at(new Date(2026, 9, 10, 9, 0))
    renderList([
      summary({
        name: 'Trauma Delta',
        canManage: false,
        canPublish: true,
        published: true,
        startsAt: '2026-06-01',
        endsAt: '2027-05-01',
        today: '2026-10-10',
        periodCount: 3,
        currentPeriod: period(2, '2026-10-01', '2027-01-31', 'published', {
          publishedAt: '2026-09-20T08:00:00+00:00',
        }),
        nextPeriod: period(3, '2027-01-31', '2027-05-01', 'preparation', { timeState: 'future' }),
        latestPeriod: period(3, '2027-01-31', '2027-05-01', 'preparation', { timeState: 'future' }),
      }),
    ])

    await screen.findByRole('heading', { name: 'Trauma Delta' })
    expect(screen.getAllByRole('article')).toHaveLength(1)
    const card = cardOf('Trauma Delta')
    expect(card).toHaveTextContent('Gestionnaire')
    expect(card).toHaveTextContent('2 lignes de garde · 17 membres · 3 périodes')
    expect(card).toHaveTextContent('Période 2 · en cours')
    expect(card).toHaveTextContent('Jour 10 sur 122')
    expect(card).toHaveTextContent('Période 3 · suivante')
    expect(card).toHaveTextContent('En préparation')
    expect(card).toHaveTextContent('Prochaine étape : ouvrir la collecte des disponibilités.')
    // Never one global "Publié" for the planning: "Publiée" belongs to period 2.
    expect(card).not.toHaveTextContent(/Publié(?!e)/)
    expect(card).toHaveTextContent('Publiée')
  })

  it('says when a period is only partly published', async () => {
    at(new Date(2026, 9, 10, 9, 0))
    renderList([
      summary({
        name: 'Urgences',
        startsAt: '2026-10-01',
        endsAt: '2027-01-01',
        today: '2026-10-10',
        currentPeriod: period(1, '2026-10-01', '2027-01-01', 'partially_published'),
        latestPeriod: period(1, '2026-10-01', '2027-01-01', 'partially_published'),
      }),
    ])

    expect(await screen.findByText('Partiellement publiée')).toBeInTheDocument()
  })

  it('groups several plannings into En cours / À venir / Terminés, a finished one showing its last period', async () => {
    at(new Date(2026, 8, 26, 9, 0))
    const done = (name: string, startsAt: string, endsAt: string) =>
      summary({
        name,
        startsAt,
        endsAt,
        today: '2026-09-26',
        periodCount: 2,
        latestPeriod: period(2, startsAt, endsAt, 'published', { timeState: 'past' }),
      })
    renderList([
      done('Trauma Gamma', '2026-04-01', '2026-07-01'),
      done('Ancien', '2026-01-01', '2026-03-01'),
      summary({
        name: 'Urgences Nord',
        startsAt: '2026-07-01',
        endsAt: '2027-01-01',
        today: '2026-09-26',
        lineCount: 1,
        currentPeriod: period(1, '2026-07-01', '2027-01-01', 'published'),
        latestPeriod: period(1, '2026-07-01', '2027-01-01', 'published'),
      }),
      summary({
        name: 'Trauma Delta',
        startsAt: '2026-10-01',
        endsAt: '2027-02-01',
        today: '2026-09-26',
        nextPeriod: period(1, '2026-10-01', '2027-02-01', 'preparation', { timeState: 'future' }),
        latestPeriod: period(1, '2026-10-01', '2027-02-01', 'preparation', { timeState: 'future' }),
      }),
    ])

    const live = await screen.findByRole('region', { name: 'En cours' })
    expect(live).toHaveTextContent(/En cours1/)
    expect(live).toHaveTextContent('Jour 88 sur 184')
    expect(live).toHaveTextContent('96 jours restants')
    expect(live).toHaveTextContent('1 ligne de garde')
    expect(live).toHaveTextContent('Publiée')

    expect(screen.getByRole('region', { name: 'À venir' })).toHaveTextContent('En préparation')

    const finished = screen.getByRole('region', { name: 'Terminés' })
    const names = Array.from(finished.querySelectorAll('article h2')).map((n) => n.textContent)
    expect(names).toEqual(['Trauma Gamma', 'Ancien'])
    expect(finished).toHaveTextContent('Période 2 · terminée')
  })

  it('says "Commence demain" the day before the start', async () => {
    at(new Date(2026, 8, 30, 9, 0))
    const p1 = period(1, '2026-10-01', '2026-11-01', 'preparation', { timeState: 'future' })
    renderList([
      summary({
        name: 'Demain',
        startsAt: '2026-10-01',
        endsAt: '2026-11-01',
        today: '2026-09-30',
        nextPeriod: p1,
        latestPeriod: p1,
      }),
    ])

    await screen.findByRole('heading', { name: 'Demain' })
    expect(cardOf('Demain')).toHaveTextContent('Commence demain')
  })
})
