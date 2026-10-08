import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { status, stubApi } from '../../testUtils/stubApi'
import { SwapRequestDialog } from './SwapRequestDialog'
import { makeOptions, makeRequest } from '../../testUtils/swapFixtures'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function renderDialog(onCreated = vi.fn()) {
  render(
    <MemoryRouter>
      <SwapRequestDialog dutyStableId="duty-2027-01-05" onClose={() => {}} onCreated={onCreated} />
    </MemoryRouter>,
  )
  return onCreated
}

const SEND = /Envoyer la (demande|proposition)/

describe('SwapRequestDialog — "Échanger ma garde"', () => {
  it('sends nothing before the responsibility warning is acknowledged, then an agreed swap with the colleague’s duty', async () => {
    const api = stubApi({
      'GET /api/duty-swaps/options': () => makeOptions(),
      'POST /api/duty-swap-requests': () => makeRequest(),
    })
    const onCreated = renderDialog()

    expect(await screen.findByText('Mar. 5 janv. · Garde')).toBeInTheDocument()
    fireEvent.click(screen.getByLabelText(/J’ai déjà convenu d’un échange/))
    fireEvent.change(screen.getByLabelText('Collègue'), { target: { value: 'u-bob' } })
    const duties = screen.getByRole('radiogroup', { name: 'Gardes de Bob Durand' })
    expect(within(duties).getAllByRole('radio')).toHaveLength(2)
    fireEvent.click(within(duties).getByLabelText(/Mar\. 12 janv\./))

    // The new distribution is shown — as a proposal only.
    const summary = screen.getByLabelText('Nouvelle répartition proposée')
    expect(summary).toHaveTextContent('Vous assureriez Mar. 12 janv. · Garde')
    expect(summary).toHaveTextContent('Bob Durand assurerait Mar. 5 janv. · Garde')
    expect(summary).toHaveTextContent('Seulement après son acceptation')

    // The warning, word for word, and the send button locked until it is acknowledged.
    const notice = screen.getByRole('note', { name: 'Responsabilité de votre garde' })
    expect(notice).toHaveTextContent(
      'Tant qu’un autre membre n’a pas accepté votre demande et que l’échange n’a pas été confirmé dans MedVue, vous restez personnellement responsable de votre garde initiale.',
    )
    expect(notice).toHaveTextContent(
      'ne vous décharge pas de votre responsabilité et ne modifie pas le planning',
    )
    expect(screen.getByRole('button', { name: SEND })).toBeDisabled()

    fireEvent.click(screen.getByLabelText(/J’ai compris/))
    fireEvent.click(screen.getByRole('button', { name: 'Envoyer la proposition' }))

    await waitFor(() => expect(onCreated).toHaveBeenCalledOnce())
    expect(api.requests('POST', '/api/duty-swap-requests')[0].body).toEqual({
      dutyStableId: 'duty-2027-01-05',
      kind: 'AGREED',
      audience: 'SELECTED',
      recipientUserStableIds: ['u-bob'],
      counterpartDutyStableId: 'duty-2027-01-12',
      acknowledgedResponsibility: true,
    })
  })

  it('asks the whole team, several colleagues or one colleague', async () => {
    const api = stubApi({
      'GET /api/duty-swaps/options': () => makeOptions(),
      'POST /api/duty-swap-requests': () => makeRequest({ kind: 'SEARCH', audience: 'ALL' }),
    })
    renderDialog()

    fireEvent.click(await screen.findByLabelText(/Je cherche quelqu’un avec qui échanger/))
    fireEvent.click(screen.getByRole('button', { name: 'Plusieurs collègues' }))
    fireEvent.click(screen.getByLabelText('Adam Leroy'))
    fireEvent.click(screen.getByLabelText('Bob Durand'))
    fireEvent.click(screen.getByRole('button', { name: 'Toute l’équipe' }))
    expect(screen.getByText(/Tous les membres de la ligne Seniors verront votre demande/)).toBeInTheDocument()
    fireEvent.click(screen.getByLabelText(/J’ai compris/))
    fireEvent.click(screen.getByRole('button', { name: 'Envoyer la demande' }))

    await waitFor(() => expect(api.requests('POST', '/api/duty-swap-requests')).toHaveLength(1))
    expect(api.requests('POST', '/api/duty-swap-requests')[0].body).toMatchObject({
      kind: 'SEARCH',
      audience: 'ALL',
      recipientUserStableIds: [],
      acknowledgedResponsibility: true,
    })
  })

  it('shows the server’s reason when the swap is impossible', async () => {
    stubApi({
      'GET /api/duty-swaps/options': () => makeOptions(),
      'POST /api/duty-swap-requests': () =>
        status(409, {
          error: 'swap_not_applicable',
          reason: 'LEGAL_MIN_REST',
          message:
            'Bob Durand ne peut pas assurer la garde du mardi 5 janvier 2027 : repos légal insuffisant.',
        }),
    })
    const onCreated = renderDialog()

    fireEvent.click(await screen.findByLabelText(/J’ai déjà convenu d’un échange/))
    fireEvent.change(screen.getByLabelText('Collègue'), { target: { value: 'u-bob' } })
    fireEvent.click(screen.getByLabelText(/Mar\. 12 janv\./))
    fireEvent.click(screen.getByLabelText(/J’ai compris/))
    fireEvent.click(screen.getByRole('button', { name: 'Envoyer la proposition' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('repos légal insuffisant')
    expect(onCreated).not.toHaveBeenCalled()
  })

  it('points to the request already open instead of offering a second one', async () => {
    stubApi({ 'GET /api/duty-swaps/options': () => makeOptions({ openRequestStableId: 'req-9' }) })
    renderDialog()

    expect(
      await screen.findByText('Une demande d’échange est déjà en cours pour cette garde.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Voir la demande' })).toHaveAttribute(
      'href',
      '/swaps?request=req-9',
    )
    expect(screen.queryByRole('button', { name: SEND })).not.toBeInTheDocument()
  })

  it('explains why a duty cannot be swapped', async () => {
    stubApi({
      'GET /api/duty-swaps/options': () =>
        status(409, {
          error: 'duty_started',
          message: 'Cette garde a déjà commencé : elle ne peut plus être échangée.',
        }),
    })
    renderDialog()

    expect(await screen.findByRole('alert')).toHaveTextContent('Cette garde a déjà commencé')
  })
})
