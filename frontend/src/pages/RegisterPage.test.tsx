import '@testing-library/jest-dom/vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../features/auth/AuthContext'
import { MyAvailabilityProvider } from '../features/availability/MyAvailabilityProvider'
import { DashboardPage } from './DashboardPage'
import { RegisterPage } from './RegisterPage'

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
  phone: '+32470123456',
  active: true,
  createdAt: '2026-01-01T00:00:00+00:00',
  updatedAt: '2026-01-01T00:00:00+00:00',
}

type Handler = (url: string, init?: RequestInit) => Promise<Response> | undefined

function stubFetch(extra: Handler) {
  const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
    if (url.endsWith('/api/login')) return jsonResponse({ token: 'fake-jwt' })
    if (url.endsWith('/api/me')) return jsonResponse(ME)
    return extra(url, init) ?? Promise.reject(new Error(`Unexpected fetch to ${url}`))
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function renderRegisterPage() {
  return render(
    <MemoryRouter initialEntries={['/register']}>
      <AuthProvider>
        <Routes>
          <Route path="/register" element={<RegisterPage />} />
          <Route
            path="/"
            element={
              <MyAvailabilityProvider>
                <DashboardPage />
              </MyAvailabilityProvider>
            }
          />
        </Routes>
      </AuthProvider>
    </MemoryRouter>,
  )
}

async function fillForm() {
  fireEvent.change(await screen.findByLabelText('Prénom'), { target: { value: 'Alice' } })
  fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Martin' } })
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'alice@example.com' } })
  fireEvent.change(screen.getByLabelText('Téléphone'), { target: { value: '0470 12 34 56' } })
  fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'correct-horse-battery' } })
}

function registerCalls(fetchMock: ReturnType<typeof stubFetch>) {
  return fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/api/register'))
}

describe('RegisterPage (classic sign-up)', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

  it('asks for first name, last name, email, phone and password — and nothing about an institution', async () => {
    stubFetch(() => undefined)
    renderRegisterPage()

    for (const label of ['Prénom', 'Nom', 'Email', 'Téléphone', 'Mot de passe']) {
      expect(await screen.findByLabelText(label)).toBeInTheDocument()
    }
    // D115: the institution is not part of a person's identity.
    expect(screen.queryByLabelText(/hôpital|institution/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
    // A classic sign-up has no invitation notice and an editable email.
    expect(screen.getByLabelText('Email')).not.toHaveAttribute('readonly')
    expect(screen.queryByRole('note')).not.toBeInTheDocument()
  })

  it('registers with exactly the contract fields, logs in and lands on the dashboard — without any /api/hospitals call', async () => {
    const fetchMock = stubFetch((url) =>
      url.endsWith('/api/register') ? jsonResponse({ ...ME, joinedTeams: [] }, 201) : undefined,
    )
    renderRegisterPage()

    await fillForm()
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    await waitFor(() => expect(screen.getByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument())
    const [, init] = registerCalls(fetchMock)[0]
    expect(JSON.parse(String(init?.body))).toEqual({
      email: 'alice@example.com',
      plainPassword: 'correct-horse-battery',
      firstName: 'Alice',
      lastName: 'Martin',
      phone: '0470 12 34 56',
    })
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/api/hospitals'))).toBe(false)
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('makes every field required so nothing is submitted half-empty', async () => {
    stubFetch(() => undefined)
    renderRegisterPage()

    for (const label of ['Prénom', 'Nom', 'Email', 'Téléphone', 'Mot de passe']) {
      expect(await screen.findByLabelText(label)).toBeRequired()
    }
    expect(screen.getByLabelText('Mot de passe')).toHaveAttribute('minlength', '8')
    expect(screen.getByLabelText('Email')).toHaveAttribute('type', 'email')
  })

  it('sends a double submit only once', async () => {
    let resolveRegister: (response: Response) => void = () => {}
    const fetchMock = stubFetch((url) =>
      url.endsWith('/api/register')
        ? new Promise<Response>((resolve) => {
            resolveRegister = resolve
          })
        : undefined,
    )
    renderRegisterPage()
    await fillForm()

    const button = screen.getByRole('button', { name: 'Créer mon compte' })
    fireEvent.click(button)
    fireEvent.click(button)
    fireEvent.submit(button.closest('form') as HTMLFormElement)

    expect(registerCalls(fetchMock)).toHaveLength(1)
    resolveRegister(
      new Response(JSON.stringify({ ...ME, joinedTeams: [] }), {
        status: 201,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    await waitFor(() => expect(screen.getByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument())
  })

  it('shows visible progress from the click until the redirect, not just a disabled button', async () => {
    // Registration is followed by a login and a profile fetch: keep each one pending
    // in turn and check the button never goes back to looking idle in between.
    const pending: Record<string, (response: Response) => void> = {}
    const hold = (name: string) =>
      new Promise<Response>((resolve) => {
        pending[name] = resolve
      })
    const json = (body: unknown, status = 200) =>
      new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        if (url.endsWith('/api/register')) return hold('register')
        if (url.endsWith('/api/login')) return hold('login')
        if (url.endsWith('/api/me')) return hold('me')
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )
    renderRegisterPage()
    await fillForm()

    const idle = screen.getByRole('button', { name: 'Créer mon compte' })
    expect(idle).toBeEnabled()
    expect(idle).toHaveAttribute('aria-busy', 'false')

    fireEvent.click(idle)

    // Immediately: disabled, busy, and the label says what is happening.
    const busy = await screen.findByRole('button', { name: 'Création du compte…' })
    expect(busy).toBeDisabled()
    expect(busy).toHaveAttribute('aria-busy', 'true')

    // Still busy while the follow-up login and profile requests run.
    await waitFor(() => expect(pending.register).toBeDefined())
    pending.register(json({ ...ME, joinedTeams: [] }, 201))
    await waitFor(() => expect(pending.login).toBeDefined())
    expect(screen.getByRole('button', { name: 'Création du compte…' })).toBeDisabled()
    pending.login(json({ token: 'fake-jwt' }))
    await waitFor(() => expect(pending.me).toBeDefined())
    expect(screen.getByRole('button', { name: 'Création du compte…' })).toBeDisabled()

    pending.me(json(ME))
    await waitFor(() => expect(screen.getByRole('heading', { name: /Bonjour, Alice/ })).toBeInTheDocument())
  })

  it('goes back to an actionable button when the request fails at the network level', async () => {
    stubFetch((url) =>
      url.endsWith('/api/register') ? Promise.reject(new TypeError('Failed to fetch')) : undefined,
    )
    renderRegisterPage()
    await fillForm()

    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Une erreur est survenue')
    const button = screen.getByRole('button', { name: 'Créer mon compte' })
    expect(button).toBeEnabled()
    expect(button).toHaveAttribute('aria-busy', 'false')
  })

  it('shows a clear message for a taken email', async () => {
    stubFetch((url) =>
      url.endsWith('/api/register') ? jsonResponse({ error: 'email_already_used' }, 409) : undefined,
    )
    renderRegisterPage()

    await fillForm()
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Cet email est déjà utilisé.')
  })

  it('shows a friendly message for an invalid phone number reported by the backend', async () => {
    stubFetch((url) =>
      url.endsWith('/api/register')
        ? jsonResponse(
            { error: 'validation_failed', violations: { phone: 'This is not a valid phone number.' } },
            422,
          )
        : undefined,
    )
    renderRegisterPage()

    await fillForm()
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Numéro de téléphone invalide')
  })

  it('shows the backend message for a weak password', async () => {
    stubFetch((url) =>
      url.endsWith('/api/register')
        ? jsonResponse(
            {
              error: 'validation_failed',
              violations: { plainPassword: 'Your password must be at least 8 characters long.' },
            },
            422,
          )
        : undefined,
    )
    renderRegisterPage()

    await fillForm()
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('at least 8 characters')
  })

  it('shows a rate-limit message with the wait time', async () => {
    stubFetch((url) =>
      url.endsWith('/api/register')
        ? Promise.resolve(
            new Response(JSON.stringify({ error: 'too_many_attempts' }), {
              status: 429,
              headers: { 'Content-Type': 'application/json', 'Retry-After': '120' },
            }),
          )
        : undefined,
    )
    renderRegisterPage()

    await fillForm()
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Trop de tentatives')
  })

  it('re-enables the button after a failure so the user can retry', async () => {
    stubFetch((url) => (url.endsWith('/api/register') ? jsonResponse({ error: 'boom' }, 500) : undefined))
    renderRegisterPage()

    await fillForm()
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    await screen.findByRole('alert')
    expect(screen.getByRole('button', { name: 'Créer mon compte' })).toBeEnabled()
  })
})
