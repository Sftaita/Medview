import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../../../testUtils/stubApi'
import type { PublicationChange, PublicationPreflight, PublicationResult } from '../result/types'
import { RepublishModal } from './RepublishModal'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

const PREFLIGHT: PublicationPreflight = {
  publishable: true,
  republishable: true,
  lines: [],
  uncoveredDuties: [],
  inconsistentGroups: [],
  invalidAssignments: [],
  conflicts: [],
  undeterminedDuties: [],
  superfluousCoverages: [],
}

const CHANGE: PublicationChange = {
  dutyStableId: 'd6',
  date: '2027-01-06',
  lineStableId: 'l1',
  lineName: 'Seniors',
  groupInstanceStableId: null,
  before: { firstName: 'Alice', lastName: 'Bernard' },
  after: { firstName: 'Bob', lastName: 'Claes' },
  beforeShown: true,
  afterShown: true,
}

function result(overrides: Partial<PublicationResult> = {}): PublicationResult {
  return {
    lines: [{ lineStableId: 'l1', lineName: 'Seniors', periodStatus: 'PUBLISHED', alreadyPublished: true }],
    publication: {
      stableId: 'p2',
      kind: 'UPDATE',
      publishedAt: '2027-01-02T10:00:00+00:00',
      changedDutyCount: 1,
    },
    recipientCount: 2,
    sentCount: 2,
    ...overrides,
  }
}

async function republishWith(reply: PublicationResult) {
  stubApi({
    'GET /api/plannings/plan-1/publication-preflight': () => PREFLIGHT,
    'POST /api/plannings/plan-1/republish': () => reply,
  })
  const onRepublished = vi.fn()
  render(
    <RepublishModal
      planningStableId="plan-1"
      changes={[CHANGE]}
      onClose={vi.fn()}
      onRepublished={onRepublished}
    />,
  )
  fireEvent.click(await screen.findByRole('button', { name: 'Republier' }))
  expect(await screen.findByText('Modifications republiées')).toBeInTheDocument()
  return onRepublished
}

describe('RepublishModal (docs/decisions.md D172)', () => {
  it('says that only the people whose own duties change are emailed, each with their own changes and the PDF', async () => {
    stubApi({ 'GET /api/plannings/plan-1/publication-preflight': () => PREFLIGHT })
    render(
      <RepublishModal
        planningStableId="plan-1"
        changes={[CHANGE]}
        onClose={vi.fn()}
        onRepublished={vi.fn()}
      />,
    )

    expect(await screen.findByRole('button', { name: 'Republier' })).toBeInTheDocument()
    expect(
      screen.getByText(/Seules les personnes dont les gardes changent reçoivent un email/),
    ).toBeInTheDocument()
    expect(screen.getByText(/ses propres changements, avec le planning actualisé en PDF/)).toBeInTheDocument()
    expect(screen.queryByText(/autres lignes/)).not.toBeInTheDocument()
  })

  it('reports the personal emails sent', async () => {
    const onRepublished = await republishWith(result())

    expect(
      screen.getByText(/2 personnes concernées — chacune reçoit le détail de ses propres changements/),
    ).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(onRepublished).toHaveBeenCalledOnce()
  })

  it('never claims everyone was told when an email could not be sent yet', async () => {
    await republishWith(result({ recipientCount: 2, sentCount: 1 }))

    expect(screen.getByRole('alert')).toHaveTextContent(
      '1 email n’a pas pu être envoyé pour l’instant : il sera renvoyé automatiquement.',
    )
  })

  it('says so when nobody is concerned', async () => {
    await republishWith(result({ recipientCount: 0, sentCount: 0 }))

    expect(
      screen.getByText('Planning republié. Aucune personne n’est concernée : aucun email envoyé.'),
    ).toBeInTheDocument()
  })
})
