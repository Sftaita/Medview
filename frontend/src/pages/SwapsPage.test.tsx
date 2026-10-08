import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { SwapRequestDetail } from '../features/swaps/types'
import { ADAM, ALICE, BOB, makeProposal, makeRequest, makeUnit } from '../testUtils/swapFixtures'
import { status, stubApi } from '../testUtils/stubApi'
import { SwapsPage } from './SwapsPage'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function renderPage(path = '/swaps') {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/swaps" element={<SwapsPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

/** Bob's view of Alice's agreed proposal: he decides. */
const RECEIVED: SwapRequestDetail = makeRequest({
  viewerRole: 'RECIPIENT',
  actions: { cancel: false, propose: false },
  proposals: [makeProposal({ actions: { accept: true, refuse: true, withdraw: false } })],
})

describe('SwapsPage — "Échanges"', () => {
  it('shows the three lists with French statuses and what waits for an answer', async () => {
    stubApi({
      'GET /api/me/duty-swaps': () => ({
        mine: [
          makeRequest(),
          makeRequest({
            stableId: 'req-2',
            status: 'COMPLETED',
            proposals: [makeProposal({ status: 'ACCEPTED' })],
          }),
          makeRequest({ stableId: 'req-3', status: 'CANCELLED' }),
          makeRequest({ stableId: 'req-4', status: 'EXPIRED' }),
          makeRequest({ stableId: 'req-5', status: 'OBSOLETE' }),
          makeRequest({ stableId: 'req-6', status: 'REFUSED' }),
        ],
        received: [RECEIVED],
        team: [],
      }),
    })
    renderPage()

    const mine = await screen.findByRole('region', { name: 'Mes demandes' })
    const rows = within(mine).getAllByRole('button')
    expect(
      rows.map(
        (row) =>
          within(row).getByText(/En attente|Échange confirmé|Annulé|Expiré|Devenu indisponible|Refusé/)
            .textContent,
      ),
    ).toEqual([
      'En attente de réponse',
      'Échange confirmé',
      'Annulé',
      'Expiré',
      'Devenu indisponible',
      'Refusé',
    ])
    expect(rows[0]).toHaveTextContent('Mar. 5 janv. · Garde')
    expect(rows[0]).toHaveTextContent('À : Bob Durand · Seniors')

    // One proposal waits for Bob's answer in "Propositions reçues".
    expect(screen.getByRole('button', { name: /Propositions reçues/ })).toHaveTextContent('1')
    fireEvent.click(screen.getByRole('button', { name: /Propositions reçues/ }))
    const received = screen.getByRole('region', { name: 'Propositions reçues' })
    expect(received).toHaveTextContent('De : Alice Martin')
    expect(received).toHaveTextContent('Proposition reçue')
    expect(received).toHaveTextContent('À traiter')
  })

  it('accepts only after an explicit confirmation, then shows what the server recorded', async () => {
    let accepted = false
    const api = stubApi({
      'GET /api/me/duty-swaps': () => ({ mine: [], received: [RECEIVED], team: [] }),
      'GET /api/duty-swap-requests/req-1': () => RECEIVED,
      'POST /api/duty-swap-proposals/prop-1/accept': () => {
        accepted = true
        return makeRequest({
          ...RECEIVED,
          status: 'COMPLETED',
          acceptedProposalStableId: 'prop-1',
          proposals: [makeProposal({ status: 'ACCEPTED' })],
          alreadyApplied: false,
        })
      },
    })
    renderPage('/swaps?request=req-1')

    const drawer = await screen.findByRole('dialog', { name: 'Demande d’échange' })
    expect(await within(drawer).findByText(/Rien n’est modifié dans le planning/)).toBeInTheDocument()
    fireEvent.click(within(drawer).getByRole('button', { name: 'Accepter' }))
    expect(accepted).toBe(false)
    expect(within(drawer).getByText('Confirmer l’échange ?')).toBeInTheDocument()
    // Bob would take Alice's Tuesday 5, Alice his Tuesday 12.
    expect(drawer).toHaveTextContent(
      'Vous assurerez Mar. 5 janv. · Garde. Alice Martin assurera Mar. 12 janv. · Garde.',
    )

    fireEvent.click(within(drawer).getByRole('button', { name: 'Confirmer l’échange' }))
    expect(await within(drawer).findByRole('status')).toHaveTextContent('Échange confirmé et enregistré')
    expect(within(drawer).getByText(/Pensez à prévenir la direction/)).toBeInTheDocument()
    expect(within(drawer).getAllByText('Échange confirmé').length).toBeGreaterThan(0)
    expect(api.requests('POST', '/api/duty-swap-proposals/prop-1/accept')).toHaveLength(1)
    expect(api.requests('GET', '/api/me/duty-swaps').length).toBeGreaterThanOrEqual(2)
  })

  it('shows why an acceptance was refused and changes nothing on screen', async () => {
    stubApi({
      'GET /api/me/duty-swaps': () => ({ mine: [], received: [RECEIVED], team: [] }),
      'GET /api/duty-swap-requests/req-1': () => RECEIVED,
      'POST /api/duty-swap-proposals/prop-1/accept': () =>
        status(409, {
          error: 'swap_not_applicable',
          reason: 'UNAVAILABLE',
          message: 'Bob Durand ne peut pas assurer la garde du mardi 5 janvier 2027 : indisponible.',
        }),
    })
    renderPage('/swaps?request=req-1')

    const drawer = await screen.findByRole('dialog', { name: 'Demande d’échange' })
    fireEvent.click(await within(drawer).findByRole('button', { name: 'Accepter' }))
    fireEvent.click(within(drawer).getByRole('button', { name: 'Confirmer l’échange' }))

    expect(await within(drawer).findByRole('alert')).toHaveTextContent('indisponible')
    expect(within(drawer).getAllByText('Proposition reçue').length).toBeGreaterThan(0)
  })

  it('lets the requester cancel her request after confirming, and reminds her she stays responsible', async () => {
    const api = stubApi({
      'GET /api/me/duty-swaps': () => ({ mine: [makeRequest()], received: [], team: [] }),
      'GET /api/duty-swap-requests/req-1': () => makeRequest(),
      'POST /api/duty-swap-requests/req-1/cancel': () =>
        makeRequest({ status: 'CANCELLED', actions: { cancel: false, propose: false } }),
    })
    renderPage('/swaps?request=req-1')

    const drawer = await screen.findByRole('dialog', { name: 'Demande d’échange' })
    expect(
      await within(drawer).findByRole('note', { name: 'Responsabilité de votre garde' }),
    ).toBeInTheDocument()
    expect(within(drawer).queryByRole('button', { name: 'Accepter' })).not.toBeInTheDocument()

    fireEvent.click(within(drawer).getByRole('button', { name: 'Annuler la demande' }))
    fireEvent.click(within(drawer).getByRole('button', { name: 'Oui, annuler la demande' }))
    expect(await within(drawer).findByText('Demande annulée.')).toBeInTheDocument()
    expect(within(drawer).getByText('Annulé')).toBeInTheDocument()
    expect(api.requests('POST', '/api/duty-swap-requests/req-1/cancel')).toHaveLength(1)
  })

  it('lets a team member propose one of their own duties on a whole-team request', async () => {
    const teamRequest = makeRequest({
      kind: 'SEARCH',
      audience: 'ALL',
      viewerRole: 'TEAM',
      recipients: [],
      proposals: [],
      actions: { cancel: false, propose: true },
      proposableUnits: [
        makeUnit(['2027-01-19']),
        makeUnit(['2027-01-23', '2027-01-24'], { blockName: 'Week-end' }),
      ],
    })
    const api = stubApi({
      'GET /api/me/duty-swaps': () => ({ mine: [], received: [], team: [teamRequest] }),
      'GET /api/duty-swap-requests/req-1': () => teamRequest,
      'POST /api/duty-swap-requests/req-1/proposals': () => ({
        ...teamRequest,
        actions: { cancel: false, propose: false },
        proposals: [
          makeProposal({
            author: ADAM,
            counterpart: ADAM,
            decider: ALICE,
            counterpartUnit: makeUnit(['2027-01-19']),
            actions: { accept: false, refuse: false, withdraw: true },
          }),
        ],
      }),
    })
    renderPage('/swaps?request=req-1')

    const drawer = await screen.findByRole('dialog', { name: 'Demande d’échange' })
    expect(await within(drawer).findByText('Toute l’équipe (Seniors)')).toBeInTheDocument()
    fireEvent.change(within(drawer).getByLabelText('Votre garde'), { target: { value: 'duty-2027-01-19' } })
    expect(drawer).toHaveTextContent('Jusque-là, vous restez responsable de votre garde.')
    fireEvent.click(within(drawer).getByRole('button', { name: 'Proposer cette garde' }))

    expect(await within(drawer).findByText(/Proposition envoyée/)).toBeInTheDocument()
    expect(api.requests('POST', '/api/duty-swap-requests/req-1/proposals')[0].body).toEqual({
      dutyStableId: 'duty-2027-01-19',
    })
    expect(within(drawer).getByRole('button', { name: 'Retirer ma proposition' })).toBeInTheDocument()
  })

  it('shows the chronology from the server', async () => {
    const detail = makeRequest({
      status: 'COMPLETED',
      proposals: [makeProposal({ status: 'ACCEPTED' })],
      history: [
        {
          stableId: 'e1',
          type: 'REQUEST_CREATED',
          occurredAt: '2026-12-10T09:00:00+00:00',
          actor: ALICE,
          proposalStableId: null,
          data: {},
        },
        {
          stableId: 'e2',
          type: 'SWAP_VALIDATION_FAILED',
          occurredAt: '2026-12-11T09:00:00+00:00',
          actor: BOB,
          proposalStableId: 'prop-1',
          data: { reason: 'UNAVAILABLE' },
        },
        {
          stableId: 'e3',
          type: 'PROPOSAL_ACCEPTED',
          occurredAt: '2026-12-12T09:00:00+00:00',
          actor: BOB,
          proposalStableId: 'prop-1',
          data: {},
        },
        {
          stableId: 'e4',
          type: 'SWAP_COMPLETED',
          occurredAt: '2026-12-12T09:00:00+00:00',
          actor: BOB,
          proposalStableId: 'prop-1',
          data: {},
        },
      ],
    })
    stubApi({
      'GET /api/me/duty-swaps': () => ({ mine: [detail], received: [], team: [] }),
      'GET /api/duty-swap-requests/req-1': () => detail,
    })
    renderPage('/swaps?request=req-1')

    const history = await screen.findByRole('region', { name: 'Historique' })
    expect(
      within(history)
        .getAllByRole('listitem')
        .map((item) => item.textContent),
    ).toEqual([
      expect.stringContaining('Alice Martin a demandé un échange'),
      expect.stringContaining(
        'Tentative d’acceptation refusée par la vérification finale — rien n’a été modifié',
      ),
      expect.stringContaining('Bob Durand a accepté la proposition'),
      expect.stringContaining('Échange enregistré dans le planning'),
    ])
  })

  it('opens an email link on the list the request belongs to', async () => {
    stubApi({
      'GET /api/me/duty-swaps': () => ({ mine: [], received: [RECEIVED], team: [] }),
      'GET /api/duty-swap-requests/req-1': () => RECEIVED,
    })
    renderPage('/swaps?request=req-1')

    expect(await screen.findByRole('region', { name: 'Propositions reçues' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Propositions reçues/ })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  it('says when a request cannot be found', async () => {
    stubApi({
      'GET /api/me/duty-swaps': () => ({ mine: [], received: [], team: [] }),
      'GET /api/duty-swap-requests/nope': () =>
        status(404, { error: 'not_found', message: 'Cette demande d’échange est introuvable.' }),
    })
    renderPage('/swaps?request=nope')

    expect(await screen.findByRole('alert')).toHaveTextContent('Cette demande d’échange est introuvable.')
    await waitFor(() => expect(screen.getByRole('region', { name: 'Mes demandes' })).toBeInTheDocument())
  })
})
