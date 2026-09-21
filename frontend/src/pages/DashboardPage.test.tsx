import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { Link, MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../features/auth/AuthContext'
import { dayIndex } from '../features/availability/calendarAxis'
import { MyAvailabilityProvider } from '../features/availability/MyAvailabilityProvider'
import type { UserAvailabilityPeriod } from '../features/availability/types'
import { createFakeBackend, makeCollection } from '../testUtils/fakeBackend'
import { DashboardPage } from './DashboardPage'
import { MyAvailabilityPage } from './MyAvailabilityPage'

const PLANNING = {
  stableId: 'p1',
  name: 'Gardes 2026-2027',
  startsAt: '2026-09-01',
  endsAt: '2027-02-28',
  creatorStableId: 'u1',
  canManage: true,
  timezone: 'Europe/Brussels',
  createdAt: '',
  updatedAt: '',
}

function period(
  stableId: string,
  type: 'UNAVAILABLE' | 'PREFER_DUTY',
  from: Date,
  to: Date,
): UserAvailabilityPeriod {
  return {
    stableId,
    type,
    startsAt: from.toISOString(),
    endsAt: to.toISOString(),
    createdAt: '',
    updatedAt: '',
  }
}

function renderDashboard() {
  render(
    <MemoryRouter>
      <AuthProvider>
        <MyAvailabilityProvider>
          <DashboardPage />
        </MyAvailabilityProvider>
      </AuthProvider>
    </MemoryRouter>,
  )
}

/** The dashboard and the calendar under one provider, like the authenticated shell. */
function renderApp() {
  render(
    <MemoryRouter initialEntries={['/my-availability']}>
      <AuthProvider>
        <MyAvailabilityProvider>
          <nav>
            <Link to="/">Accueil</Link>
            <Link to="/my-availability">Calendrier</Link>
          </nav>
          <Routes>
            <Route path="/" element={<DashboardPage />} />
            <Route path="/my-availability" element={<MyAvailabilityPage />} />
          </Routes>
        </MyAvailabilityProvider>
      </AuthProvider>
    </MemoryRouter>,
  )
}

function frozenDecember() {
  // Only `Date` is faked: promises and timers keep running normally.
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-12-14T09:00:00Z'))
}

describe('DashboardPage', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('greets the user and lists their plannings and upcoming availability', async () => {
    const start = new Date()
    start.setDate(start.getDate() + 10)
    start.setHours(0, 0, 0, 0)
    const end = new Date(start)
    end.setDate(end.getDate() + 3)
    createFakeBackend({ plannings: [PLANNING], periods: [period('a1', 'UNAVAILABLE', start, end)] }).install()

    renderDashboard()

    expect(await screen.findByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: /Gardes 2026-2027/ })).toHaveAttribute(
      'href',
      '/plannings/p1',
    )
    expect(await screen.findByText(/3 jours/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ouvrir le calendrier' })).toHaveAttribute(
      'href',
      '/my-availability',
    )
  })

  it('shows empty states, never an error, when a block cannot be loaded', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) {
          return Promise.resolve(
            new Response(JSON.stringify({ token: 'access' }), {
              headers: { 'Content-Type': 'application/json' },
            }),
          )
        }
        if (url.endsWith('/api/me')) {
          return Promise.resolve(
            new Response(
              JSON.stringify({
                id: 1,
                stableId: 'u',
                email: 'a@b.c',
                firstName: 'Alice',
                lastName: 'M',
                active: true,
              }),
              { headers: { 'Content-Type': 'application/json' } },
            ),
          )
        }
        return Promise.resolve(
          new Response('{}', { status: 500, headers: { 'Content-Type': 'application/json' } }),
        )
      }),
    )
    renderDashboard()

    expect(await screen.findByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Aucun planning pour le moment.')).toBeInTheDocument())
    await waitFor(() =>
      expect(screen.getByText('Aucune indisponibilité ou préférence à venir.')).toBeInTheDocument(),
    )
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('shows the teams joined through an invitation, once', async () => {
    sessionStorage.setItem(
      'medvue.flash.joinedTeams',
      JSON.stringify([
        {
          teamStableId: 't1',
          teamName: 'Urgences',
          planningStableId: 'p1',
          planningName: 'Gardes 2026-2027',
        },
      ]),
    )
    createFakeBackend().install()
    renderDashboard()

    expect(await screen.findByRole('status')).toHaveTextContent('Votre compte a bien été créé.')
    expect(screen.getByRole('link', { name: 'Urgences' })).toHaveAttribute('href', '/plannings/p1')
    expect(sessionStorage.getItem('medvue.flash.joinedTeams')).toBeNull()
  })
})

describe('DashboardPage — synchronized with the calendar', () => {
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('reflects a change made in the calendar without reloading and without a new request', async () => {
    const backend = createFakeBackend()
    backend.install()
    // The server has not answered yet when we look at the dashboard: the change is already there.
    backend.hold('POST', '/api/me/calendar')
    renderApp()
    await waitFor(() => expect(screen.queryByText('Chargement…')).not.toBeInTheDocument())

    const target = new Date()
    target.setDate(target.getDate() + 5)
    const day = document.querySelector<HTMLElement>(
      `[data-day="${dayIndex(target.getFullYear(), target.getMonth(), target.getDate())}"]`,
    )
    // Pick a day that is on screen (the calendar opens on the current month).
    expect(day).not.toBeNull()
    fireEvent.click(day as HTMLElement)

    const calendarReadsBefore = backend.requests('GET', '/api/me/calendar').length
    fireEvent.click(screen.getByRole('link', { name: 'Accueil' }))

    expect(await screen.findByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument()
    expect(screen.queryByText('Aucune indisponibilité ou préférence à venir.')).not.toBeInTheDocument()
    expect(screen.getByText('Indisponible')).toBeInTheDocument()
    expect(backend.requests('GET', '/api/me/calendar')).toHaveLength(calendarReadsBefore)
  })

  it('reflects a deletion made in the calendar', async () => {
    const start = new Date()
    start.setDate(start.getDate() + 5)
    start.setHours(0, 0, 0, 0)
    const end = new Date(start)
    end.setDate(end.getDate() + 2)
    const backend = createFakeBackend({ periods: [period('a1', 'UNAVAILABLE', start, end)] })
    backend.install()
    renderApp()
    await screen.findByRole('button', { name: /^Retirer/ })

    fireEvent.click(screen.getByRole('button', { name: /^Retirer/ }))
    fireEvent.click(screen.getByRole('link', { name: 'Accueil' }))

    expect(await screen.findByText('Aucune indisponibilité ou préférence à venir.')).toBeInTheDocument()
    await waitFor(() => expect(backend.periods).toHaveLength(0))
  })

  it('gets a rolled-back edit back to what the server holds', async () => {
    const backend = createFakeBackend()
    backend.install()
    backend.failNext('POST', '/api/me/calendar', 409)
    renderApp()
    await waitFor(() => expect(screen.queryByText('Chargement…')).not.toBeInTheDocument())

    const target = new Date()
    target.setDate(target.getDate() + 5)
    fireEvent.click(
      document.querySelector<HTMLElement>(
        `[data-day="${dayIndex(target.getFullYear(), target.getMonth(), target.getDate())}"]`,
      ) as HTMLElement,
    )
    await screen.findByRole('alert')
    fireEvent.click(screen.getByRole('link', { name: 'Accueil' }))

    expect(await screen.findByText('Aucune indisponibilité ou préférence à venir.')).toBeInTheDocument()
  })
})

describe('DashboardPage — availability collections', () => {
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('shows a pending collection with its window, deadline and the way to answer', async () => {
    frozenDecember()
    createFakeBackend({ collections: [makeCollection()] }).install()

    renderDashboard()

    const callout = await screen.findByRole('region', { name: /Disponibilités janvier–mars 2027/ })
    expect(
      within(callout).getByText('Vos disponibilités sont attendues pour janvier–mars 2027'),
    ).toBeInTheDocument()
    expect(within(callout).getByText('À renseigner avant le 20 décembre')).toBeInTheDocument()
    expect(within(callout).getByText('Gardes 2026-2027')).toBeInTheDocument()
    expect(within(callout).getByRole('link', { name: 'Renseigner mes disponibilités' })).toHaveAttribute(
      'href',
      '/my-availability?collection=col-1',
    )
  })

  it('shows nothing when no collection is open', async () => {
    createFakeBackend().install()

    renderDashboard()

    await screen.findByRole('heading', { name: /Bonjour, Alice/ })
    expect(screen.queryByLabelText('Disponibilités attendues')).not.toBeInTheDocument()
  })

  it('lets someone with nothing to declare say so, and shows the confirmation at once', async () => {
    frozenDecember()
    const backend = createFakeBackend({ collections: [makeCollection()] })
    backend.install()
    renderDashboard()

    fireEvent.click(
      await screen.findByRole('button', { name: "Je n'ai aucune indisponibilité sur cette période" }),
    )

    expect(await screen.findByText(/Disponibilités confirmées le/)).toHaveTextContent('14/12')
    expect(screen.queryByText(/Vos disponibilités sont attendues/)).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Renseigner mes disponibilités' })).not.toBeInTheDocument()
    expect(backend.requests('POST', '/api/availability-collections/col-1/acknowledge')).toHaveLength(1)
  })

  it('does not offer "no unavailability" when absences already sit in the window', async () => {
    frozenDecember()
    createFakeBackend({
      collections: [makeCollection()],
      periods: [period('a1', 'UNAVAILABLE', new Date(2027, 0, 15), new Date(2027, 0, 18))],
    }).install()

    renderDashboard()

    await screen.findByRole('link', { name: 'Renseigner mes disponibilités' })
    expect(
      screen.queryByRole('button', { name: /aucune indisponibilité sur cette période/ }),
    ).not.toBeInTheDocument()
  })

  it('lists what is still to do before what has been confirmed', async () => {
    frozenDecember()
    createFakeBackend({
      collections: [
        makeCollection({
          stableId: 'done',
          startsAt: '2026-09-01',
          endsAt: '2027-01-01',
          lastDay: '2026-12-31',
          myResponse: {
            stableId: 'r0',
            status: 'ACKNOWLEDGED',
            acknowledgedAt: '2026-08-26T08:00:00+00:00',
            acknowledgementKind: 'CONFIRMED',
            lastAvailabilityChangeAt: null,
          },
        }),
        makeCollection(),
      ],
    }).install()

    renderDashboard()

    const regions = await screen.findAllByRole('region', { name: /^Disponibilités / })
    expect(regions.map((region) => region.getAttribute('data-collection'))).toEqual(['col-1', 'done'])
    expect(within(regions[1]).getByText(/Disponibilités confirmées le/)).toHaveTextContent('26/08')
  })

  it('says when the calendar was modified after the confirmation, without reopening it', async () => {
    frozenDecember()
    createFakeBackend({
      collections: [
        makeCollection({
          myResponse: {
            stableId: 'r1',
            status: 'ACKNOWLEDGED',
            acknowledgedAt: '2026-12-12T08:00:00+00:00',
            acknowledgementKind: 'CONFIRMED',
            lastAvailabilityChangeAt: '2026-12-13T08:00:00+00:00',
          },
        }),
      ],
    }).install()

    renderDashboard()

    const callout = await screen.findByRole('region', { name: /Disponibilités janvier–mars 2027/ })
    expect(within(callout).getByText(/Disponibilités confirmées le/)).toHaveTextContent('12/12')
    expect(within(callout).getByText(/calendrier modifié depuis le/)).toHaveTextContent('13/12')
    expect(within(callout).queryByRole('button', { name: /Confirmer/ })).not.toBeInTheDocument()
  })
})
