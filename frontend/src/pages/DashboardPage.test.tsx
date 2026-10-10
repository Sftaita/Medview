import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { Link, MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../features/auth/AuthContext'
import { dayIndex } from '../features/availability/calendarAxis'
import { MyAvailabilityProvider } from '../features/availability/MyAvailabilityProvider'
import type { UserAvailabilityPeriod } from '../features/availability/types'
import { createFakeBackend, makeCollection } from '../testUtils/fakeBackend'
import { makeMyDuty } from '../testUtils/myDuty'
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
    source: 'MANUAL',
    editable: true,
    startsAt: from.toISOString(),
    endsAt: to.toISOString(),
    createdAt: '',
    updatedAt: '',
  }
}

/** A whole-day period on local calendar days, both included (the API end is exclusive). */
function days(stableId: string, type: 'UNAVAILABLE' | 'PREFER_DUTY', from: string, lastDay: string) {
  const [y1, m1, d1] = from.split('-').map(Number)
  const [y2, m2, d2] = lastDay.split('-').map(Number)
  return period(stableId, type, new Date(y1, m1 - 1, d1), new Date(y2, m2 - 1, d2 + 1))
}

/** The dashboard mockup's day (docs/Design/react_dashboard): Saturday 26 September 2026. */
function frozenMockupDay() {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(2026, 8, 26, 9, 0))
}

const TRAUMA = {
  ...PLANNING,
  stableId: 'trauma',
  name: 'Trauma Delta',
  startsAt: '2026-10-01',
  // Exclusive: the planning runs up to 31 January 2027 included.
  endsAt: '2027-02-01',
  myLineName: 'Première ligne',
  memberCount: 17,
  published: false,
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

  it('greets the user with the date, as in the mockup', async () => {
    frozenMockupDay()
    createFakeBackend().install()

    renderDashboard()

    expect(await screen.findByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument()
    expect(screen.getByText('Samedi 26 septembre 2026')).toBeInTheDocument()
  })

  it('summarizes a planning: status, inclusive period, own line, head count, timeline', async () => {
    frozenMockupDay()
    createFakeBackend({
      plannings: [TRAUMA],
      periods: [
        days('u1', 'UNAVAILABLE', '2026-10-03', '2026-10-04'),
        days('u2', 'UNAVAILABLE', '2026-11-20', '2026-11-22'),
        // Past: neither listed nor marked.
        days('u0', 'UNAVAILABLE', '2026-09-10', '2026-09-11'),
      ],
    }).install()

    renderDashboard()

    const row = await screen.findByRole('link', { name: /Trauma Delta/ })
    expect(row).toHaveAttribute('href', '/plannings/trauma')
    expect(within(row).getByText('Commence dans 5 jours')).toBeInTheDocument()
    expect(within(row).getByText('Jeu. 1 oct. 2026 → dim. 31 janv. 2027')).toBeInTheDocument()
    expect(within(row).getByText('1 oct. 2026 → 31 janv. 2027')).toBeInTheDocument()
    expect(row).toHaveTextContent('123 jours · Première ligne · 17 membres')
    expect(Array.from(row.querySelectorAll('.db-timeline-labels span')).map((el) => el.textContent)).toEqual([
      'Oct.',
      'Nov.',
      'Déc.',
      'Janv.',
    ])
    await waitFor(() => expect(row.querySelectorAll('.db-timeline-mark')).toHaveLength(2))
    expect(within(row).getByText(/2 de vos indisponibilités tombent/)).toBeInTheDocument()
    expect(within(row).getByText(/Planning en préparation/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Tous les plannings/ })).toHaveAttribute('href', '/plannings')
  })

  it('tells a running and a finished planning apart, and leaves out the line of a non-participant', async () => {
    frozenMockupDay()
    createFakeBackend({
      plannings: [
        {
          ...PLANNING,
          stableId: 'run',
          name: 'En cours',
          startsAt: '2026-09-01',
          endsAt: '2026-10-01',
          myLineName: null,
          memberCount: 1,
        },
        { ...PLANNING, stableId: 'old', name: 'Ancien', startsAt: '2026-01-01', endsAt: '2026-03-01' },
      ],
    }).install()

    renderDashboard()

    const running = await screen.findByRole('link', { name: /En cours/ })
    expect(within(running).getByText('En cours · jour 26 sur 30')).toBeInTheDocument()
    expect(running).toHaveTextContent('30 jours · 1 membre')
    expect(within(screen.getByRole('link', { name: /Ancien/ })).getByText('Terminé')).toBeInTheDocument()
  })

  it('offers the duties of a published planning without following the planning link', async () => {
    frozenMockupDay()
    createFakeBackend({ plannings: [{ ...TRAUMA, published: true }] }).install()
    render(
      <MemoryRouter>
        <AuthProvider>
          <MyAvailabilityProvider>
            <Routes>
              <Route path="/" element={<DashboardPage />} />
              <Route path="/my-duties" element={<p>Page mes gardes</p>} />
              <Route path="/plannings/:id" element={<p>Page planning</p>} />
            </Routes>
          </MyAvailabilityProvider>
        </AuthProvider>
      </MemoryRouter>,
    )

    fireEvent.click(await screen.findByText('Voir mes gardes'))

    expect(await screen.findByText('Page mes gardes')).toBeInTheDocument()
    expect(screen.queryByText('Page planning')).not.toBeInTheDocument()
  })

  it('shows the next duty with its countdown, and how many follow', async () => {
    frozenMockupDay()
    createFakeBackend({
      plannings: [{ ...TRAUMA, published: true }],
      duties: [
        makeMyDuty(['2026-09-20']),
        makeMyDuty(['2026-10-03', '2026-10-04'], { blockName: 'Week-end', planningName: 'Trauma Delta' }),
        makeMyDuty(['2026-10-13']),
        makeMyDuty(['2026-11-02']),
      ],
    }).install()
    render(
      <MemoryRouter>
        <AuthProvider>
          <MyAvailabilityProvider>
            <Routes>
              <Route path="/" element={<DashboardPage />} />
              <Route path="/my-duties" element={<p>Page mes gardes</p>} />
            </Routes>
          </MyAvailabilityProvider>
        </AuthProvider>
      </MemoryRouter>,
    )

    const card = await screen.findByRole('region', { name: 'Prochaine garde' })
    const row = within(card).getByRole('link', { name: /Sam\. 3/ })
    expect(row).toHaveTextContent('Sam. 3 → dim. 4 oct.')
    expect(row).toHaveTextContent('Trauma Delta · Première ligne · Bloc Week-end')
    expect(row).toHaveTextContent('Dans 7 jours')
    expect(row).toHaveAttribute('href', '/plannings/p1')
    expect(card).toHaveTextContent('Puis 2 autres gardes à venir')
    expect(within(card).queryByText(/13 oct/)).not.toBeInTheDocument()

    fireEvent.click(within(card).getByRole('link', { name: /Mes gardes/ }))
    expect(await screen.findByText('Page mes gardes')).toBeInTheDocument()
  })

  it('shows no next-duty card without an upcoming duty', async () => {
    frozenMockupDay()
    createFakeBackend({ plannings: [TRAUMA], duties: [makeMyDuty(['2026-09-20'])] }).install()
    renderDashboard()

    await screen.findByText('Trauma Delta')
    expect(screen.queryByRole('region', { name: 'Prochaine garde' })).not.toBeInTheDocument()
  })

  it('counts SurgicalHub leave with the person’s own unavailability, as their union', async () => {
    frozenMockupDay()
    createFakeBackend({
      periods: [
        days('u1', 'UNAVAILABLE', '2026-10-03', '2026-10-04'),
        // Imported leave overlapping the manual one, and another of its own.
        {
          ...days('sh1', 'UNAVAILABLE', '2026-10-04', '2026-10-06'),
          source: 'SURGICAL_HUB',
          editable: false,
        },
        {
          ...days('sh2', 'UNAVAILABLE', '2026-11-26', '2026-11-26'),
          source: 'SURGICAL_HUB',
          editable: false,
        },
      ],
    }).install()

    renderDashboard()

    const card = await screen.findByRole('region', { name: 'Mes indisponibilités' })
    await waitFor(() => expect(within(card).getAllByRole('listitem')).toHaveLength(2))
    const rows = within(card).getAllByRole('listitem')
    expect(rows[0]).toHaveTextContent('Sam. 3 → mar. 6 oct.')
    expect(rows[1]).toHaveTextContent('Jeu. 26 nov.')
    expect(within(card).getByText('2 périodes · 5 jours au total')).toBeInTheDocument()
  })

  it('lists upcoming unavailabilities only, the next one with its countdown', async () => {
    frozenMockupDay()
    createFakeBackend({
      periods: [
        days('u3', 'UNAVAILABLE', '2026-11-26', '2026-11-26'),
        days('u1', 'UNAVAILABLE', '2026-10-03', '2026-10-04'),
        days('u4', 'UNAVAILABLE', '2026-11-30', '2026-12-02'),
        days('p1', 'PREFER_DUTY', '2026-10-10', '2026-10-11'),
      ],
    }).install()

    renderDashboard()

    const card = await screen.findByRole('region', { name: 'Mes indisponibilités' })
    await waitFor(() => expect(within(card).getAllByRole('listitem')).toHaveLength(3))
    const rows = within(card).getAllByRole('listitem')
    expect(rows[0]).toHaveTextContent('Sam. 3 → dim. 4 oct.')
    expect(rows[0]).toHaveTextContent('2 jours')
    expect(within(rows[0]).getByText('Dans 7 jours')).toBeInTheDocument()
    expect(rows[1]).toHaveTextContent('Jeu. 26 nov.')
    expect(rows[1]).toHaveTextContent('Journée entière')
    expect(within(rows[1]).queryByText(/^Dans/)).not.toBeInTheDocument()
    expect(rows[2]).toHaveTextContent('Lun. 30 nov. → mer. 2 déc.')
    expect(within(card).getByText('3 périodes · 6 jours au total')).toBeInTheDocument()
    expect(within(card).getByRole('link', { name: /Calendrier/ })).toHaveAttribute('href', '/my-availability')
    expect(within(card).getByRole('link', { name: /Déclarer une indisponibilité/ })).toHaveAttribute(
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
    await waitFor(() => expect(screen.getByText("Aucun planning pour l'instant")).toBeInTheDocument())
    await waitFor(() => expect(screen.getByText('Aucune indisponibilité à venir')).toBeInTheDocument())
    expect(screen.getByRole('link', { name: /Déclarer une indisponibilité/ })).toBeInTheDocument()
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
    expect(screen.queryByText('Aucune indisponibilité à venir')).not.toBeInTheDocument()
    expect(screen.getByText('Journée entière')).toBeInTheDocument()
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

    expect(await screen.findByText('Aucune indisponibilité à venir')).toBeInTheDocument()
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

    expect(await screen.findByText('Aucune indisponibilité à venir')).toBeInTheDocument()
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
