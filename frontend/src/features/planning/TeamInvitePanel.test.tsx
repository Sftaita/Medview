import '@testing-library/jest-dom/vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { InviteResult, TeamInvitation } from './types'
import { TeamInvitePanel } from './TeamInvitePanel'

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

const PENDING: TeamInvitation = {
  stableId: 'inv-1',
  email: 'marie@example.com',
  firstName: 'Marie',
  lastName: 'Dupont',
  role: 'MEMBER',
  status: 'PENDING',
  invitedByName: 'Alice Martin',
  expiresAt: '2026-10-01T00:00:00+00:00',
  createdAt: '2026-09-20T00:00:00+00:00',
}

const BASE = '/api/plannings/p1/teams/t1/invitations'

function stubFetch(handlers: {
  list?: TeamInvitation[]
  post?: () => Promise<Response>
  revoke?: () => Promise<Response>
}) {
  const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const method = init?.method ?? 'GET'
    if (url.endsWith(BASE) && method === 'GET') return jsonResponse(handlers.list ?? [])
    if (url.endsWith(BASE) && method === 'POST')
      return handlers.post?.() ?? Promise.reject(new Error('unexpected POST'))
    if (url.endsWith('/revoke')) return handlers.revoke?.() ?? Promise.reject(new Error('unexpected revoke'))
    return Promise.reject(new Error(`Unexpected fetch to ${url}`))
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function result(overrides: Partial<InviteResult>): InviteResult {
  return { status: 'INVITATION_CREATED', emailSent: true, member: null, invitation: PENDING, ...overrides }
}

async function submit(email = 'marie@example.com') {
  fireEvent.click(await screen.findByRole('button', { name: 'Ajouter une personne' }))
  fireEvent.change(screen.getByLabelText('Prénom'), { target: { value: 'Marie' } })
  fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Dupont' } })
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: email } })
  fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))
}

describe('TeamInvitePanel', () => {
  beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))

  const renderPanel = (onMembersChanged = vi.fn()) =>
    render(<TeamInvitePanel planningStableId="p1" teamStableId="t1" onMembersChanged={onMembersChanged} />)

  it('lists pending invitations apart from members, labelled as invitations', async () => {
    stubFetch({
      list: [
        PENDING,
        {
          ...PENDING,
          stableId: 'inv-2',
          firstName: 'Paul',
          lastName: 'Durand',
          email: 'paul@example.com',
          status: 'EXPIRED',
        },
      ],
    })
    renderPanel()

    expect(
      await screen.findByText(/Marie Dupont \(marie@example\.com\) — Invitation en attente/),
    ).toBeInTheDocument()
    expect(screen.getByText(/Paul Durand \(paul@example\.com\) — Invitation expirée/)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Invitations en attente' })).toBeInTheDocument()
  })

  it('says so when nothing is pending', async () => {
    stubFetch({ list: [] })
    renderPanel()

    expect(await screen.findByText('Aucune invitation en attente.')).toBeInTheDocument()
  })

  it('sends the new person the API and reports "invitation envoyée"', async () => {
    const fetchMock = stubFetch({ post: () => jsonResponse(result({}), 201) })
    renderPanel()

    await submit()

    expect(await screen.findByRole('status')).toHaveTextContent('Invitation envoyée à marie@example.com.')
    const [, init] = fetchMock.mock.calls.find(([, i]) => i?.method === 'POST') as [string, RequestInit]
    expect(JSON.parse(String(init.body))).toEqual({
      email: 'marie@example.com',
      firstName: 'Marie',
      lastName: 'Dupont',
    })
  })

  it('reports an existing user added, and asks the page to refresh the members', async () => {
    stubFetch({ post: () => jsonResponse(result({ status: 'USER_ADDED', invitation: null }), 201) })
    const onMembersChanged = vi.fn()
    renderPanel(onMembersChanged)

    await submit()

    expect(await screen.findByRole('status')).toHaveTextContent(
      "a déjà un compte MedVue : cette personne a été ajoutée à l'équipe",
    )
    expect(onMembersChanged).toHaveBeenCalledTimes(1)
  })

  it('reports "déjà membre" without touching the members list', async () => {
    stubFetch({ post: () => jsonResponse(result({ status: 'ALREADY_MEMBER', invitation: null }), 200) })
    const onMembersChanged = vi.fn()
    renderPanel(onMembersChanged)

    await submit()

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Marie Dupont fait déjà partie de cette équipe.',
    )
    expect(onMembersChanged).not.toHaveBeenCalled()
  })

  it('reports an invitation already pending', async () => {
    stubFetch({ post: () => jsonResponse(result({ status: 'INVITATION_ALREADY_PENDING' }), 200) })
    renderPanel()

    await submit()

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Une invitation est déjà en attente pour marie@example.com.',
    )
  })

  it('warns when the email could not be sent', async () => {
    stubFetch({ post: () => jsonResponse(result({ emailSent: false }), 201) })
    renderPanel()

    await submit()

    expect(await screen.findByRole('status')).toHaveTextContent("l'email n'a pas pu être envoyé")
  })

  it('explains a membership conflict in another team of the planning', async () => {
    stubFetch({ post: () => jsonResponse({ error: 'membership_conflict' }, 409) })
    renderPanel()

    await submit()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      "déjà partie d'une autre équipe de ce planning",
    )
  })

  it('sends a double submit only once', async () => {
    let release: (response: Response) => void = () => {}
    const fetchMock = stubFetch({
      post: () =>
        new Promise<Response>((resolve) => {
          release = resolve
        }),
    })
    renderPanel()

    await submit()
    const form = screen.getByLabelText('Email').closest('form') as HTMLFormElement
    fireEvent.submit(form)
    fireEvent.submit(form)

    expect(fetchMock.mock.calls.filter(([, i]) => i?.method === 'POST')).toHaveLength(1)
    release(
      new Response(JSON.stringify(result({})), {
        status: 201,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    await screen.findByRole('status')
  })

  it('revokes a pending invitation and reloads the list', async () => {
    const fetchMock = stubFetch({
      list: [PENDING],
      revoke: () => jsonResponse({ ...PENDING, status: 'REVOKED' }),
    })
    renderPanel()

    fireEvent.click(await screen.findByRole('button', { name: 'Révoquer' }))

    expect(await screen.findByRole('status')).toHaveTextContent(
      "L'invitation pour marie@example.com a été révoquée.",
    )
    await waitFor(() =>
      expect(fetchMock.mock.calls.filter(([, i]) => (i?.method ?? 'GET') === 'GET')).toHaveLength(2),
    )
  })
})
