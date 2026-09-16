import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../features/auth/AuthContext'
import { LoginPage } from './LoginPage'

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

function renderLoginPage() {
  return render(
    <MemoryRouter initialEntries={['/login']}>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/" element={<div>Tableau de bord</div>} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>,
  )
}

describe('LoginPage', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('logs the user in and redirects on success', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        // No session yet when the page mounts.
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/login')) return jsonResponse({ token: 'fake-jwt' })
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

    renderLoginPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'alice@example.com' } })
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'correct-horse-battery' } })
    fireEvent.click(screen.getByRole('button', { name: 'Se connecter' }))

    await waitFor(() => expect(screen.getByText('Tableau de bord')).toBeInTheDocument())
    expect(localStorage.getItem('medvue.auth.token')).toBe('fake-jwt')
  })

  it('shows an error message on invalid credentials', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => jsonResponse({ code: 401, message: 'Invalid credentials.' }, 401)),
    )

    renderLoginPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'alice@example.com' } })
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'wrong-password' } })
    fireEvent.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Email ou mot de passe incorrect.')
    expect(localStorage.getItem('medvue.auth.token')).toBeNull()
  })

  // Regression test for a UAT finding: a 429 (rate-limited) response fell
  // into the generic "Une erreur est survenue" catch-all, indistinguishable
  // from a real server error, instead of telling the user why and how long
  // to wait.
  it('shows a rate-limit specific message with the Retry-After delay on 429', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve(
          new Response(JSON.stringify({ error: 'too_many_attempts', message: 'Too many login attempts.' }), {
            status: 429,
            headers: { 'Content-Type': 'application/json', 'Retry-After': '120' },
          }),
        ),
      ),
    )

    renderLoginPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'alice@example.com' } })
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'whatever' } })
    fireEvent.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Trop de tentatives. Merci de réessayer dans 2 minutes.',
    )
  })

  // Regression test for a UAT finding: two near-simultaneous submits (e.g.
  // a fast double-click) each fired a real /api/login request, because
  // disabled={isSubmitting} alone doesn't apply to the DOM synchronously
  // relative to the second click.
  it('only sends one request when the form is submitted twice in a row', async () => {
    let loginCalls = 0
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/login')) {
          loginCalls += 1
          return jsonResponse({ token: 'fake-jwt' })
        }
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

    renderLoginPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'alice@example.com' } })
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'correct-horse-battery' } })
    const submitButton = screen.getByRole('button', { name: 'Se connecter' })
    fireEvent.click(submitButton)
    fireEvent.click(submitButton)

    await waitFor(() => expect(screen.getByText('Tableau de bord')).toBeInTheDocument())
    expect(loginCalls).toBe(1)
  })
})
