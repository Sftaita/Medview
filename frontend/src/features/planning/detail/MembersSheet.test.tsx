import '@testing-library/jest-dom/vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { InviteResult, PlanningLineSummary, PlanningTeamMember } from '../types'
import { MembersSheet } from './MembersSheet'

// "Ajouter un membre" by first name, last name and email only (docs/decisions.md D221): the
// manager never types a technical identifier, a role or an entry date.

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

const LINE: PlanningLineSummary = {
  stableId: 'l1',
  name: 'Garde principale',
  type: 'PRIMARY',
  position: 0,
  active: true,
  team: { stableId: 't1', name: 'Chirurgie', canInvite: true },
  planningPeriodStableId: 'pp1',
  createdAt: '2026-09-20T00:00:00+00:00',
  updatedAt: '2026-09-20T00:00:00+00:00',
}

function member(overrides: Partial<PlanningTeamMember>): PlanningTeamMember {
  return {
    stableId: 'm1',
    userStableId: 'u1',
    firstName: 'Alice',
    lastName: 'Martin',
    role: 'MEMBER',
    membershipStart: '2026-09-20',
    membershipEnd: null,
    active: true,
    ...overrides,
  }
}

const MEMBERS = '/api/plannings/p1/teams/t1/members'
const INVITATIONS = '/api/plannings/p1/teams/t1/invitations'

function stubFetch(
  initial: PlanningTeamMember[],
  afterAdd: PlanningTeamMember[],
  post: () => Promise<Response>,
) {
  let added = false
  const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const method = init?.method ?? 'GET'
    if (url.endsWith(MEMBERS) && method === 'GET') return jsonResponse(added ? afterAdd : initial)
    if (url.endsWith(INVITATIONS) && method === 'GET') return jsonResponse([])
    if (url.endsWith(INVITATIONS) && method === 'POST') {
      added = true
      return post()
    }
    return Promise.reject(new Error(`Unexpected fetch to ${method} ${url}`))
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

const renderSheet = (onChanged = vi.fn(), canManage = true) =>
  render(
    <MembersSheet
      planningStableId="p1"
      line={LINE}
      canManage={canManage}
      onClose={vi.fn()}
      onChanged={onChanged}
    />,
  )

describe('MembersSheet', () => {
  beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))

  it('asks only for a first name, a last name and an email — no identifier, role or entry date', async () => {
    stubFetch([member({})], [], () => Promise.reject(new Error('no POST')))
    renderSheet()

    await screen.findByText('Alice Martin')
    const form = screen.getByLabelText('Adresse e-mail').closest('form') as HTMLFormElement
    const fields = within(form).getAllByRole('textbox')
    expect(fields).toHaveLength(3)
    expect(fields.map(labelOf)).toEqual(['Prénom', 'Nom', 'Adresse e-mail'])
    expect(form.querySelector('select, input[type="date"]')).toBeNull()

    expect(screen.queryByLabelText(/Identifiant/i)).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Rôle')).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/Date d.entrée/i)).not.toBeInTheDocument()
    expect(screen.queryByPlaceholderText(/stableId/i)).not.toBeInTheDocument()
    expect(document.body).not.toHaveTextContent(/stableId|identifiant connu/i)
  })

  it('adds an existing account, then refreshes the members and the planning', async () => {
    const onChanged = vi.fn()
    const bob = member({ stableId: 'm2', userStableId: 'u2', firstName: 'Bob', lastName: 'Leroy' })
    const added: InviteResult = { status: 'USER_ADDED', emailSent: true, member: bob, invitation: null }
    const fetchMock = stubFetch([member({})], [member({}), bob], () => jsonResponse(added, 201))
    renderSheet(onChanged)

    await screen.findByText('Alice Martin')
    fireEvent.change(screen.getByLabelText('Prénom'), { target: { value: 'Bob' } })
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Leroy' } })
    fireEvent.change(screen.getByLabelText('Adresse e-mail'), { target: { value: 'bob@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))

    expect(await screen.findByText('Bob Leroy')).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent("ajoutée à l'équipe")
    expect(onChanged).toHaveBeenCalledTimes(1)

    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
    expect(JSON.parse(String(post?.[1]?.body))).toEqual({
      email: 'bob@example.com',
      firstName: 'Bob',
      lastName: 'Leroy',
    })
  })

  it('keeps "Rendre gestionnaire" for promoting a member later', async () => {
    stubFetch([member({})], [], () => Promise.reject(new Error('no POST')))
    renderSheet()

    expect(
      await screen.findByRole('button', { name: 'Rendre gestionnaire Alice Martin' }),
    ).toBeInTheDocument()
  })

  it('does not offer the form to someone the API did not allow to invite', async () => {
    stubFetch([member({})], [], () => Promise.reject(new Error('no POST')))
    render(
      <MembersSheet
        planningStableId="p1"
        line={{ ...LINE, team: { ...LINE.team, canInvite: false } }}
        canManage={false}
        onClose={vi.fn()}
        onChanged={vi.fn()}
      />,
    )

    await screen.findByText('Alice Martin')
    await waitFor(() => expect(screen.queryByLabelText('Adresse e-mail')).not.toBeInTheDocument())
    expect(screen.queryByRole('button', { name: /Rendre gestionnaire/ })).not.toBeInTheDocument()
  })
})

function labelOf(field: HTMLElement): string {
  const id = field.getAttribute('id')
  const label = id ? document.querySelector(`label[for="${id}"]`) : null
  return label?.textContent?.trim() ?? ''
}
