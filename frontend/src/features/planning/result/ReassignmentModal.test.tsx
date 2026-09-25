import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { status, stubApi } from '../../../testUtils/stubApi'
import { ReassignmentModal } from './ReassignmentModal'
import type { ReassignmentCandidatesView } from './types'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function view(overrides: Partial<ReassignmentCandidatesView> = {}): ReassignmentCandidatesView {
  return {
    groupInstanceStableId: null,
    blockDuties: [
      { dutyStableId: 'd1', date: '2027-01-12', startsAt: '', endsAt: '', dutyTypeName: 'Garde' },
    ],
    generationStableId: 'g1',
    currentTeamMemberStableId: 'm-alice',
    candidates: [
      {
        teamMemberStableId: 'm-alice',
        firstName: 'Alice',
        lastName: 'Martin',
        selectable: true,
        isCurrent: true,
        blockingReasons: [],
      },
      {
        teamMemberStableId: 'm-bob',
        firstName: 'Bob',
        lastName: 'Durand',
        selectable: true,
        isCurrent: false,
        blockingReasons: [],
      },
      {
        teamMemberStableId: 'm-carla',
        firstName: 'Carla',
        lastName: 'Petit',
        selectable: false,
        isCurrent: false,
        blockingReasons: ['indisponible'],
      },
    ],
    ...overrides,
  }
}

function stubCandidates(v: ReassignmentCandidatesView, reassignReply: unknown = { status: 'reassigned' }) {
  return stubApi({
    'GET /api/plannings/plan-1/duties/d1/reassignment-candidates': () => v,
    'POST /api/plannings/plan-1/duties/d1/reassign': () => reassignReply,
  })
}

function renderModal(onClose = vi.fn(), onReassigned = vi.fn()) {
  render(
    <ReassignmentModal
      planningStableId="plan-1"
      dutyStableId="d1"
      onClose={onClose}
      onReassigned={onReassigned}
    />,
  )
  return { onClose, onReassigned }
}

async function chooseBob() {
  await screen.findByText('Bob Durand')
  const bobRow = screen.getByText('Bob Durand').closest<HTMLElement>('.reassignment-candidate')!
  fireEvent.click(within(bobRow).getByRole('button', { name: 'Choisir' }))
}

describe('ReassignmentModal', () => {
  it('shows every real candidate, disabled ones with their real reason, never hidden', async () => {
    stubCandidates(view())
    renderModal()

    expect(await screen.findByText('Bob Durand')).toBeInTheDocument()
    expect(screen.getByText('Carla Petit')).toBeInTheDocument()
    expect(screen.getByText('— indisponible')).toBeInTheDocument()
    expect(screen.getByText('— actuellement attribué')).toBeInTheDocument()

    const carlaButton = screen.getAllByRole('button', { name: 'Choisir' }).find((b) => {
      return b.closest('.reassignment-candidate')?.textContent?.includes('Carla')
    })
    expect(carlaButton).toBeDisabled()
  })

  it('does nothing until "Enregistrer la modification" is clicked, and a candidate choice never touches the calendar first', async () => {
    const api = stubCandidates(view())
    const { onReassigned } = renderModal()

    await chooseBob()

    expect(api.requests('POST', '/api/plannings/plan-1/duties/d1/reassign')).toHaveLength(0)
    expect(onReassigned).not.toHaveBeenCalled()
  })

  it('"Annuler" discards the selection: no save request is ever made', async () => {
    const api = stubCandidates(view())
    const { onClose } = renderModal()

    await chooseBob()
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))

    expect(onClose).toHaveBeenCalledOnce()
    expect(api.requests('POST', '/api/plannings/plan-1/duties/d1/reassign')).toHaveLength(0)
  })

  it('saves only on explicit confirmation, with the real expected current identity, then refreshes', async () => {
    const api = stubCandidates(view())
    const { onReassigned } = renderModal()

    await chooseBob()
    expect(await screen.findByText('Bob Durand', { selector: 'strong' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la modification' }))

    await waitFor(() => expect(onReassigned).toHaveBeenCalledOnce())
    const calls = api.requests('POST', '/api/plannings/plan-1/duties/d1/reassign')
    expect(calls).toHaveLength(1)
    expect(calls[0].body).toEqual({
      teamMemberStableId: 'm-bob',
      expectedCurrentTeamMemberStableId: 'm-alice',
    })
    expect(await screen.findByText('Modification enregistrée')).toBeInTheDocument()
  })

  it('shows a clear message and never silently overwrites on a stale save (409)', async () => {
    stubCandidates(view(), status(409, { error: 'stale_reassignment' }))
    renderModal()

    await chooseBob()
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la modification' }))

    expect(
      await screen.findByText(/Le planning a changé depuis l.ouverture de cette fenêtre/),
    ).toBeInTheDocument()
  })

  it('shows a clear message when the chosen candidate is no longer valid at save time (409)', async () => {
    stubCandidates(view(), status(409, { error: 'invalid_candidate' }))
    renderModal()

    await chooseBob()
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la modification' }))

    expect(await screen.findByText(/Cette attribution n.est plus possible/)).toBeInTheDocument()
  })

  it('describes an atomic block by its date range and duty count', async () => {
    stubCandidates(
      view({
        blockDuties: [
          { dutyStableId: 'd1', date: '2027-01-16', startsAt: '', endsAt: '', dutyTypeName: 'Garde' },
          { dutyStableId: 'd2', date: '2027-01-17', startsAt: '', endsAt: '', dutyTypeName: 'Garde' },
        ],
      }),
    )
    renderModal()

    expect(await screen.findByText(/Bloc du samedi 16 janvier au dimanche 17 janvier/)).toBeInTheDocument()
    expect(screen.getByText('Modifier le bloc de garde')).toBeInTheDocument()
  })
})
