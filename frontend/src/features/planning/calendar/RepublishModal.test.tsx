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

    expect(screen.queryByText(/chacune reçoit/)).not.toBeInTheDocument()
    expect(screen.getByText(/2 personnes concernées : un email personnel/)).toBeInTheDocument()
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

describe('RepublishModal — new lines (docs/decisions.md D196)', () => {
  const SENIORS: PublicationPreflight['lines'][number] = {
    lineStableId: 'l1',
    lineName: 'Seniors',
    periodStatus: 'PUBLISHED',
    hasGeneration: true,
    published: true,
    firstPublishable: false,
    blockers: [],
    sourceLineStableId: null,
  }
  const JUNIORS: PublicationPreflight['lines'][number] = {
    lineStableId: 'l2',
    lineName: 'Juniors',
    periodStatus: 'GENERATED',
    hasGeneration: true,
    published: false,
    firstPublishable: true,
    blockers: [],
    sourceLineStableId: null,
  }

  function open(lines: PublicationPreflight['lines'], changes: PublicationChange[] = [CHANGE]) {
    const api = stubApi({
      'GET /api/plannings/plan-1/publication-preflight': () => ({ ...PREFLIGHT, lines }),
      'POST /api/plannings/plan-1/republish': () => result(),
    })
    render(
      <RepublishModal
        planningStableId="plan-1"
        changes={changes}
        onClose={vi.fn()}
        onRepublished={vi.fn()}
      />,
    )
    return api
  }

  it('never publishes a new line unless it is checked: unchecked by default, the republication sends none', async () => {
    const api = open([SENIORS, JUNIORS])

    const box = await screen.findByRole('checkbox', { name: /Juniors/ })
    expect(box).not.toBeChecked()
    expect(screen.getByRole('group', { name: 'Nouvelles lignes à publier' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Republier' }))

    expect(await screen.findByText('Modifications republiées')).toBeInTheDocument()
    expect(api.requests('POST', '/api/plannings/plan-1/republish')[0].body).toEqual({})
  })

  it('publishes the checked line with the republication, in one request', async () => {
    const api = open([SENIORS, JUNIORS])

    fireEvent.click(await screen.findByRole('checkbox', { name: /Juniors/ }))
    expect(
      screen.getByText(/recevront le planning en PDF, comme pour une première publication/),
    ).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Republier' }))

    expect(await screen.findByText('Modifications republiées')).toBeInTheDocument()
    expect(api.requests('POST', '/api/plannings/plan-1/republish')).toHaveLength(1)
    expect(api.requests('POST', '/api/plannings/plan-1/republish')[0].body).toEqual({ newLines: ['l2'] })
  })

  it('without any change, nothing can be sent until a new line is checked', async () => {
    const api = open([SENIORS, JUNIORS], [])

    expect(await screen.findByText('Publier de nouvelles lignes ?')).toBeInTheDocument()
    const send = await screen.findByRole('button', { name: 'Publier' })
    expect(send).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox', { name: /Juniors/ }))
    expect(send).toBeEnabled()
    fireEvent.click(send)

    await screen.findByText('Modifications republiées')
    expect(api.requests('POST', '/api/plannings/plan-1/republish')[0].body).toEqual({ newLines: ['l2'] })
  })

  it('a line not ready says why and cannot be chosen; a reinforcement waits for its source', async () => {
    open([
      SENIORS,
      { ...JUNIORS, firstPublishable: false, blockers: ['UNCOVERED_DUTIES'] },
      { ...JUNIORS, lineStableId: 'l3', lineName: 'Renfort', sourceLineStableId: 'l4' },
      { ...JUNIORS, lineStableId: 'l4', lineName: 'Source' },
    ])

    const juniors = await screen.findByRole('checkbox', { name: /Juniors/ })
    expect(juniors).toBeDisabled()
    expect(screen.getByText(/des gardes obligatoires ne sont pas couvertes/)).toBeInTheDocument()
    const renfort = screen.getByRole('checkbox', { name: /Renfort/ })
    expect(renfort).toBeDisabled()
    expect(screen.getByText(/se publie avec ou après « Source »/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Source' }))
    expect(renfort).toBeEnabled()
    fireEvent.click(renfort)
    expect(renfort).toBeChecked()
    // The source unchecked again: its reinforcement goes with it, never published before it.
    fireEvent.click(screen.getByRole('checkbox', { name: 'Source' }))
    expect(renfort).not.toBeChecked()
    expect(renfort).toBeDisabled()
  })

  it('only the published lines decide whether the republication can go out', async () => {
    open([
      SENIORS,
      { ...JUNIORS, firstPublishable: false, hasGeneration: false, blockers: ['NOT_GENERATED'] },
    ])

    expect(await screen.findByRole('button', { name: 'Republier' })).toBeEnabled()
    expect(screen.queryByText(/Republication impossible/)).not.toBeInTheDocument()
  })

  it('republishes the selected period — named in both requests, never a new line by itself (lot L6)', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/publication-preflight': () => PREFLIGHT,
      'POST /api/plannings/plan-1/republish': () => result(),
    })
    render(
      <RepublishModal
        planningStableId="plan-1"
        periodStableId="c2"
        changes={[CHANGE]}
        onClose={vi.fn()}
        onRepublished={vi.fn()}
      />,
    )

    fireEvent.click(await screen.findByRole('button', { name: 'Republier' }))
    await vi.waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/republish')).toHaveLength(1))
    expect(api.requests('GET', '/api/plannings/plan-1/publication-preflight')[0].query.get('period')).toBe(
      'c2',
    )
    const republish = api.requests('POST', '/api/plannings/plan-1/republish')[0]
    expect(republish.query.get('period')).toBe('c2')
    expect(republish.body).toEqual({})
  })
})
