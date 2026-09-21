import '@testing-library/jest-dom/vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../features/auth/AuthContext'
import { DashboardPage } from './DashboardPage'

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

const ME = {
  id: 1,
  email: 'alice@example.com',
  firstName: 'Alice',
  lastName: 'Martin',
  active: true,
  createdAt: '2026-01-01T00:00:00+00:00',
  updatedAt: '2026-01-01T00:00:00+00:00',
}

function stub(handlers: { plannings?: unknown; calendar?: unknown }) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/api/token/refresh')) return jsonResponse({ token: 'access' })
      if (url.endsWith('/api/me')) return jsonResponse(ME)
      if (url.endsWith('/api/me/calendar')) {
        return handlers.calendar === undefined ? jsonResponse({}, 500) : jsonResponse(handlers.calendar)
      }
      if (url.endsWith('/api/plannings')) {
        return handlers.plannings === undefined ? jsonResponse({}, 500) : jsonResponse(handlers.plannings)
      }
      return Promise.reject(new Error(`Unexpected fetch to ${url}`))
    }),
  )
}

function renderDashboard() {
  render(
    <MemoryRouter>
      <AuthProvider>
        <DashboardPage />
      </AuthProvider>
    </MemoryRouter>,
  )
}

describe('DashboardPage', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('greets the user and lists their plannings and upcoming availability', async () => {
    const start = new Date()
    start.setDate(start.getDate() + 10)
    start.setHours(0, 0, 0, 0)
    const end = new Date(start)
    end.setDate(end.getDate() + 3)

    stub({
      plannings: [
        {
          stableId: 'p1',
          name: 'Gardes 2026-2027',
          startsAt: '2026-09-01',
          endsAt: '2027-02-28',
          creatorStableId: 'u1',
          canManage: true,
          timezone: 'Europe/Brussels',
          createdAt: '',
          updatedAt: '',
        },
      ],
      calendar: [
        {
          stableId: 'a1',
          type: 'UNAVAILABLE',
          startsAt: start.toISOString(),
          endsAt: end.toISOString(),
          createdAt: '',
          updatedAt: '',
        },
      ],
    })
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
    stub({})
    renderDashboard()

    expect(await screen.findByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Aucun planning pour le moment.')).toBeInTheDocument())
    expect(screen.getByText('Aucune indisponibilité ou préférence à venir.')).toBeInTheDocument()
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
    stub({ plannings: [], calendar: [] })
    renderDashboard()

    expect(await screen.findByRole('status')).toHaveTextContent('Votre compte a bien été créé.')
    expect(screen.getByRole('link', { name: 'Urgences' })).toHaveAttribute('href', '/plannings/p1')
    expect(sessionStorage.getItem('medvue.flash.joinedTeams')).toBeNull()
  })
})
