import { act, render, screen } from '@testing-library/react'
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

  it('shows the public homepage on / when not authenticated', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/health')) return jsonResponse({ status: 'ok', database: 'ok' })
        // No valid refresh cookie: the bootstrap's silent refresh fails.
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
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

    expect(
      await screen.findByRole('heading', { level: 1, name: /Le planning de gardes médicales/ }),
    ).toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Navigation principale' })).not.toBeInTheDocument()
  })

  it('still redirects to the login page from any other protected route when not authenticated', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/health')) return jsonResponse({ status: 'ok', database: 'ok' })
        // No valid refresh cookie: the bootstrap's silent refresh fails.
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    render(
      <MemoryRouter initialEntries={['/my-duties']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeInTheDocument()
  })

  it('renders the dashboard when a valid session is already stored', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/health')) return jsonResponse({ status: 'ok', database: 'ok' })
        // A valid HttpOnly refresh cookie (not visible to this test) lets
        // the bootstrap silently obtain a fresh access token.
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ token: 'fresh-access-token' })
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

    expect(await screen.findByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument()
    // The brand appears in the sidebar and in the phone top bar (one DOM, switched by CSS).
    expect(screen.getAllByText('MedVue').length).toBeGreaterThan(0)
    expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeInTheDocument()
  })

  // Regression test for a UAT finding: /login (and /register) stayed
  // reachable and showed the form even for an already-authenticated user,
  // instead of redirecting to the dashboard.
  it('redirects away from /login when a valid session is already stored', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/health')) return jsonResponse({ status: 'ok', database: 'ok' })
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ token: 'fresh-access-token' })
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
      <MemoryRouter initialEntries={['/login']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Connexion' })).not.toBeInTheDocument()
  })

  // Regression test for a UAT finding: logging out in one tab left other
  // open tabs of the same app showing stale "logged in" UI, since
  // localStorage changes made by one tab don't re-render another tab's
  // React state by themselves.
  it('clears the session in this tab when the access token is removed by another tab', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/health')) return jsonResponse({ status: 'ok', database: 'ok' })
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ token: 'fresh-access-token' })
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

    expect(await screen.findByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument()

    // Simulate another tab's logout: it removed the key and the browser
    // dispatches a StorageEvent to every *other* same-origin tab.
    act(() => {
      window.dispatchEvent(
        new StorageEvent('storage', {
          key: 'medvue.auth.token',
          newValue: null,
          oldValue: 'fresh-access-token',
        }),
      )
    })

    // Signed out on "/": the public homepage replaces the dashboard.
    expect(
      await screen.findByRole('heading', { level: 1, name: /Le planning de gardes médicales/ }),
    ).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: /Bonjour, Alice/ })).not.toBeInTheDocument()
  })
})
