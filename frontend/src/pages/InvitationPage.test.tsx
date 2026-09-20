import '@testing-library/jest-dom/vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../features/auth/AuthContext'
import { DashboardPage } from './DashboardPage'
import { InvitationPage } from './InvitationPage'

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

const TOKEN = 'a'.repeat(64)

const INFO = {
  email: 'marie@example.com',
  proposedFirstName: 'Mary',
  proposedLastName: 'Dupond',
  teamName: 'Cardiologie',
  planningName: 'Gardes 2027',
  inviterName: 'Alice Martin',
  expiresAt: '2026-10-01T00:00:00+00:00',
  accountExists: false,
}

const JOINED = [
  { teamStableId: 't1', teamName: 'Cardiologie', planningStableId: 'p1', planningName: 'Gardes 2027' },
  { teamStableId: 't2', teamName: 'Urgences', planningStableId: 'p2', planningName: 'Gardes urgences' },
]

const ME = {
  id: 2,
  email: 'marie@example.com',
  firstName: 'Marie',
  lastName: 'Dupont',
  active: true,
  createdAt: '2026-01-01T00:00:00+00:00',
  updatedAt: '2026-01-01T00:00:00+00:00',
}

type Handler = (url: string, init?: RequestInit) => Promise<Response> | undefined

function stubFetch({
  session = false,
  extra = () => undefined,
}: { session?: boolean; extra?: Handler } = {}) {
  const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/token/refresh')) {
      return session
        ? jsonResponse({ token: 'fresh' })
        : jsonResponse({ error: 'invalid_refresh_token' }, 401)
    }
    if (url.endsWith('/api/login')) return jsonResponse({ token: 'fake-jwt' })
    if (url.endsWith('/api/me')) return jsonResponse(ME)
    return extra(url, init) ?? Promise.reject(new Error(`Unexpected fetch to ${url}`))
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function renderInvitationPage(token = TOKEN) {
  return render(
    <MemoryRouter initialEntries={[`/invitations/${token}`]}>
      <AuthProvider>
        <Routes>
          <Route path="/invitations/:token" element={<InvitationPage />} />
          <Route path="/login" element={<div>Page de connexion</div>} />
          <Route path="/" element={<DashboardPage />} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>,
  )
}

const invitationLookup =
  (info: unknown, status = 200): Handler =>
  (url) =>
    url.endsWith(`/api/invitations/${TOKEN}`) ? jsonResponse(info, status) : undefined

describe('InvitationPage — new account', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

  it('prefills the form, locks the email and warns that the names come from the inviter', async () => {
    stubFetch({ extra: invitationLookup(INFO) })
    renderInvitationPage()

    expect(await screen.findByLabelText('Prénom')).toHaveValue('Mary')
    expect(screen.getByLabelText('Nom')).toHaveValue('Dupond')
    const email = screen.getByLabelText('Email')
    expect(email).toHaveValue('marie@example.com')
    expect(email).toHaveAttribute('readonly')

    expect(
      screen.getByText(
        /Votre prénom et votre nom ont été renseignés par la personne qui vous a invité\. Vérifiez-les avant de continuer\./,
      ),
    ).toBeInTheDocument()
    expect(screen.getByText(/vous invite à rejoindre l'équipe/)).toHaveTextContent('Alice Martin')
    expect(screen.getByText(/vous invite à rejoindre l'équipe/)).toHaveTextContent('Cardiologie')
    // No institution field: it is not part of a person's identity (D115).
    expect(screen.queryByLabelText(/hôpital|institution/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
    // The team is never asked for.
    expect(screen.queryByLabelText(/équipe/i)).not.toBeInTheDocument()
  })

  it('lets the user correct the names and submits them with the invitation token', async () => {
    const fetchMock = stubFetch({
      extra: (url) => {
        if (url.endsWith(`/api/invitations/${TOKEN}`)) return jsonResponse(INFO)
        if (url.endsWith('/api/register')) return jsonResponse({ ...ME, joinedTeams: JOINED }, 201)
        return undefined
      },
    })
    renderInvitationPage()

    fireEvent.change(await screen.findByLabelText('Prénom'), { target: { value: 'Marie' } })
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Dupont' } })
    fireEvent.change(screen.getByLabelText('Téléphone'), { target: { value: '+32 470 12 34 56' } })
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'correct-horse-battery' } })
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    // Feedback: the user is told which teams they joined automatically.
    const banner = await screen.findByRole('status')
    expect(banner).toHaveTextContent('Vous avez été ajouté automatiquement à ces équipes')
    expect(banner).toHaveTextContent('Cardiologie')
    expect(banner).toHaveTextContent('Urgences')

    const [, init] = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/api/register')) as [
      string,
      RequestInit,
    ]
    expect(JSON.parse(String(init.body))).toEqual({
      email: 'marie@example.com',
      plainPassword: 'correct-horse-battery',
      firstName: 'Marie',
      lastName: 'Dupont',
      phone: '+32 470 12 34 56',
      invitationToken: TOKEN,
    })
  })

  it('tells a single-team joiner they joined "cette équipe"', async () => {
    stubFetch({
      extra: (url) => {
        if (url.endsWith(`/api/invitations/${TOKEN}`)) return jsonResponse(INFO)
        if (url.endsWith('/api/register')) return jsonResponse({ ...ME, joinedTeams: [JOINED[0]] }, 201)
        return undefined
      },
    })
    renderInvitationPage()

    await screen.findByLabelText('Téléphone')
    fireEvent.change(screen.getByLabelText('Téléphone'), { target: { value: '+32 470 12 34 56' } })
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'correct-horse-battery' } })
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    expect(await screen.findByRole('status')).toHaveTextContent('à cette équipe')
  })

  it.each([
    ['invitation_expired', 410, /a expiré/],
    ['invitation_revoked', 410, /a été annulée/],
    ['invitation_already_used', 410, /déjà été utilisée/],
    ['invitation_not_found', 404, /introuvable/],
  ])('shows a clear message for %s', async (code, status, message) => {
    stubFetch({ extra: invitationLookup({ error: code }, status) })
    renderInvitationPage()

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(screen.queryByLabelText('Mot de passe')).not.toBeInTheDocument()
  })

  it('shows the same kind of message when the link is refused at submission time', async () => {
    stubFetch({
      extra: (url) => {
        if (url.endsWith(`/api/invitations/${TOKEN}`)) return jsonResponse(INFO)
        if (url.endsWith('/api/register')) return jsonResponse({ error: 'invitation_expired' }, 410)
        return undefined
      },
    })
    renderInvitationPage()

    await screen.findByLabelText('Téléphone')
    fireEvent.change(screen.getByLabelText('Téléphone'), { target: { value: '+32 470 12 34 56' } })
    fireEvent.change(screen.getByLabelText('Mot de passe'), { target: { value: 'correct-horse-battery' } })
    fireEvent.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('a expiré')
  })
})

describe('InvitationPage — account already exists', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

  it('asks a logged-out invitee to log in instead of showing the sign-up form', async () => {
    stubFetch({ extra: invitationLookup({ ...INFO, accountExists: true }) })
    renderInvitationPage()

    expect(await screen.findByText(/Un compte MedVue existe déjà/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Mot de passe')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Se connecter' })).toHaveAttribute('href', '/login')
  })

  it('lets the matching logged-in account accept the invitation in one click', async () => {
    const fetchMock = stubFetch({
      session: true,
      extra: (url) => {
        if (url.endsWith(`/api/invitations/${TOKEN}`)) return jsonResponse({ ...INFO, accountExists: true })
        if (url.endsWith(`/api/invitations/${TOKEN}/accept`))
          return jsonResponse({ joinedTeams: [JOINED[0]] })
        return undefined
      },
    })
    renderInvitationPage()

    fireEvent.click(await screen.findByRole('button', { name: "Rejoindre l'équipe" }))

    expect(await screen.findByRole('status')).toHaveTextContent("Vous avez rejoint l'équipe : Cardiologie")
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/accept'))).toHaveLength(1)
  })

  it('does not offer to accept when logged in with another account', async () => {
    stubFetch({
      session: true,
      extra: invitationLookup({ ...INFO, email: 'someone.else@example.com', accountExists: true }),
    })
    renderInvitationPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('vous êtes connecté avec un autre compte')
    await waitFor(() =>
      expect(screen.queryByRole('button', { name: "Rejoindre l'équipe" })).not.toBeInTheDocument(),
    )
  })
})
