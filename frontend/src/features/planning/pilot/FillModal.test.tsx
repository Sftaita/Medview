import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makePreflight } from '../../../testUtils/pilotFixtures'
import { status as httpStatus, stubApi } from '../../../testUtils/stubApi'
import { FillModal, FillResultBody } from './FillModal'
import { fillResultText } from './fillText'
import { PilotHeaderActions } from './PilotHeaderActions'
import type { FillLinePlan, FillPreview, PlanningJob } from './types'

// "Compléter la période N" / "Générer cette ligne" (docs/decisions.md D222, D228).

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

/** docs/decisions.md D226: a published line whose period was extended: only its new dates are completed. */
const SENIORS_EXTENDED = plan({
  periodStatus: 'PUBLISHED',
  action: 'COMPLETE',
  reason: 'extension_days',
  selectable: true,
  selectedByDefault: true,
  uncoveredRequiredDutyCount: 3,
  otherUncoveredCount: 2,
  extensionOnly: true,
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
      period={{ stableId: 'c2', number: 2, startsAt: '2027-01-31', lastDay: '2027-05-02', named: true }}
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
  it('shows each line and its protected assignments, with nothing to tick', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([SENIORS_PUBLISHED_WITH_HOLES, JUNIORS_NEW, ARCHIVED]),
    })
    renderModal()

    expect(await screen.findByRole('heading', { name: 'Compléter la période 2 ?' })).toBeInTheDocument()
    expect(api.requests('GET', '/api/plannings/plan-1/fill-preview')[0].query.get('period')).toBe('c2')
    const lines = screen.getByRole('list', { name: 'Lignes concernées' })
    // The whole period is one action: no line to choose.
    expect(within(lines).queryByRole('checkbox')).not.toBeInTheDocument()
    const line = (name: string) => within(lines).getByText(name).closest('li')
    expect(line('Seniors')).toHaveTextContent('— inchangée')
    expect(line('Juniors')).not.toHaveTextContent('inchangée')
    expect(
      within(lines).getByText(
        'Publiée : conservée telle quelle (2 gardes non pourvues, à attribuer à la main). Les autres lignes sont traitées normalement.',
      ),
    ).toBeInTheDocument()
    expect(
      within(lines).getByText(
        'Jamais générée : ses gardes seront créées puis attribuées (30 gardes à pourvoir).',
      ),
    ).toBeInTheDocument()
    expect(within(lines).getByText('Archivée : jamais modifiée.')).toBeInTheDocument()
    expect(within(lines).getByText('28 gardes attribuées protégées')).toBeInTheDocument()
    expect(screen.getByText(/32 affectations existantes protégées/)).toBeInTheDocument()
    expect(screen.getByText(/Aucun e-mail n’est envoyé/)).toBeInTheDocument()
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
    expect(screen.getByRole('button', { name: 'Compléter la période 2' })).toBeDisabled()

    answer(preview([SENIORS_DRAFT_WITH_HOLES]))
    await waitFor(() => expect(screen.queryByText('Analyse des lignes en cours…')).not.toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Compléter la période 2' })).toBeEnabled()
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

  it('sends every line that can receive something — never a published one — then hands the queued job to the page', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([
          SENIORS_DRAFT_WITH_HOLES,
          { ...JUNIORS_NEW, lineStableId: 'l4', action: 'COMPLETE', lineName: 'Juniors' },
          { ...SENIORS_PUBLISHED_WITH_HOLES, lineStableId: 'l9', lineName: 'Publiée' },
        ]),
      'POST /api/plannings/plan-1/fill': () => httpStatus(202, { job: queued }),
    })
    const { onLaunched, onClose } = renderModal()

    fireEvent.click(await confirmButton('Compléter la période 2'))

    await waitFor(() => expect(onLaunched).toHaveBeenCalledWith(queued))
    expect(onClose).toHaveBeenCalled()
    const [post] = api.requests('POST', '/api/plannings/plan-1/fill')
    // Only completions: no rest rule is sent (a completion keeps its generation's own).
    expect(post.body).toEqual({ lines: ['l1', 'l4'] })
    expect(post.query.get('period')).toBe('c2')
  })

  it('sends a published line for the new dates of an extension only, and says so; never one merely excluded', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([
          SENIORS_EXTENDED,
          { ...SENIORS_PUBLISHED_WITH_HOLES, lineStableId: 'l9', lineName: 'Ancienne publiée' },
        ]),
      'POST /api/plannings/plan-1/fill': () => httpStatus(202, { job: queued }),
    })
    const { onLaunched } = renderModal()

    expect(
      await screen.findByText(
        'Publiée : 3 gardes des nouvelles dates à attribuer. Les gardes déjà publiées ne changent pas, et 2 anciens postes vacants restent à attribuer à la main. À republier ensuite.',
      ),
    ).toBeInTheDocument()
    fireEvent.click(await confirmButton('Compléter la période 2'))

    await waitFor(() => expect(onLaunched).toHaveBeenCalledWith(queued))
    expect(api.requests('POST', '/api/plannings/plan-1/fill')[0].body).toEqual({ lines: ['l1'] })
  })

  it('with a first generation, sends the rest rules as chosen — unticked by default', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([JUNIORS_NEW]),
      'POST /api/plannings/plan-1/fill': () => httpStatus(202, { job: queued }),
    })
    renderModal()

    fireEvent.click(await confirmButton('Compléter la période 2'))

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
    const confirm = await confirmButton('Générer cette ligne')
    expect(screen.queryByText('Seniors')).not.toBeInTheDocument()
    expect(screen.getByText('Juniors')).toBeInTheDocument()
    fireEvent.click(confirm)

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/fill')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/fill')[0].body).toMatchObject({ lines: ['l2'] })
  })

  it('counts only the posts that can be completed; the impossible ones apart, never "à compléter" (D234)', async () => {
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([
          plan({
            lineStableId: 'l2',
            lineName: 'Fell/Ass',
            action: 'COMPLETE',
            selectable: true,
            selectedByDefault: true,
            uncoveredRequiredDutyCount: 21,
            unfillable: {
              dutyCount: 19,
              noMemberOnDateCount: 19,
              noEligibleMemberCount: 0,
              firstMembershipStart: '2026-11-01',
              from: '2026-10-02',
              to: '2026-10-29',
            },
          }),
        ]),
    })
    renderModal()

    expect(await screen.findByText('2 gardes non pourvues à compléter')).toBeInTheDocument()
    expect(
      screen.getByText(
        /19 gardes sans membre éligible : aucune attribution possible, elles resteront vacantes/,
      ),
    ).toBeInTheDocument()
    expect(await confirmButton('Compléter la période 2')).toBeEnabled()
  })

  it('only impossible posts: nothing to send, the cause said (D234)', async () => {
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () =>
        preview([
          plan({
            lineStableId: 'l2',
            lineName: 'Fell/Ass',
            action: 'NOTHING',
            reason: 'no_eligible_member',
            uncoveredRequiredDutyCount: 19,
            unfillable: {
              dutyCount: 19,
              noMemberOnDateCount: 19,
              noEligibleMemberCount: 0,
              firstMembershipStart: '2026-11-01',
              from: '2026-10-02',
              to: '2026-10-29',
            },
          }),
        ]),
    })
    renderModal()

    expect(await screen.findByText(/n’est éligible pour 19 gardes/)).toBeInTheDocument()
    expect(screen.queryByText(/à compléter/)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Compléter la période 2' })).toBeDisabled()
  })

  it('nothing to send (only a published line): the button stays disabled', async () => {
    stubApi({ 'GET /api/plannings/plan-1/fill-preview': () => preview([SENIORS_PUBLISHED_WITH_HOLES]) })
    renderModal()

    expect(await screen.findByText(/Publiée : conservée telle quelle/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Compléter la période 2' })).toBeDisabled()
  })

  it('says in words when the server finds nothing to fill, or a computation already running', async () => {
    let answer = httpStatus(409, { error: 'nothing_to_fill' })
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([JUNIORS_NEW]),
      'POST /api/plannings/plan-1/fill': () => answer,
    })
    const { onLaunched } = renderModal()

    fireEvent.click(await confirmButton('Compléter la période 2'))
    expect(await screen.findByText(/rien n’a été modifié/)).toBeInTheDocument()

    answer = httpStatus(409, { error: 'job_in_progress' })
    fireEvent.click(await confirmButton('Compléter la période 2'))
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
    // On a phone each count keeps its label (cards, never a hidden column).
    expect(
      within(juniors)
        .getAllByRole('cell')
        .map((cell) => cell.dataset.label),
    ).toEqual([
      'Gardes existantes conservées',
      'Nouvelles gardes attribuées',
      'Gardes toujours non pourvues',
      'Affectations existantes modifiées',
    ])
  })

  it('never says "complétés" when a completion could give nobody (recette at 390 px)', () => {
    const completion = (addedCount: number, remainingUncoveredCount: number) => ({
      lineStableId: 'l3',
      lineName: 'Renfort',
      action: 'COMPLETE' as const,
      status: 'done' as const,
      reason: null,
      keptCount: 19,
      modifiedCount: 0,
      addedCount,
      remainingUncoveredCount,
      blockers: [],
    })
    expect(fillResultText(completion(0, 2))).toBe('Personne n’a pu être attribué : postes vacants inchangés.')
    expect(fillResultText(completion(1, 1))).toBe('Postes vacants complétés en partie.')
    expect(fillResultText(completion(2, 0))).toBe('Postes vacants complétés.')
  })
})

function renderHeader(hasGeneratedLines: boolean) {
  const onLaunched = vi.fn()
  render(
    <PilotHeaderActions
      planningStableId="plan-1"
      timezone="Europe/Brussels"
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
  it('nothing generated yet: generating is the main action, and nothing can be replaced', () => {
    stubApi({})
    renderHeader(false)

    expect(screen.getByRole('button', { name: 'Générer la période 1' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Compléter|postes non pourvus/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Plus d’actions' })).not.toBeInTheDocument()
  })

  it('a line generated: never "Générer" any more — the main action protects, regenerating is apart', async () => {
    stubApi({ 'GET /api/plannings/plan-1/fill-preview': () => preview([JUNIORS_NEW]) })
    renderHeader(true)

    expect(screen.queryByRole('button', { name: /^Générer/ })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Compléter la période 1' }))
    expect(await screen.findByRole('heading', { name: 'Compléter la période ?' })).toBeInTheDocument()
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

  it('"Générer la période" never sends the confirmation, and explains a refusal on a generated line', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => preview([]),
      'GET /api/plannings/plan-1/generation-preflight': () => ({
        ...makePreflight(),
        canGenerate: false,
        blockers: [{ code: 'REGENERATION_NOT_CONFIRMED', lineStableId: 'line-1', lineName: 'Seniors' }],
      }),
    })
    renderHeader(false)

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    expect(
      await screen.findByText(
        /déjà générée : ses gardes attribuées ne sont jamais remplacées par défaut\. Utilisez « Compléter la période »/,
      ),
    ).toBeInTheDocument()
    expect(
      api.requests('GET', '/api/plannings/plan-1/generation-preflight')[0].query.get('replaceExisting'),
    ).toBeNull()
    expect(api.requests('POST', '/api/plannings/plan-1/generations')).toHaveLength(0)
  })
})
