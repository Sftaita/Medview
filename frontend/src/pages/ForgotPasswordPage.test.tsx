import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../features/auth/AuthContext'
import { ForgotPasswordPage } from './ForgotPasswordPage'

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

function renderForgotPasswordPage() {
  return render(
    <MemoryRouter initialEntries={['/forgot-password']}>
      <AuthProvider>
        <Routes>
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/login" element={<div>Page de connexion</div>} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>,
  )
}

const GENERIC_MESSAGE = 'Si un compte correspond à cette adresse, un email de réinitialisation a été envoyé.'

describe('ForgotPasswordPage', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the generic message after submitting a known email', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/password-reset/request')) {
          return jsonResponse({ success: true, message: GENERIC_MESSAGE })
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderForgotPasswordPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'alice@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Envoyer le lien de réinitialisation' }))

    expect(await screen.findByText(GENERIC_MESSAGE)).toBeInTheDocument()
  })

  // The UI must never be able to distinguish "unknown email" from "known
  // email" — same request, same response, same rendered outcome.
  it('shows the exact same generic message for an unknown email', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/password-reset/request')) {
          return jsonResponse({ success: true, message: GENERIC_MESSAGE })
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderForgotPasswordPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'nobody@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Envoyer le lien de réinitialisation' }))

    expect(await screen.findByText(GENERIC_MESSAGE)).toBeInTheDocument()
  })

  it('shows a rate-limit specific message with the Retry-After delay on 429', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/password-reset/request')) {
          return Promise.resolve(
            new Response(JSON.stringify({ error: 'too_many_attempts', message: 'Too many attempts.' }), {
              status: 429,
              headers: { 'Content-Type': 'application/json', 'Retry-After': '120' },
            }),
          )
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderForgotPasswordPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'alice@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Envoyer le lien de réinitialisation' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Trop de tentatives. Merci de réessayer dans 2 minutes.',
    )
  })

  // Same guard as LoginPage/RegisterPage: a fast double-click must only
  // ever fire one request.
  it('only sends one request when the form is submitted twice in a row', async () => {
    let requestCalls = 0
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/password-reset/request')) {
          requestCalls += 1
          return jsonResponse({ success: true, message: GENERIC_MESSAGE })
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderForgotPasswordPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'alice@example.com' } })
    const submitButton = screen.getByRole('button', { name: 'Envoyer le lien de réinitialisation' })
    fireEvent.click(submitButton)
    fireEvent.click(submitButton)

    await waitFor(() => expect(screen.getByText(GENERIC_MESSAGE)).toBeInTheDocument())
    expect(requestCalls).toBe(1)
  })

  it('links back to the login page', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    renderForgotPasswordPage()

    fireEvent.click(screen.getByRole('link', { name: 'Retour à la connexion' }))

    expect(await screen.findByText('Page de connexion')).toBeInTheDocument()
  })
})
