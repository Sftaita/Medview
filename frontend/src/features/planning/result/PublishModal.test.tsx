import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { status, stubApi } from '../../../testUtils/stubApi'
import { PublishModal } from './PublishModal'
import type { PublicationPreflight, PublicationResult } from './types'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function preflight(overrides: Partial<PublicationPreflight> = {}): PublicationPreflight {
  return {
    publishable: true,
    lines: [{ lineStableId: 'l1', lineName: 'Seniors', periodStatus: 'GENERATED', hasGeneration: true }],
    uncoveredDuties: [],
    inconsistentGroups: [],
    invalidAssignments: [],
    conflicts: [],
    ...overrides,
  }
}

function publicationResult(overrides: Partial<PublicationResult> = {}): PublicationResult {
  return {
    lines: [{ lineStableId: 'l1', lineName: 'Seniors', periodStatus: 'PUBLISHED', alreadyPublished: false }],
    ...overrides,
  }
}

function stubPreflight(preflightResponse: PublicationPreflight, publishReply: unknown = publicationResult()) {
  return stubApi({
    'GET /api/plannings/plan-1/publication-preflight': () => preflightResponse,
    'POST /api/plannings/plan-1/publish': () => publishReply,
  })
}

function renderModal(onClose = vi.fn(), onPublished = vi.fn()) {
  render(<PublishModal planningStableId="plan-1" onClose={onClose} onPublished={onPublished} />)
  return { onClose, onPublished }
}

describe('PublishModal', () => {
  it('shows a clear confirmation when the current calendar is publishable', async () => {
    stubPreflight(preflight())
    renderModal()

    expect(await screen.findByText(/complet et cohérent/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publier' })).toBeInTheDocument()
  })

  it('shows a clear refusal, never a publish button, when the calendar is not publishable', async () => {
    stubPreflight(
      preflight({
        publishable: false,
        uncoveredDuties: [{ dutyStableId: 'd1', date: '2027-01-05', dutyTypeName: 'Garde' }],
      }),
    )
    renderModal()

    expect(await screen.findByText('Publication impossible.')).toBeInTheDocument()
    expect(screen.getByText(/1 garde obligatoire reste.*non couverte/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Publier' })).not.toBeInTheDocument()
  })

  it('never calls POST /publish before the final "Publier" click', async () => {
    const api = stubPreflight(preflight())
    renderModal()

    await screen.findByRole('button', { name: 'Publier' })

    expect(api.requests('POST', '/api/plannings/plan-1/publish')).toHaveLength(0)
  })

  it('publishes only on explicit confirmation and reports the real per-line result', async () => {
    stubPreflight(preflight())
    const { onPublished } = renderModal()

    fireEvent.click(await screen.findByRole('button', { name: 'Publier' }))

    await waitFor(() => expect(onPublished).toHaveBeenCalledOnce())
    expect(await screen.findByText('Planning publié')).toBeInTheDocument()
    expect(screen.getByText(/Seniors/)).toBeInTheDocument()
    expect(screen.getByText(/publiée\./)).toBeInTheDocument()
  })

  it('shows a clear error and leaves the status unchanged when the server refuses at publish time', async () => {
    stubPreflight(preflight(), status(409, { error: 'not_publishable' }))
    const { onPublished } = renderModal()

    fireEvent.click(await screen.findByRole('button', { name: 'Publier' }))

    expect(await screen.findByText(/n’est plus publiable/)).toBeInTheDocument()
    expect(onPublished).not.toHaveBeenCalled()
    expect(screen.queryByText('Planning publié')).not.toBeInTheDocument()
  })

  it('"Annuler" closes without publishing', async () => {
    const api = stubPreflight(preflight())
    const { onClose } = renderModal()

    await screen.findByRole('button', { name: 'Publier' })
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))

    expect(onClose).toHaveBeenCalledOnce()
    expect(api.requests('POST', '/api/plannings/plan-1/publish')).toHaveLength(0)
  })
})
