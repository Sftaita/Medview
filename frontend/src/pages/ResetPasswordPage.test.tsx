import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../features/auth/AuthContext'
import { TOKEN_STORAGE_KEY } from '../lib/apiClient'
import { ResetPasswordPage } from './ResetPasswordPage'

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

function renderResetPasswordPage() {
  return render(
    <MemoryRouter initialEntries={['/reset-password']}>
      <AuthProvider>
        <Routes>
          <Route path="/reset-password" element={<ResetPasswordPage />} />
          <Route path="/login" element={<div>Page de connexion</div>} />
          <Route path="/forgot-password" element={<div>Mot de passe oublié</div>} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>,
  )
}

describe('ResetPasswordPage', () => {
  beforeEach(() => {
    localStorage.clear()
    window.location.hash = ''
  })

  afterEach(() => {
    window.location.hash = ''
  })

  it('reads the token from the URL fragment and cleans it up immediately', async () => {
    window.location.hash = '#token=abc123def456'
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderResetPasswordPage()

    await screen.findByRole('button', { name: 'Modifier mon mot de passe' })
    expect(window.location.hash).toBe('')
  })

  it('shows an invalid-link state when there is no token in the URL', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderResetPasswordPage()

    expect(await screen.findByText('Ce lien est invalide ou a expiré.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('link', { name: 'Demander un nouveau lien' }))
    expect(await screen.findByText('Mot de passe oublié')).toBeInTheDocument()
  })

  it('rejects mismatched passwords without calling the API', async () => {
    window.location.hash = '#token=abc123def456'
    const fetchMock = vi.fn((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
      return Promise.reject(new Error(`Unexpected fetch to ${url}`))
    })
    vi.stubGlobal('fetch', fetchMock)

    renderResetPasswordPage()

    fireEvent.change(await screen.findByLabelText('Nouveau mot de passe'), {
      target: { value: 'brand-new-password-1' },
    })
    fireEvent.change(screen.getByLabelText('Confirmer le mot de passe'), {
      target: { value: 'something-else-1' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Modifier mon mot de passe' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Les deux mots de passe ne correspondent pas.')
    expect(fetchMock).not.toHaveBeenCalledWith(
      expect.stringContaining('/api/password-reset/confirm'),
      expect.anything(),
    )
  })

  it('shows a generic invalid/expired message on a rejected token, without leaking the reason', async () => {
    window.location.hash = '#token=abc123def456'
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/password-reset/confirm')) {
          return jsonResponse(
            { error: 'invalid_or_expired_token', message: 'Ce lien est invalide ou a expiré.' },
            400,
          )
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderResetPasswordPage()

    fireEvent.change(await screen.findByLabelText('Nouveau mot de passe'), {
      target: { value: 'brand-new-password-1' },
    })
    fireEvent.change(screen.getByLabelText('Confirmer le mot de passe'), {
      target: { value: 'brand-new-password-1' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Modifier mon mot de passe' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Ce lien est invalide ou a expiré.')
  })

  it('shows the server-side password policy violation on 422', async () => {
    window.location.hash = '#token=abc123def456'
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/password-reset/confirm')) {
          return jsonResponse(
            {
              error: 'validation_failed',
              violations: { newPassword: 'Your password must be at least 8 characters long.' },
            },
            422,
          )
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderResetPasswordPage()

    fireEvent.change(await screen.findByLabelText('Nouveau mot de passe'), { target: { value: 'shortpw1' } })
    fireEvent.change(screen.getByLabelText('Confirmer le mot de passe'), { target: { value: 'shortpw1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Modifier mon mot de passe' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Your password must be at least 8 characters long.',
    )
  })

  it('on success: shows the confirmation, clears the local session and never auto-logs in', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'stale-jwt-from-before-the-reset')
    window.location.hash = '#token=abc123def456'
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/password-reset/confirm')) {
          return jsonResponse({ success: true, message: 'Votre mot de passe a été modifié.' })
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderResetPasswordPage()

    fireEvent.change(await screen.findByLabelText('Nouveau mot de passe'), {
      target: { value: 'brand-new-password-1' },
    })
    fireEvent.change(screen.getByLabelText('Confirmer le mot de passe'), {
      target: { value: 'brand-new-password-1' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Modifier mon mot de passe' }))

    expect(await screen.findByText('Mot de passe modifié')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier mon mot de passe' })).not.toBeInTheDocument()
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()

    // Never an automatic session: only a link to the login page, no token.
    const loginLink = screen.getByRole('link', { name: 'Se connecter' })
    fireEvent.click(loginLink)
    expect(await screen.findByText('Page de connexion')).toBeInTheDocument()
  })

  it('only sends one request when the form is submitted twice in a row', async () => {
    window.location.hash = '#token=abc123def456'
    let confirmCalls = 0
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/password-reset/confirm')) {
          confirmCalls += 1
          return jsonResponse({ success: true, message: 'Votre mot de passe a été modifié.' })
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderResetPasswordPage()

    fireEvent.change(await screen.findByLabelText('Nouveau mot de passe'), {
      target: { value: 'brand-new-password-1' },
    })
    fireEvent.change(screen.getByLabelText('Confirmer le mot de passe'), {
      target: { value: 'brand-new-password-1' },
    })
    const submitButton = screen.getByRole('button', { name: 'Modifier mon mot de passe' })
    fireEvent.click(submitButton)
    fireEvent.click(submitButton)

    await waitFor(() => expect(screen.getByText('Mot de passe modifié')).toBeInTheDocument())
    expect(confirmCalls).toBe(1)
  })
})
