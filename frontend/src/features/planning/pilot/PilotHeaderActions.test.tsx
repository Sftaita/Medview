import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makePreflight, makeStatus } from '../../../testUtils/pilotFixtures'
import { status as httpStatus, stubApi } from '../../../testUtils/stubApi'
import { PilotHeaderActions } from './PilotHeaderActions'
import { ResultBody } from './GenerationModal'
import type { CollectionStatus, LaunchResult, PlanningJob } from './types'

beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function renderActions(
  status: CollectionStatus | null = makeStatus(),
  handlers = { onChanged: vi.fn(), onLaunched: vi.fn() },
  lineProps: { primaryLineStableId?: string; primaryLineName?: string } = {},
) {
  render(
    <PilotHeaderActions
      planningStableId="plan-1"
      timezone="Europe/Brussels"
      status={status}
      onChanged={handlers.onChanged}
      onLaunched={handlers.onLaunched}
      {...lineProps}
    />,
  )
  return handlers
}

/**
 * The confirm button is rendered — disabled — while the preflight loads: find it,
 * then wait until it is enabled. Clicking it at once raced the preflight and was
 * silently ignored on slower runners (the CI), leaving the test waiting forever.
 */
async function enabledConfirm(scope: Pick<typeof screen, 'findByRole'> = screen) {
  const button = await scope.findByRole('button', { name: 'Générer quand même' })
  await waitFor(() => expect(button).toBeEnabled())
  return button
}

/** Opens the header's "⋯" menu and picks an entry. */
function chooseMenu(name: string) {
  fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
  fireEvent.click(screen.getByRole('menuitem', { name }))
}

describe('PilotHeaderActions — buttons and menu', () => {
  it('offers Générer le planning, and Paramètres in the menu', () => {
    renderActions(makeStatus({ availabilityDeadline: '2026-09-25' }))

    expect(screen.getByRole('button', { name: 'Générer le planning' })).toBeEnabled()
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    expect(screen.getByRole('menuitem', { name: 'Paramètres' })).toBeEnabled()
    // The deadline is shown by the availability follow-up, not by the header.
    expect(screen.queryByText(/Fin souhaitée/)).not.toBeInTheDocument()
  })

  it('keeps everything enabled once the deadline is passed', () => {
    renderActions(makeStatus({ availabilityDeadline: '2026-09-25', deadlineOverdueDays: 3 }))

    expect(screen.getByRole('button', { name: 'Générer le planning' })).toBeEnabled()
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    expect(screen.getByRole('menuitem', { name: 'Paramètres' })).toBeEnabled()
  })

  it('offers the generation even before the pilot data has loaded', () => {
    renderActions(null)

    expect(screen.getByRole('button', { name: 'Générer le planning' })).toBeEnabled()
  })

  it('lists Modifier le nom only when the page allows renaming, and closes the menu on Escape', () => {
    const onRename = vi.fn()
    render(
      <PilotHeaderActions
        planningStableId="plan-1"
        timezone="Europe/Brussels"
        status={makeStatus()}
        onRename={onRename}
        onChanged={vi.fn()}
        onLaunched={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    fireEvent.keyDown(window, { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    chooseMenu('Modifier le nom')
    expect(onRename).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('has no Modifier le nom entry without onRename', () => {
    renderActions()
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    expect(screen.queryByRole('menuitem', { name: 'Modifier le nom' })).not.toBeInTheDocument()
  })

  it('opens the generation dialog when the page asks for it (controlled)', async () => {
    stubApi({ 'GET /api/plannings/plan-1/generation-preflight': () => makePreflight() })
    const onDialogChange = vi.fn()
    render(
      <PilotHeaderActions
        planningStableId="plan-1"
        timezone="Europe/Brussels"
        status={makeStatus()}
        onChanged={vi.fn()}
        onLaunched={vi.fn()}
        dialog="generate"
        onDialogChange={onDialogChange}
      />,
    )

    expect(await screen.findByRole('dialog')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))
    expect(onDialogChange).toHaveBeenCalledWith(null)
  })
})

describe('Semaine type (docs/decisions.md D136)', () => {
  it('is not offered before the primary line is known', () => {
    renderActions()
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    expect(screen.queryByRole('menuitem', { name: 'Semaine type' })).not.toBeInTheDocument()
  })

  it('opens, loads the current structure and saves it', async () => {
    const api = stubApi({
      'GET /api/planning-lines/line-1/week-structure': () => ({
        blocks: [{ id: 'A', name: 'Week-end', family: 'Week-end', days: ['VEN', 'SAM', 'DIM'] }],
        solo: ['LUN', 'MAR', 'MER', 'JEU'],
        soloFamily: 'Semaine',
        excluded: [],
      }),
      'PUT /api/planning-lines/line-1/week-structure': () => ({
        blocks: [],
        solo: ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
        soloFamily: 'Semaine',
        excluded: [],
      }),
    })
    const { onChanged } = renderActions(makeStatus(), undefined, {
      primaryLineStableId: 'line-1',
      primaryLineName: 'Ligne principale',
    })

    chooseMenu('Semaine type')
    const dialog = await screen.findByRole('dialog', { name: 'Semaine type — Ligne principale' })
    expect(await within(dialog).findByText('Ven · Sam · Dim · 3 jours')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Dissoudre' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1))
    expect(api.requests('PUT', '/api/planning-lines/line-1/week-structure')).toHaveLength(1)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})

describe('PlanningSettingsModal', () => {
  it('says the deadline is indicative and blocks nothing', () => {
    renderActions()

    chooseMenu('Paramètres')

    const dialog = screen.getByRole('dialog', { name: 'Paramètres du planning' })
    expect(within(dialog).getByLabelText('Fin souhaitée d’encodage des indisponibilités')).toBeInTheDocument()
    expect(within(dialog).getByText(/ne bloque jamais rien/)).toBeInTheDocument()
    expect(
      within(dialog).getByText(/chacun peut encore ajouter ou modifier ses indisponibilités/),
    ).toBeInTheDocument()
    expect(within(dialog).getByText(/la génération du planning reste possible/)).toBeInTheDocument()
  })

  it('changes the deadline, then closes and tells the page', async () => {
    const api = stubApi({
      'PATCH /api/plannings/plan-1/settings': () => ({
        availabilityDeadline: '2026-10-05',
        deadlineOverdueDays: null,
        openCollectionCount: 1,
      }),
    })
    const { onChanged } = renderActions(makeStatus({ availabilityDeadline: '2026-09-25' }))

    chooseMenu('Paramètres')
    const dialog = screen.getByRole('dialog')
    const input = within(dialog).getByLabelText('Fin souhaitée d’encodage des indisponibilités')
    expect(input).toHaveValue('2026-09-25')
    // Nothing to save until it changes.
    expect(within(dialog).getByRole('button', { name: 'Enregistrer' })).toBeDisabled()

    fireEvent.change(input, { target: { value: '2026-10-05' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(api.requests('PATCH', '/api/plannings/plan-1/settings')[0].body).toEqual({
      availabilityDeadline: '2026-10-05',
    })
    expect(onChanged).toHaveBeenCalledTimes(1)
  })

  it('can set a first deadline and clear an existing one', async () => {
    const api = stubApi({
      'PATCH /api/plannings/plan-1/settings': () => ({
        availabilityDeadline: null,
        deadlineOverdueDays: null,
        openCollectionCount: 1,
      }),
    })
    renderActions(makeStatus({ availabilityDeadline: '2026-09-25' }))

    chooseMenu('Paramètres')
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Effacer la date' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(api.requests('PATCH', '/api/plannings/plan-1/settings')[0].body).toEqual({
      availabilityDeadline: null,
    })
  })

  it('explains a refused date and stays open', async () => {
    stubApi({ 'PATCH /api/plannings/plan-1/settings': () => httpStatus(422, { error: 'validation_failed' }) })
    renderActions(makeStatus())

    chooseMenu('Paramètres')
    const dialog = screen.getByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Fin souhaitée d’encodage des indisponibilités'), {
      target: { value: '2020-01-01' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/déjà passée/)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})

const QUEUED: { job: PlanningJob } = {
  job: {
    stableId: 'job-1',
    kind: 'GENERATE',
    status: 'QUEUED',
    requestedBy: { firstName: 'Camille', lastName: 'Dupont' },
    createdAt: '2026-09-27T10:00:00+00:00',
    startedAt: null,
    finishedAt: null,
    failureCode: null,
    outcome: null,
  },
}

const SUCCESS: LaunchResult = {
  lines: [
    {
      lineStableId: 'line-1',
      lineName: 'Seniors',
      generationStableId: 'gen-1',
      status: 'COMPLETED',
      error: null,
      coverageStatus: 'COMPLETE',
      strictSolverStatus: 'OPTIMAL',
      partialSolverStatus: null,
      assignmentCount: 28,
      unassignedDutyCount: 0,
      optimality: { GENERATE: true },
      diagnostics: null,
      snapshot: { capturedAt: '2026-09-21T08:14:00+00:00', memberCount: 16, unavailableCount: 42 },
      demand: null,
    },
  ],
}

function renderResult(result: LaunchResult) {
  render(<ResultBody result={result} timezone="Europe/Brussels" />)
}

describe('GenerationModal', () => {
  const preflightUrl = 'GET /api/plannings/plan-1/generation-preflight'
  const launchUrl = 'POST /api/plannings/plan-1/generations'

  it('shows the preflight: period, participants, who confirmed, who did not, unavailabilities', async () => {
    stubApi({ [preflightUrl]: () => makePreflight() })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))

    const dialog = await screen.findByRole('dialog', { name: 'Générer le planning ?' })
    await within(dialog).findByText('16 membres participent')
    expect(within(dialog).getByText('1 janvier 2027 → 31 mars 2027')).toBeInTheDocument()
    expect(within(dialog).getByText('13 ont confirmé leurs disponibilités')).toBeInTheDocument()
    expect(within(dialog).getByText('3 n’ont pas encore répondu')).toBeInTheDocument()
    expect(within(dialog).getByText('42 indisponibilités sont enregistrées')).toBeInTheDocument()
    expect(
      within(dialog).getByText('Cette génération utilisera l’état actuel des données.'),
    ).toBeInTheDocument()
  })

  it('warns about pending members but still lets the admin generate anyway', async () => {
    stubApi({ [preflightUrl]: () => makePreflight() })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))

    const dialog = await screen.findByRole('dialog')
    const warnings = await within(dialog).findByRole('list', { name: 'Avertissements' })
    expect(
      within(warnings).getByText(/3 membres n’ont pas confirmé leurs disponibilités/),
    ).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Générer quand même' })).toBeEnabled()
    expect(within(dialog).getByRole('button', { name: 'Annuler' })).toBeEnabled()
  })

  it('uses a simpler wording when everybody confirmed and nothing warrants a warning', async () => {
    stubApi({
      [preflightUrl]: () => makePreflight({ confirmedCount: 16, pendingCount: 0, warnings: [] }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('Tous ont confirmé leurs disponibilités')
    expect(within(dialog).getByRole('button', { name: 'Générer' })).toBeEnabled()
    expect(within(dialog).queryByRole('list', { name: 'Avertissements' })).not.toBeInTheDocument()
  })

  it('a passed deadline is one more warning, never a blocker', async () => {
    stubApi({
      [preflightUrl]: () =>
        makePreflight({
          availabilityDeadline: '2026-09-25',
          deadlineOverdueDays: 2,
          warnings: [
            { code: 'PENDING_MEMBERS', lineStableId: null, lineName: null },
            { code: 'DEADLINE_PASSED', lineStableId: null, lineName: null },
          ],
        }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('Fin souhaitée d’encodage : 25 septembre 2026')
    expect(
      within(dialog).getByText(/Date souhaitée dépassée depuis 2 jours\. Elle reste indicative/),
    ).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Générer quand même' })).toBeEnabled()
  })

  it('requests the generation once even on a double click, then hands the queued job over to the page (docs/decisions.md D149)', async () => {
    let release: () => void = () => {}
    const held = new Promise<void>((resolve) => (release = resolve))
    const api = stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: async () => {
        await held
        return httpStatus(202, QUEUED)
      },
    })
    const { onLaunched } = renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    const dialog = await screen.findByRole('dialog')
    const confirm = await enabledConfirm(within(dialog))
    fireEvent.click(confirm)
    fireEvent.click(confirm)

    expect(await within(dialog).findByRole('button', { name: 'Envoi…' })).toBeDisabled()
    // Nothing can dismiss the dialog while the request is being sent.
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.getByRole('dialog')).toBeInTheDocument()

    release()
    // The server answered at once with a queued job: the dialog closes, the page follows the job.
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(api.requests('POST', '/api/plannings/plan-1/generations')).toHaveLength(1)
    expect(onLaunched).toHaveBeenCalledTimes(1)
    expect(onLaunched).toHaveBeenCalledWith(QUEUED.job)
  })

  it('is not offered once the planning is published, and is disabled while a job runs', () => {
    render(
      <PilotHeaderActions
        planningStableId="plan-1"
        timezone="Europe/Brussels"
        status={makeStatus()}
        onChanged={vi.fn()}
        onLaunched={vi.fn()}
        canLaunchGeneration={false}
      />,
    )
    expect(screen.queryByRole('button', { name: 'Générer le planning' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Plus d’actions' })).toBeInTheDocument()
    cleanup()

    render(
      <PilotHeaderActions
        planningStableId="plan-1"
        timezone="Europe/Brussels"
        status={makeStatus()}
        onChanged={vi.fn()}
        onLaunched={vi.fn()}
        busy
      />,
    )
    expect(screen.getByRole('button', { name: 'Générer le planning' })).toBeDisabled()
  })

  it('reports an incomplete coverage honestly', async () => {
    renderResult({
      lines: [
        { ...SUCCESS.lines[0], coverageStatus: 'INCOMPLETE', assignmentCount: 25, unassignedDutyCount: 3 },
      ],
    })

    expect(
      await screen.findByText(/couverture incomplète, 25 gardes affectées, 3 gardes non pourvues/),
    ).toBeInTheDocument()
  })

  it('cancels without generating anything', async () => {
    const api = stubApi({ [preflightUrl]: () => makePreflight() })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Annuler' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.requests('POST', '/api/plannings/plan-1/generations')).toHaveLength(0)
  })

  it('blocks — and says why — only for a technical prerequisite', async () => {
    stubApi({
      [preflightUrl]: () =>
        makePreflight({
          canGenerate: false,
          blockers: [{ code: 'NO_ACTIVE_RULE_SET', lineStableId: 'line-1', lineName: 'Seniors' }],
        }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))

    const dialog = await screen.findByRole('dialog')
    const blockers = await within(dialog).findByRole('list', { name: 'Points bloquants' })
    expect(
      within(blockers).getByText('Ligne « Seniors » : aucune règle de planning n’est active.'),
    ).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Générer quand même' })).toBeDisabled()
  })

  it('explains the conditional-line states in words, never by their raw code (docs/decisions.md D163)', async () => {
    stubApi({
      [preflightUrl]: () =>
        makePreflight({
          canGenerate: false,
          blockers: [{ code: 'AMBIGUOUS_COVERAGE_SOURCE', lineStableId: 'line-2', lineName: 'Renfort' }],
          warnings: [{ code: 'COVERAGE_SOURCE_MISSING', lineStableId: 'line-2', lineName: 'Renfort' }],
        }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))

    const dialog = await screen.findByRole('dialog')
    const blockers = await within(dialog).findByRole('list', { name: 'Points bloquants' })
    expect(within(blockers).getByText(/plusieurs gardes de la ligne source/)).toBeInTheDocument()
    expect(
      within(dialog).getByText(/aucune garde sur la ligne source : aucun renfort n’y est possible/),
    ).toBeInTheDocument()
    expect(within(dialog).queryByText(/COVERAGE_SOURCE/)).not.toBeInTheDocument()
  })

  it('tells when another generation is already running', async () => {
    stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () => httpStatus(409, { error: 'job_in_progress' }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    fireEvent.click(await enabledConfirm())

    expect(await screen.findByRole('alert')).toHaveTextContent(/déjà en cours/)
    expect(screen.getByRole('button', { name: 'Générer quand même' })).toBeEnabled()
  })

  it('reports a preflight that could not load', async () => {
    stubApi({ [preflightUrl]: () => httpStatus(500, {}) })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/Impossible de contrôler/)
  })

  it('shows real family unit counts, never hardcoded names (docs/decisions.md D137)', async () => {
    stubApi({ [preflightUrl]: () => makePreflight() })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))

    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('20 gardes à répartir — Sans famille')).toBeInTheDocument()
    expect(within(dialog).getByText('10 gardes à répartir — Week-end')).toBeInTheDocument()
  })

  it('sends the chosen rest policy with the launch, disabled by default', async () => {
    const api = stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () => httpStatus(202, QUEUED),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(await enabledConfirm(within(dialog)))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/generations')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/generations')[0].body).toEqual({
      legalMinRestEnabled: false,
      legalMinRestHours: null,
      teamMinRestEnabled: false,
      teamMinRestHours: null,
    })
  })

  it('enables team min rest with a chosen number of hours', async () => {
    const api = stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () => httpStatus(202, QUEUED),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(await within(dialog).findByRole('checkbox', { name: /Repos minimum d’équipe/ }))
    fireEvent.change(within(dialog).getByLabelText('Repos minimum d’équipe, en heures'), {
      target: { value: '36' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Générer quand même' }))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/generations')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/generations')[0].body).toEqual({
      legalMinRestEnabled: false,
      legalMinRestHours: null,
      teamMinRestEnabled: true,
      teamMinRestHours: 36,
    })
  })

  it('never calls a FEASIBLE-but-complete result "optimal"', async () => {
    renderResult({ lines: [{ ...SUCCESS.lines[0], strictSolverStatus: 'FEASIBLE' }] })

    expect(await screen.findByText(/L’optimalité mathématique n’a pas pu être démontrée/)).toBeInTheDocument()
    expect(screen.queryByText(/prouvé optimal/)).not.toBeInTheDocument()
  })

  it('says a truly OPTIMAL result was proven optimal, with the frozen snapshot it used', async () => {
    renderResult(SUCCESS)

    expect(await screen.findByText(/prouvé optimal/)).toBeInTheDocument()
    expect(screen.getByText(/couverture complète, 28 gardes affectées/)).toBeInTheDocument()
    expect(
      screen.getByText(/État figé le 21\/09\/2026 à 10:14 \(16 membres, 42 indisponibilités\)/),
    ).toBeInTheDocument()
  })

  it('says what a conditional line required, and when its source line failed (docs/decisions.md D164)', async () => {
    renderResult({
      lines: [
        {
          ...SUCCESS.lines[0],
          lineStableId: 'line-2',
          lineName: 'Renfort',
          coverageStatus: 'COMPLETE',
          assignmentCount: 3,
          demand: { requiredUnitCount: 2, notRequiredUnitCount: 5, undeterminedUnitCount: 1 },
        },
        {
          ...SUCCESS.lines[0],
          lineStableId: 'line-3',
          lineName: 'Renfort bis',
          generationStableId: null,
          status: null,
          error: 'source_generation_failed',
          coverageStatus: null,
          assignmentCount: null,
          demand: null,
        },
      ],
    })

    expect(
      await screen.findByText(/2 renforts requis, couverture complète, 3 gardes affectées\./),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        /1 renfort n’a pas pu être évalué : la garde source correspondante n’a pas de titulaire\./,
      ),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/sa ligne source n’a pas pu être générée : les renforts n’ont pas été calculés\./),
    ).toBeInTheDocument()
  })

  it('reuses the real UNSAT diagnostic instead of inventing a cause in React', async () => {
    renderResult({
      lines: [
        {
          ...SUCCESS.lines[0],
          coverageStatus: 'INCOMPLETE',
          assignmentCount: 25,
          unassignedDutyCount: 3,
          diagnostics: {
            strictSolverStatus: 'UNSATISFIABLE',
            partialSolverStatus: 'OPTIMAL',
            requiredDutyCount: 28,
            assignedDutyCount: 25,
            unassignedDuties: [
              {
                dutyUnitStableKey: 'duty-1',
                critical: true,
                candidateExclusions: [
                  { candidateId: 'user-1', exclusions: [{ reason: 'UNAVAILABLE', context: {} }] },
                ],
              },
            ],
            structuralDiagnostics: [{ code: 'NO_ELIGIBLE_CANDIDATE', dutyUnitStableKey: 'duty-1' }],
            solverAnalysis: { available: true },
            diagnosticRelaxations: [
              {
                ruleCode: 'TEAM_MIN_REST',
                tier: 'POLICY_HARD',
                phrasing: 'Relâcher le repos minimum d’équipe permettrait de couvrir 1 garde de plus.',
                disclaimer: 'Une relaxation possible parmi d’autres — pas nécessairement la cause unique.',
              },
            ],
            existingDataConflict: null,
          },
        },
      ],
    })

    expect(await screen.findByText('Indisponibilité déclarée (1)')).toBeInTheDocument()
    expect(screen.getByText('Au moins une garde n’a aucun candidat éligible.')).toBeInTheDocument()
    expect(
      screen.getByText(/Relâcher le repos minimum d’équipe permettrait de couvrir 1 garde de plus\./),
    ).toBeInTheDocument()
  })

  it('names a cross-line exclusion in French, never by its raw code (docs/decisions.md D161)', async () => {
    renderResult({
      lines: [
        {
          ...SUCCESS.lines[0],
          coverageStatus: 'INCOMPLETE',
          assignmentCount: 27,
          unassignedDutyCount: 1,
          diagnostics: {
            strictSolverStatus: 'UNSATISFIABLE',
            partialSolverStatus: 'OPTIMAL',
            requiredDutyCount: 28,
            assignedDutyCount: 27,
            unassignedDuties: [
              {
                dutyUnitStableKey: 'duty-1',
                critical: false,
                candidateExclusions: [
                  { candidateId: 'user-1', exclusions: [{ reason: 'CROSS_LINE_CONFLICT', context: {} }] },
                  {
                    candidateId: 'user-2',
                    exclusions: [{ reason: 'CROSS_LINE_TEAM_MIN_REST', context: {} }],
                  },
                ],
              },
            ],
            structuralDiagnostics: [{ code: 'NO_ELIGIBLE_CANDIDATE', dutyUnitStableKey: 'duty-1' }],
            solverAnalysis: { available: true },
            diagnosticRelaxations: [],
            existingDataConflict: null,
          },
        },
      ],
    })

    expect(
      await screen.findByText('Déjà de garde au même moment sur une autre ligne (1)'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Repos minimum d’équipe avec une garde sur une autre ligne (1)'),
    ).toBeInTheDocument()
    expect(screen.queryByText(/CROSS_LINE/)).not.toBeInTheDocument()
  })
})

describe('Règles de génération (docs/decisions.md D137)', () => {
  it('is not offered before the primary line is known', () => {
    renderActions()
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    expect(screen.queryByRole('menuitem', { name: 'Règles de génération' })).not.toBeInTheDocument()
  })

  it('shows the inactive state and lets a manager activate it', async () => {
    const api = stubApi({
      'GET /api/planning-lines/line-1/rule-set': () => ({ active: false }),
      'POST /api/planning-lines/line-1/rule-set/activate': () => ({
        active: true,
        activatedAt: '2026-09-24T09:00:00+00:00',
        effectiveFrom: '2026-09-24',
      }),
    })
    const { onChanged } = renderActions(makeStatus(), undefined, {
      primaryLineStableId: 'line-1',
      primaryLineName: 'Seniors',
    })

    chooseMenu('Règles de génération')
    const dialog = await screen.findByRole('dialog', { name: 'Règles de génération — Seniors' })
    expect(await within(dialog).findByText(/Aucune règle de génération n’est active/)).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Activer' }))

    expect(
      await within(dialog).findByText(/Des règles de génération sont actives pour cette ligne/),
    ).toBeInTheDocument()
    expect(api.requests('POST', '/api/planning-lines/line-1/rule-set/activate')).toHaveLength(1)
    expect(onChanged).toHaveBeenCalledTimes(1)
  })

  it('shows the active state directly, with no Activer button', async () => {
    stubApi({
      'GET /api/planning-lines/line-1/rule-set': () => ({
        active: true,
        activatedAt: '2026-09-20T08:00:00+00:00',
        effectiveFrom: '2026-09-20',
      }),
    })
    renderActions(makeStatus(), undefined, { primaryLineStableId: 'line-1', primaryLineName: 'Seniors' })

    chooseMenu('Règles de génération')
    const dialog = await screen.findByRole('dialog')
    expect(
      await within(dialog).findByText(/Des règles de génération sont actives pour cette ligne/),
    ).toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: 'Activer' })).not.toBeInTheDocument()
  })

  it('reports an activation failure and stays open', async () => {
    stubApi({
      'GET /api/planning-lines/line-1/rule-set': () => ({ active: false }),
      'POST /api/planning-lines/line-1/rule-set/activate': () => httpStatus(500, {}),
    })
    renderActions(makeStatus(), undefined, { primaryLineStableId: 'line-1', primaryLineName: 'Seniors' })

    chooseMenu('Règles de génération')
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Activer' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/Impossible d’activer/)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})
