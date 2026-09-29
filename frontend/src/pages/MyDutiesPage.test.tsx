import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../features/auth/AuthContext'
import { createFakeBackend } from '../testUtils/fakeBackend'
import { makeMyDuty } from '../testUtils/myDuty'
import { MyDutiesPage } from './MyDutiesPage'

function renderPage() {
  render(
    <MemoryRouter initialEntries={['/my-duties']}>
      <AuthProvider>
        <Routes>
          <Route path="/my-duties" element={<MyDutiesPage />} />
          <Route path="/plannings/:id" element={<p>Page planning</p>} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>,
  )
}

describe('MyDutiesPage', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 9, 7, 9, 0)) // Wednesday 7 October 2026
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('lists upcoming duties by month, soonest first, with their planning, line and countdown', async () => {
    createFakeBackend({
      duties: [
        makeMyDuty(['2026-09-29']),
        makeMyDuty(['2026-10-08']),
        makeMyDuty(['2026-10-17', '2026-10-18'], {
          blockName: 'Week-end',
          lineName: 'Renfort',
          conditional: true,
          coverageState: 'REQUIRED_ASSIGNED',
        }),
        makeMyDuty(['2026-11-03'], { planningName: 'Trauma Delta' }),
      ],
    }).install()
    renderPage()

    const october = await screen.findByRole('region', { name: 'Octobre 2026' })
    const rows = within(october).getAllByRole('link')
    expect(rows).toHaveLength(2)
    expect(rows[0]).toHaveTextContent('Jeu. 8 oct.')
    expect(rows[0]).toHaveTextContent('Gardes 2026-2027 · Première ligne · Garde')
    expect(rows[0]).toHaveTextContent('Demain')
    expect(rows[1]).toHaveTextContent('Sam. 17 → dim. 18 oct.')
    expect(rows[1]).toHaveTextContent('Renfort · Bloc Week-end')
    expect(within(rows[1]).getByText('Renfort')).toBeInTheDocument()
    expect(rows[1]).toHaveTextContent('Dans 10 jours')
    expect(within(screen.getByRole('region', { name: 'Novembre 2026' })).getByRole('link')).toHaveTextContent(
      'Trauma Delta',
    )
    expect(screen.queryByText(/29 sept/)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /À venir \(3\)/ })).toHaveAttribute('aria-pressed', 'true')
  })

  it('shows past duties on demand, latest first, without a countdown', async () => {
    createFakeBackend({
      duties: [makeMyDuty(['2026-09-01']), makeMyDuty(['2026-09-29']), makeMyDuty(['2026-10-08'])],
    }).install()
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: /Passées \(2\)/ }))

    const rows = within(screen.getByRole('region', { name: 'Septembre 2026' })).getAllByRole('link')
    expect(rows).toHaveLength(2)
    expect(rows[0]).toHaveTextContent('Mar. 29 sept.')
    expect(rows[1]).toHaveTextContent('Mar. 1 sept.')
    expect(screen.queryByText(/Dans|Demain/)).not.toBeInTheDocument()
    expect(screen.queryByText(/8 oct/)).not.toBeInTheDocument()
  })

  it('calls a block under way "En cours"', async () => {
    createFakeBackend({ duties: [makeMyDuty(['2026-10-06', '2026-10-07'], { blockName: 'Nuit' })] }).install()
    renderPage()

    expect(await screen.findByText('En cours')).toBeInTheDocument()
  })

  it('opens the planning of a duty', async () => {
    createFakeBackend({ duties: [makeMyDuty(['2026-10-08'])] }).install()
    renderPage()

    fireEvent.click(await screen.findByRole('link', { name: /Jeu\. 8 oct\./ }))

    expect(await screen.findByText('Page planning')).toBeInTheDocument()
  })

  it('says so when nothing is published yet', async () => {
    createFakeBackend({ duties: [] }).install()
    renderPage()

    expect(await screen.findByText('Aucune garde à venir')).toBeInTheDocument()
    expect(screen.getByText(/dès la publication d'un planning/)).toBeInTheDocument()
  })

  it('reports a failed load', async () => {
    createFakeBackend({ duties: null }).install()
    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('Impossible de charger vos gardes.')
  })
})
