import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
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
    republishable: true,
    lines: [{ lineStableId: 'l1', lineName: 'Seniors', periodStatus: 'GENERATED', hasGeneration: true }],
    uncoveredDuties: [],
    inconsistentGroups: [],
    invalidAssignments: [],
    conflicts: [],
    undeterminedDuties: [],
    superfluousCoverages: [],
    ...overrides,
  }
}

function publicationResult(overrides: Partial<PublicationResult> = {}): PublicationResult {
  return {
    lines: [{ lineStableId: 'l1', lineName: 'Seniors', periodStatus: 'PUBLISHED', alreadyPublished: false }],
    publication: {
      stableId: 'p1',
      kind: 'FIRST',
      publishedAt: '2027-01-02T10:00:00+00:00',
      changedDutyCount: 0,
    },
    recipientCount: 3,
    sentCount: 3,
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

  it('shows a superfluous reinforcement as a warning next to a publishable calendar (docs/decisions.md D166)', async () => {
    stubPreflight(
      preflight({
        superfluousCoverages: [
          {
            code: 'SUPERFLUOUS_CONDITIONAL_COVERAGE',
            lineStableId: 'l2',
            lineName: 'Renfort',
            duty: { dutyStableId: 'r1', date: '2027-01-05', dutyTypeName: 'Renfort' },
            unitStableKey: 'r1',
            dates: ['2027-01-05'],
            member: { teamMemberStableId: 'm-carol', firstName: 'Carol', lastName: 'Dubois' },
            source: { dutyStableId: 's1', date: '2027-01-05', holder: null },
            reason: 'HOLDER_HAS_NO_TRIGGER',
            explanation: 'Renfort non requis : Anne Admin (garde source du 5 janvier 2027) ne déclenche pas de renfort. Carol Dubois reste affecté ; son retrait n’est pas obligatoire.',
          },
        ],
      }),
    )
    renderModal()

    expect(await screen.findByText(/complet et cohérent/)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /ils n’empêchent pas la publication/ })).toBeInTheDocument()
    expect(within(screen.getByRole('list', { name: 'Avertissements' })).getByText(/Carol Dubois reste affecté/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publier' })).toBeInTheDocument()
  })

  it('names a missing required reinforcement as such, apart from missing duties (D167)', async () => {
    stubPreflight(
      preflight({
        publishable: false,
        uncoveredDuties: [
          { dutyStableId: 'd1', date: '2027-01-05', dutyTypeName: 'Garde', lineStableId: 'l1', lineName: 'Seniors', conditional: false },
          { dutyStableId: 'r1', date: '2027-01-06', dutyTypeName: 'Garde', lineStableId: 'l2', lineName: 'Renfort', conditional: true },
          { dutyStableId: 'r2', date: '2027-01-07', dutyTypeName: 'Garde', lineStableId: 'l2', lineName: 'Renfort', conditional: true },
        ],
      }),
    )
    renderModal()

    expect(await screen.findByText('Publication impossible.')).toBeInTheDocument()
    const blockers = screen.getByRole('list', { name: 'Points bloquants' })
    expect(within(blockers).getByText(/1 garde obligatoire reste non couverte/)).toBeInTheDocument()
    expect(within(blockers).getByText(/2 renforts requis ne sont pas attribués \(ligne « Renfort »\)/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Publier' })).not.toBeInTheDocument()
  })

  it('names a reinforcement whose demand cannot be evaluated as a blocker (docs/decisions.md D165)', async () => {
    stubPreflight(
      preflight({
        publishable: false,
        republishable: false,
        undeterminedDuties: [
          {
            code: 'UNDETERMINED_CONDITIONAL_DEMAND',
            lineStableId: 'l2',
            lineName: 'Renfort',
            duty: { dutyStableId: 'r1', date: '2027-01-05', dutyTypeName: 'Renfort' },
            unitStableKey: 'r1',
            dates: ['2027-01-05'],
            member: null,
            source: { dutyStableId: 's1', date: '2027-01-05', holder: null },
            reason: 'SOURCE_UNASSIGNED',
            explanation: 'La garde source du 5 janvier 2027 n’a pas de titulaire : impossible de savoir si ce renfort est requis.',
          },
        ],
      }),
    )
    renderModal()

    expect(await screen.findByText('Publication impossible.')).toBeInTheDocument()
    expect(screen.getByText(/1 renfort ne peut pas être évalué/)).toBeInTheDocument()
  })

  it('lists each refused assignment with its date, holder and rule, and locates it (docs/decisions.md D171)', async () => {
    stubPreflight(
      preflight({
        publishable: false,
        republishable: false,
        invalidAssignments: [
          {
            duty: { dutyStableId: 'd29', date: '2026-10-29', dutyTypeName: 'Garde', lineStableId: 'l1', lineName: 'Seniors' },
            unitStableKey: 'd29',
            dates: ['2026-10-29'],
            dutyStableIds: ['d29'],
            member: { teamMemberStableId: 'm1', firstName: 'Membre', lastName: 'Un' },
            reason: 'indisponible',
            reasonCode: 'UNAVAILABLE',
          },
        ],
      }),
    )
    const onLocate = vi.fn()
    render(<PublishModal planningStableId="plan-1" onClose={vi.fn()} onPublished={vi.fn()} onLocate={onLocate} />)

    const list = await screen.findByRole('list', { name: 'Affectations à corriger' })
    expect(within(list).getByText('Jeudi 29 octobre · ligne « Seniors »')).toBeInTheDocument()
    expect(within(list).getByText('Membre Un a déclaré une indisponibilité ce jour-là.')).toBeInTheDocument()
    expect(within(list).getByText(/Règle : personne n’est de garde un jour où elle s’est déclarée indisponible/)).toBeInTheDocument()
    fireEvent.click(within(list).getByRole('button', { name: 'Voir dans le calendrier' }))
    expect(onLocate).toHaveBeenCalledWith('d29')
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
