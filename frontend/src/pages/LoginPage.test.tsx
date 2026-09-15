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
})
