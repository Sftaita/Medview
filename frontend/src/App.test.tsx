import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import { AuthProvider } from './features/auth/AuthContext'

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

describe('App', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('redirects to the login page when not authenticated', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/health')) return jsonResponse({ status: 'ok', database: 'ok' })
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    render(
      <MemoryRouter initialEntries={['/']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeInTheDocument()
  })

  it('renders the dashboard when a valid session is already stored', async () => {
    localStorage.setItem('medvue.auth.token', 'fake-jwt')

    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/health')) return jsonResponse({ status: 'ok', database: 'ok' })
        if (url.endsWith('/api/me')) {
          return jsonResponse({
            id: 1,
            email: 'alice@example.com',
            firstName: 'Alice',
            lastName: 'Martin',
            active: true,
            createdAt: '2026-01-01T00:00:00+00:00',
            updatedAt: '2026-01-01T00:00:00+00:00',
          })
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    render(
      <MemoryRouter initialEntries={['/']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>,
    )

    expect(screen.getByText('MedVue')).toBeInTheDocument()
    expect(await screen.findByRole('heading', { name: 'Tableau de bord' })).toBeInTheDocument()
  })
})
