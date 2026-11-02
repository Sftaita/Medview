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
  action: 'EXCLUDED',
  reason: 'published',
  selectable: false,
  selectedByDefault: false,
  coveredRequiredDutyCount: 28,
  uncoveredRequiredDutyCount: 2,
  protectedAssignmentCount: 28,
})
const SENIORS_DRAFT_WITH_HOLES = plan({
  action: 'COMPLETE',
  reason: null,
  selectable: true,
  selectedByDefault: true,
  coveredRequiredDutyCount: 28,
  uncoveredRequiredDutyCount: 2,
  protectedAssignmentCount: 28,
  lateMemberCount: 1,
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
  it('shows each line and its protected assignments; a published line can never be ticked', async () => {
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([SENIORS_PUBLISHED_WITH_HOLES, JUNIORS_NEW, ARCHIVED]),
    })
    renderModal()

    const lines = await screen.findByRole('list', { name: 'Lignes concernées' })
    const seniors = within(lines).getByRole('checkbox', { name: /Seniors/ })
    expect(seniors).not.toBeChecked()
    expect(seniors).toBeDisabled()
    expect(within(lines).getByRole('checkbox', { name: /Juniors/ })).toBeChecked()
    expect(within(lines).getByRole('checkbox', { name: /Anciens/ })).toBeDisabled()
    expect(
      within(lines).getByText(
        'Publiée : jamais complétée automatiquement (2 gardes non pourvues, à attribuer à la main). Elle n’empêche pas de générer les autres lignes.',
      ),
    ).toBeInTheDocument()
    expect(within(lines).getByText(/première génération de cette ligne seule/)).toBeInTheDocument()
    expect(within(lines).getByText('Archivée : jamais modifiée.')).toBeInTheDocument()
    expect(within(lines).getByText('28 gardes attribuées protégées')).toBeInTheDocument()
    expect(screen.getByText(/32 affectations existantes protégées/)).toBeInTheDocument()
    // A first generation is selected: its rest rules are asked for — both unticked, never guessed.
    const rest = screen.getByRole('group', { name: 'Règles de repos' })
    for (const box of within(rest).getAllByRole('checkbox')) expect(box).not.toBeChecked()
  })

  it('shows a clear, announced wait while the preview is computed — nothing can be launched meanwhile', async () => {
    let answer: (value: unknown) => void = () => {}
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        new Promise((resolve) => {
          answer = resolve
        }),
    })
    renderModal()

    const wait = await screen.findByRole('status')
    expect(wait).toHaveTextContent('Analyse des lignes en cours…')
    expect(wait).toHaveTextContent('jusqu’à une vingtaine de secondes')
    expect(screen.getByRole('button', { name: 'Générer les postes non pourvus' })).toBeDisabled()

    answer(preview([SENIORS_DRAFT_WITH_HOLES]))
    await waitFor(() => expect(screen.queryByText('Analyse des lignes en cours…')).not.toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Générer les postes non pourvus' })).toBeEnabled()
  })

  it('says when members who joined after the generation are taken into account', async () => {
    stubApi({ 'GET /api/plannings/plan-1/fill-preview': () => preview([SENIORS_DRAFT_WITH_HOLES]) })
    renderModal()

    expect(
      await screen.findByText(
        '2 gardes non pourvues à compléter, sans toucher aux gardes attribuées. 1 membre arrivé depuis la génération est pris en compte.',
      ),
    ).toBeInTheDocument()
  })

  it('sends exactly the lines ticked — never a published one — then hands the queued job to the page', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([
          SENIORS_DRAFT_WITH_HOLES,
          JUNIORS_NEW,
          { ...SENIORS_PUBLISHED_WITH_HOLES, lineStableId: 'l9', lineName: 'Publiée' },
        ]),
      'POST /api/plannings/plan-1/fill': () => httpStatus(202, { job: queued }),
    })
    const { onLaunched, onClose } = renderModal()

    fireEvent.click(await screen.findByRole('checkbox', { name: /Juniors/ }))
    fireEvent.click(await confirmButton('Générer les postes non pourvus'))

    await waitFor(() => expect(onLaunched).toHaveBeenCalledWith(queued))
    expect(onClose).toHaveBeenCalled()
    const [post] = api.requests('POST', '/api/plannings/plan-1/fill')
    // Only a completion: no rest rule is sent (a completion keeps its generation's own).
    expect(post.body).toEqual({ lines: ['l1'] })
  })

  it('with a first generation, sends the rest rules as chosen — unticked by default', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([JUNIORS_NEW]),
      'POST /api/plannings/plan-1/fill': () => httpStatus(202, { job: queued }),
    })
    renderModal()

    fireEvent.click(await confirmButton('Générer les postes non pourvus'))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/fill')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/fill')[0].body).toMatchObject({
      lines: ['l2'],
      legalMinRestEnabled: false,
      teamMinRestEnabled: false,
    })
  })

  it('"Générer cette ligne" offers that line only', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([SENIORS_DRAFT_WITH_HOLES, JUNIORS_NEW]),
      'POST /api/plannings/plan-1/fill': () => httpStatus(202, { job: queued }),
    })
    renderModal('l2')

    expect(await screen.findByRole('heading', { name: 'Générer cette ligne ?' })).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: /Seniors/ })).not.toBeInTheDocument()
    fireEvent.click(await confirmButton('Générer cette ligne'))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/fill')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/fill')[0].body).toMatchObject({ lines: ['l2'] })
  })

  it('nothing selectable (only a published line): the button stays disabled', async () => {
    stubApi({ 'GET /api/plannings/plan-1/fill-preview': () => preview([SENIORS_PUBLISHED_WITH_HOLES]) })
    renderModal()

    expect(await screen.findByText(/Publiée : jamais complétée automatiquement/)).toBeInTheDocument()
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

function renderHeader(hasGeneratedLines: boolean) {
  const onLaunched = vi.fn()
  render(
    <PilotHeaderActions
      planningStableId="plan-1"
      timezone="Europe/Brussels"
      status={makeStatus()}
      onChanged={vi.fn()}
      onLaunched={onLaunched}
      hasGeneratedLines={hasGeneratedLines}
    />,
  )
  return onLaunched
}

const generatedPreflight = () => {
  const base = makePreflight()
  return { ...base, lines: base.lines.map((line) => ({ ...line, decision: 'GENERATE' })) }
}

describe('PilotHeaderActions — protecting what is assigned (docs/decisions.md D222)', () => {
  it('nothing generated yet: "Générer le planning" stays the main action', () => {
    stubApi({})
    renderHeader(false)

    expect(screen.getByRole('button', { name: 'Générer le planning' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Générer les postes non pourvus' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    expect(screen.queryByRole('menuitem', { name: 'Régénérer entièrement…' })).not.toBeInTheDocument()
  })

  it('a line generated: no "Générer le planning" any more — the main action protects, regenerating is apart', async () => {
    stubApi({ 'GET /api/plannings/plan-1/fill-preview': () => preview([JUNIORS_NEW]) })
    renderHeader(true)

    expect(screen.queryByRole('button', { name: 'Générer le planning' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Générer les postes non pourvus' }))
    expect(
      await screen.findByRole('heading', { name: 'Générer les postes non pourvus ?' }),
    ).toBeInTheDocument()
  })

  it('"Régénérer entièrement…" requires ticking an explicit confirmation, and only then sends it', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([plan({ lineStableId: 'line-1', lineName: 'Seniors' })]),
      'GET /api/plannings/plan-1/generation-preflight': () => generatedPreflight(),
      'POST /api/plannings/plan-1/generations': () =>
        httpStatus(202, { job: { ...queued, kind: 'GENERATE' } }),
    })
    const onLaunched = renderHeader(true)

    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    fireEvent.click(screen.getByRole('menuitem', { name: 'Régénérer entièrement…' }))

    expect(await screen.findByRole('heading', { name: 'Régénérer entièrement ?' })).toBeInTheDocument()
    const warning = await screen.findByText(/Action destructrice/)
    await waitFor(() => expect(warning.closest('p')).toHaveTextContent('« Seniors » (30 gardes attribuées)'))
    await waitFor(() =>
      expect(api.requests('GET', '/api/plannings/plan-1/generation-preflight')).toHaveLength(1),
    )
    expect(
      api.requests('GET', '/api/plannings/plan-1/generation-preflight')[0].query.get('replaceExisting'),
    ).toBe('1')
    const confirm = screen.getByRole('button', { name: 'Régénérer et remplacer' })
    expect(confirm).toBeDisabled()

    fireEvent.click(screen.getByRole('checkbox', { name: /Je confirme remplacer 30 gardes attribuées/ }))
    await waitFor(() => expect(confirm).toBeEnabled())
    fireEvent.click(confirm)

    await waitFor(() => expect(onLaunched).toHaveBeenCalled())
    expect(api.requests('POST', '/api/plannings/plan-1/generations')[0].body).toMatchObject({
      replaceExistingAssignments: true,
    })
  })

  it('"Générer le planning" never sends the confirmation, and explains a refusal on a generated line', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([]),
      'GET /api/plannings/plan-1/generation-preflight': () => ({
        ...makePreflight(),
        canGenerate: false,
        blockers: [{ code: 'REGENERATION_NOT_CONFIRMED', lineStableId: 'line-1', lineName: 'Seniors' }],
      }),
    })
    renderHeader(false)

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))

    expect(
      await screen.findByText(
        /déjà générée : « Générer le planning » ne remplace jamais des gardes attribuées/,
      ),
    ).toBeInTheDocument()
    expect(
      api.requests('GET', '/api/plannings/plan-1/generation-preflight')[0].query.get('replaceExisting'),
    ).toBeNull()
    expect(api.requests('POST', '/api/plannings/plan-1/generations')).toHaveLength(0)
  })
})
