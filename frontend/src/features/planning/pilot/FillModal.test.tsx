import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makePreflight, makeStatus } from '../../../testUtils/pilotFixtures'
import { status as httpStatus, stubApi } from '../../../testUtils/stubApi'
import { FillModal, FillResultBody } from './FillModal'
import { PilotHeaderActions } from './PilotHeaderActions'
import type { FillLinePlan, FillPreview, PlanningJob } from './types'

// "Générer les postes non pourvus" / "Générer cette ligne" (docs/decisions.md D222).

beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function plan(overrides: Partial<FillLinePlan>): FillLinePlan {
  return {
    lineStableId: 'l1',
    lineName: 'Seniors',
    lineType: 'PRIMARY',
    periodStatus: 'GENERATED',
    generated: true,
    action: 'NOTHING',
    reason: 'nothing_to_complete',
    selectable: false,
    selectedByDefault: false,
    requiredDutyCount: 30,
    coveredRequiredDutyCount: 30,
    uncoveredRequiredDutyCount: 0,
    protectedAssignmentCount: 30,
    blockers: [],
    warnings: [],
    afterSource: false,
    ...overrides,
  }
}

const SENIORS_PUBLISHED_WITH_HOLES = plan({
  periodStatus: 'PUBLISHED',
  action: 'COMPLETE',
  reason: 'published',
  selectable: true,
  selectedByDefault: false,
  coveredRequiredDutyCount: 28,
  uncoveredRequiredDutyCount: 2,
  protectedAssignmentCount: 28,
})
const JUNIORS_NEW = plan({
  lineStableId: 'l2',
  lineName: 'Juniors',
  lineType: 'SECONDARY',
  periodStatus: 'DRAFT',
  generated: false,
  action: 'GENERATE',
  reason: null,
  selectable: true,
  selectedByDefault: true,
  requiredDutyCount: 30,
  coveredRequiredDutyCount: 0,
  uncoveredRequiredDutyCount: 30,
  protectedAssignmentCount: 0,
})
const ARCHIVED = plan({
  lineStableId: 'l3',
  lineName: 'Anciens',
  periodStatus: 'ARCHIVED',
  action: 'EXCLUDED',
  reason: 'archived',
  protectedAssignmentCount: 4,
})

const preview = (lines: FillLinePlan[]): FillPreview => ({
  lines,
  blockers: [],
  needsRestPolicy: lines.some((line) => line.action === 'GENERATE'),
})

const queued: PlanningJob = {
  stableId: 'job-1',
  kind: 'FILL',
  status: 'QUEUED',
  requestedBy: { firstName: 'Claire', lastName: 'Martin' },
  createdAt: '2027-01-01T10:00:00+00:00',
  startedAt: null,
  finishedAt: null,
  failureCode: null,
  outcome: null,
}

function renderModal(onlyLineStableId?: string) {
  const onLaunched = vi.fn()
  const onClose = vi.fn()
  render(
    <FillModal
      planningStableId="plan-1"
      timezone="Europe/Brussels"
      onlyLineStableId={onlyLineStableId}
      onClose={onClose}
      onLaunched={onLaunched}
    />,
  )
  return { onLaunched, onClose }
}

async function confirmButton(name: string) {
  const button = await screen.findByRole('button', { name })
  await waitFor(() => expect(button).toBeEnabled())
  return button
}

describe('FillModal — the preview', () => {
  it('shows each line, its protected assignments, and leaves a published line unticked', async () => {
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([SENIORS_PUBLISHED_WITH_HOLES, JUNIORS_NEW, ARCHIVED]),
    })
    renderModal()

    const lines = await screen.findByRole('list', { name: 'Lignes concernées' })
    const seniors = within(lines).getByRole('checkbox', { name: /Seniors/ })
    const juniors = within(lines).getByRole('checkbox', { name: /Juniors/ })
    const archived = within(lines).getByRole('checkbox', { name: /Anciens/ })
    expect(seniors).not.toBeChecked()
    expect(seniors).toBeEnabled()
    expect(juniors).toBeChecked()
    expect(archived).toBeDisabled()
    expect(within(lines).getByText(/rien n’est republié ni envoyé/)).toBeInTheDocument()
    expect(within(lines).getByText(/première génération de cette ligne seule/)).toBeInTheDocument()
    expect(within(lines).getByText('Archivée : jamais modifiée.')).toBeInTheDocument()
    expect(within(lines).getByText('28 gardes attribuées protégées')).toBeInTheDocument()
    expect(screen.getByText(/32 affectations existantes protégées/)).toBeInTheDocument()
    // A first generation is selected: its rest rules are asked for, never guessed.
    expect(screen.getByRole('group', { name: 'Règles de repos' })).toBeInTheDocument()
  })

  it('sends exactly the lines ticked, then hands the queued job to the page', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([SENIORS_PUBLISHED_WITH_HOLES, JUNIORS_NEW]),
      'POST /api/plannings/plan-1/fill': () => httpStatus(202, { job: queued }),
    })
    const { onLaunched, onClose } = renderModal()

    fireEvent.click(await screen.findByRole('checkbox', { name: /Seniors/ }))
    fireEvent.click(await confirmButton('Générer les postes non pourvus'))

    await waitFor(() => expect(onLaunched).toHaveBeenCalledWith(queued))
    expect(onClose).toHaveBeenCalled()
    const [post] = api.requests('POST', '/api/plannings/plan-1/fill')
    expect(post.body).toMatchObject({ lines: ['l1', 'l2'], legalMinRestEnabled: false })
  })

  it('without a first generation, sends no rest rules (a completion keeps its own)', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([
          {
            ...SENIORS_PUBLISHED_WITH_HOLES,
            periodStatus: 'GENERATED',
            reason: null,
            selectedByDefault: true,
          },
        ]),
      'POST /api/plannings/plan-1/fill': () => httpStatus(202, { job: queued }),
    })
    renderModal()

    fireEvent.click(await confirmButton('Générer les postes non pourvus'))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/fill')).toHaveLength(1))
    expect(screen.queryByRole('group', { name: 'Règles de repos' })).not.toBeInTheDocument()
    expect(api.requests('POST', '/api/plannings/plan-1/fill')[0].body).toEqual({ lines: ['l1'] })
  })

  it('"Générer cette ligne" offers that line only', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([SENIORS_PUBLISHED_WITH_HOLES, JUNIORS_NEW]),
      'POST /api/plannings/plan-1/fill': () => httpStatus(202, { job: queued }),
    })
    renderModal('l2')

    expect(await screen.findByRole('heading', { name: 'Générer cette ligne ?' })).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: /Seniors/ })).not.toBeInTheDocument()
    fireEvent.click(await confirmButton('Générer cette ligne'))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/fill')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/fill')[0].body).toMatchObject({ lines: ['l2'] })
  })

  it('nothing selectable: the button stays disabled', async () => {
    stubApi({ 'GET /api/plannings/plan-1/fill-preview': () => preview([plan({})]) })
    renderModal()

    expect(
      await screen.findByText('Toutes les gardes obligatoires sont attribuées : rien à faire.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Générer les postes non pourvus' })).toBeDisabled()
  })

  it('says in words when the server finds nothing to fill, or a computation already running', async () => {
    let answer = httpStatus(409, { error: 'nothing_to_fill' })
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([JUNIORS_NEW]),
      'POST /api/plannings/plan-1/fill': () => answer,
    })
    const { onLaunched } = renderModal()

    fireEvent.click(await confirmButton('Générer les postes non pourvus'))
    expect(await screen.findByText(/rien n’a été modifié/)).toBeInTheDocument()

    answer = httpStatus(409, { error: 'job_in_progress' })
    fireEvent.click(await confirmButton('Générer les postes non pourvus'))
    expect(await screen.findByText(/Un calcul est déjà en cours/)).toBeInTheDocument()
    expect(onLaunched).not.toHaveBeenCalled()
  })
})

describe('FillResultBody — the report', () => {
  it('shows, per line, kept, added, still missing and modified — the modified count first', () => {
    render(
      <FillResultBody
        outcome={{
          coverage: 'INCOMPLETE',
          modifiedExistingCount: 0,
          keptExistingCount: 30,
          addedCount: 27,
          lines: [
            {
              lineStableId: 'l1',
              lineName: 'Seniors',
              action: 'NOTHING',
              status: 'nothing_to_do',
              reason: null,
              keptCount: 30,
              modifiedCount: 0,
              addedCount: 0,
              remainingUncoveredCount: 0,
              blockers: [],
            },
            {
              lineStableId: 'l2',
              lineName: 'Juniors',
              action: 'GENERATE',
              status: 'done',
              reason: null,
              keptCount: 0,
              modifiedCount: 0,
              addedCount: 27,
              remainingUncoveredCount: 3,
              blockers: [],
            },
          ],
        }}
      />,
    )

    expect(screen.getByText('Affectations existantes modifiées : 0')).toBeInTheDocument()
    const juniors = screen.getByRole('row', { name: /Juniors/ })
    expect(
      within(juniors)
        .getAllByRole('cell')
        .map((cell) => cell.textContent),
    ).toEqual(['0', '27', '3', '0'])
    expect(within(juniors).getByText('Première génération effectuée.')).toBeInTheDocument()
  })
})

describe('PilotHeaderActions — "Générer les postes non pourvus"', () => {
  it('opens the preview from the header', async () => {
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([JUNIORS_NEW]),
      'GET /api/plannings/plan-1/generation-preflight': () => makePreflight(),
    })
    render(
      <PilotHeaderActions
        planningStableId="plan-1"
        timezone="Europe/Brussels"
        status={makeStatus()}
        onChanged={vi.fn()}
        onLaunched={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Générer les postes non pourvus' }))
    expect(
      await screen.findByRole('heading', { name: 'Générer les postes non pourvus ?' }),
    ).toBeInTheDocument()
  })

  it('"Générer le planning" warns that it replaces the calendar of a line that holds assignments', async () => {
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([plan({ lineStableId: 'line-1', lineName: 'Seniors' })]),
      'GET /api/plannings/plan-1/generation-preflight': () => {
        const base = makePreflight()
        return { ...base, lines: base.lines.map((line) => ({ ...line, decision: 'GENERATE' })) }
      },
    })
    render(
      <PilotHeaderActions
        planningStableId="plan-1"
        timezone="Europe/Brussels"
        status={makeStatus()}
        onChanged={vi.fn()}
        onLaunched={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    expect(await screen.findByText(/Régénérer remplace le calendrier de/)).toHaveTextContent(
      '« Seniors » (30 gardes attribuées)',
    )
  })
})
