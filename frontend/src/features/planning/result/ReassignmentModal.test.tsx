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
    groupLabel: null,
    blockDuties: [
      { dutyStableId: 'd1', date: '2027-01-12', startsAt: '', endsAt: '', dutyTypeName: 'Garde' },
    ],
    generationStableId: 'g1',
    currentTeamMemberStableId: 'm-alice',
    currentAssignee: { teamMemberStableId: 'm-alice', firstName: 'Alice', lastName: 'Martin' },
    candidates: [
      { teamMemberStableId: 'm-bob', firstName: 'Bob', lastName: 'Durand' },
      { teamMemberStableId: 'm-dan', firstName: 'Dan', lastName: 'Roux' },
    ],
    ...overrides,
  }
}

function stubCandidates(
  v: ReassignmentCandidatesView,
  reassignReply: unknown = { status: 'reassigned' },
  unassignReply: unknown = { status: 'unassigned' },
) {
  return stubApi({
    'GET /api/plannings/plan-1/duties/d1/reassignment-candidates': () => v,
    'POST /api/plannings/plan-1/duties/d1/reassign': () => reassignReply,
    'POST /api/plannings/plan-1/duties/d1/unassign': () => unassignReply,
  })
}

function renderModal(onClose = vi.fn(), onChanged = vi.fn()) {
  render(
    <ReassignmentModal planningStableId="plan-1" dutyStableId="d1" onClose={onClose} onChanged={onChanged} />,
  )
  return { onClose, onChanged }
}

async function chooseBob() {
  await screen.findByText('Bob Durand')
  const bobRow = screen.getByText('Bob Durand').closest<HTMLElement>('.reassignment-candidate')!
  fireEvent.click(within(bobRow).getByRole('button', { name: 'Choisir' }))
}

describe('ReassignmentModal (assignment editor)', () => {
  it('shows who holds the duty now and lists only the real replacements (docs/decisions.md D144)', async () => {
    stubCandidates(view())
    renderModal()

    expect(await screen.findByText('Alice Martin', { selector: 'strong' })).toBeInTheDocument()
    const list = screen.getByRole('list', { name: 'Candidats' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(2)
    expect(within(list).queryByText('Alice Martin')).not.toBeInTheDocument()
    expect(screen.queryByText(/indisponible/)).not.toBeInTheDocument()
  })

  it('says so plainly when nobody of the line can take it', async () => {
    stubCandidates(view({ candidates: [] }))
    renderModal()

    expect(await screen.findByText(/Personne de cette ligne ne peut prendre cette garde/)).toBeInTheDocument()
  })

  it('does nothing until "Remplacer" is clicked, and "Annuler" discards the choice', async () => {
    const api = stubCandidates(view())
    const { onClose, onChanged } = renderModal()

    await chooseBob()
    expect(api.requests('POST', '/api/plannings/plan-1/duties/d1/reassign')).toHaveLength(0)
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))

    expect(onClose).toHaveBeenCalledOnce()
    expect(onChanged).not.toHaveBeenCalled()
    expect(api.requests('POST', '/api/plannings/plan-1/duties/d1/reassign')).toHaveLength(0)
  })

  it('replaces with the real expected current identity, then refreshes', async () => {
    const api = stubCandidates(view())
    const { onChanged } = renderModal()

    await chooseBob()
    fireEvent.click(screen.getByRole('button', { name: 'Remplacer' }))

    await waitFor(() => expect(onChanged).toHaveBeenCalledOnce())
    expect(api.requests('POST', '/api/plannings/plan-1/duties/d1/reassign')[0].body).toEqual({
      teamMemberStableId: 'm-bob',
      expectedCurrentTeamMemberStableId: 'm-alice',
    })
    expect(await screen.findByText('Modification enregistrée')).toBeInTheDocument()
  })

  it('removes the holder without replacement only after an explicit confirmation', async () => {
    const api = stubCandidates(view())
    const { onChanged } = renderModal()

    fireEvent.click(await screen.findByRole('button', { name: 'Retirer l’affectation' }))
    expect(screen.getByText(/restera/)).toHaveTextContent('non attribuée')
    expect(api.requests('POST', '/api/plannings/plan-1/duties/d1/unassign')).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer le retrait' }))

    await waitFor(() => expect(onChanged).toHaveBeenCalledOnce())
    expect(api.requests('POST', '/api/plannings/plan-1/duties/d1/unassign')[0].body).toEqual({
      expectedCurrentTeamMemberStableId: 'm-alice',
    })
    expect(await screen.findByText('La garde est désormais non attribuée.')).toBeInTheDocument()
  })

  it('offers no removal on a duty that is already uncovered — only an assignment', async () => {
    stubCandidates(view({ currentTeamMemberStableId: null, currentAssignee: null }))
    renderModal()

    expect(await screen.findByText('⚠ Non attribué')).toBeInTheDocument()
    expect(screen.getByText('Attribuer à')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Retirer l’affectation' })).not.toBeInTheDocument()
  })

  it('shows a clear message and never silently overwrites on a stale save (409)', async () => {
    stubCandidates(view(), status(409, { error: 'stale_reassignment' }))
    renderModal()

    await chooseBob()
    fireEvent.click(screen.getByRole('button', { name: 'Remplacer' }))

    expect(
      await screen.findByText(/Le planning a changé depuis l.ouverture de cette fenêtre/),
    ).toBeInTheDocument()
  })

  it('shows a clear message when the chosen candidate is no longer valid at save time (409)', async () => {
    stubCandidates(view(), status(409, { error: 'invalid_candidate' }))
    renderModal()

    await chooseBob()
    fireEvent.click(screen.getByRole('button', { name: 'Remplacer' }))

    expect(await screen.findByText(/Cette attribution n.est plus possible/)).toBeInTheDocument()
  })

  it('works on the whole atomic block, named by its pattern and date range', async () => {
    stubCandidates(
      view({
        groupInstanceStableId: 'b1',
        groupLabel: 'Week-end',
        blockDuties: [
          { dutyStableId: 'd1', date: '2027-01-16', startsAt: '', endsAt: '', dutyTypeName: 'Garde' },
          { dutyStableId: 'd2', date: '2027-01-17', startsAt: '', endsAt: '', dutyTypeName: 'Garde' },
        ],
      }),
    )
    renderModal()

    expect(
      await screen.findByText(
        /Bloc Week-end du samedi 16 janvier au dimanche 17 janvier — 2 gardes modifiées ensemble/,
      ),
    ).toBeInTheDocument()
    expect(screen.getByText('Modifier le bloc de garde')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Retirer l’affectation' }))
    expect(screen.getByText(/de tout le bloc/)).toBeInTheDocument()
  })
})
