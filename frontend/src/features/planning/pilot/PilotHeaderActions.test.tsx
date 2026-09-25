import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makePreflight, makeStatus } from '../../../testUtils/pilotFixtures'
import { status as httpStatus, stubApi } from '../../../testUtils/stubApi'
import { PilotHeaderActions } from './PilotHeaderActions'
import type { CollectionStatus, LaunchResult } from './types'

beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function renderActions(
  status: CollectionStatus | null = makeStatus(),
  handlers = { onChanged: vi.fn(), onGenerated: vi.fn() },
  lineProps: { primaryLineStableId?: string; primaryLineName?: string } = {},
) {
  render(
    <PilotHeaderActions
      planningStableId="plan-1"
      timezone="Europe/Brussels"
      status={status}
      onChanged={handlers.onChanged}
      onGenerated={handlers.onGenerated}
      {...lineProps}
    />,
  )
  return handlers
}

describe('PilotHeaderActions — deadline and buttons', () => {
  it('shows the informative deadline, and Paramètres and Générer le planning', () => {
    renderActions(makeStatus({ availabilityDeadline: '2026-09-25' }))

    expect(screen.getByText(/Fin souhaitée d’encodage :/)).toHaveTextContent('25 septembre 2026')
    expect(screen.getByRole('button', { name: 'Paramètres' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Générer le planning' })).toBeEnabled()
  })

  it('keeps everything enabled once the deadline is passed — it only tags it', () => {
    renderActions(makeStatus({ availabilityDeadline: '2026-09-25', deadlineOverdueDays: 3 }))

    expect(screen.getByText('Dépassée')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Paramètres' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Générer le planning' })).toBeEnabled()
  })

  it('offers the generation even before the pilot data has loaded, and shows no deadline', () => {
    renderActions(null)

    expect(screen.queryByText(/Fin souhaitée/)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Générer le planning' })).toBeEnabled()
  })
})

describe('Semaine type (docs/decisions.md D136)', () => {
  it('is not offered before the primary line is known', () => {
    renderActions()
    expect(screen.queryByRole('button', { name: 'Semaine type' })).not.toBeInTheDocument()
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

    fireEvent.click(screen.getByRole('button', { name: 'Semaine type' }))
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

    fireEvent.click(screen.getByRole('button', { name: 'Paramètres' }))

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

    fireEvent.click(screen.getByRole('button', { name: 'Paramètres' }))
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

    fireEvent.click(screen.getByRole('button', { name: 'Paramètres' }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Effacer la date' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(api.requests('PATCH', '/api/plannings/plan-1/settings')[0].body).toEqual({
      availabilityDeadline: null,
    })
  })

  it('explains a refused date and stays open', async () => {
    stubApi({ 'PATCH /api/plannings/plan-1/settings': () => httpStatus(422, { error: 'validation_failed' }) })
    renderActions(makeStatus())

    fireEvent.click(screen.getByRole('button', { name: 'Paramètres' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Fin souhaitée d’encodage des indisponibilités'), {
      target: { value: '2020-01-01' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/déjà passée/)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})

const SUCCESS: LaunchResult = {
  planningStableId: 'plan-1',
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
    },
  ],
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

  it('launches once even on a double click, shows the result, and tells the page', async () => {
    let release: () => void = () => {}
    const held = new Promise<void>((resolve) => (release = resolve))
    const api = stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: async () => {
        await held
        return SUCCESS
      },
    })
    const { onGenerated } = renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    const dialog = await screen.findByRole('dialog')
    const confirm = await within(dialog).findByRole('button', { name: 'Générer quand même' })
    fireEvent.click(confirm)
    fireEvent.click(confirm)

    expect(await within(dialog).findByRole('button', { name: 'Génération en cours…' })).toBeDisabled()
    expect(within(dialog).getByText('La génération peut durer quelques secondes…')).toBeInTheDocument()
    // Nothing can dismiss the dialog while it runs.
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.getByRole('dialog')).toBeInTheDocument()

    release()
    const result = await within(dialog).findByRole('list', { name: 'Résultat de la génération' })
    expect(within(result).getByText(/couverture complète, 28 gardes affectées/)).toBeInTheDocument()
    expect(
      within(result).getByText(/État figé le 21\/09\/2026 à 10:14 \(16 membres, 42 indisponibilités\)/),
    ).toBeInTheDocument()
    expect(api.requests('POST', '/api/plannings/plan-1/generations')).toHaveLength(1)
    expect(onGenerated).toHaveBeenCalledTimes(1)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Fermer' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('reports an incomplete coverage honestly', async () => {
    stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () => ({
        ...SUCCESS,
        lines: [
          { ...SUCCESS.lines[0], coverageStatus: 'INCOMPLETE', assignmentCount: 25, unassignedDutyCount: 3 },
        ],
      }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Générer quand même' }))

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

  it('tells when another generation is already running', async () => {
    stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () => httpStatus(409, { error: 'generation_in_progress' }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Générer quand même' }))

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
      [launchUrl]: () => SUCCESS,
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Générer quand même' }))

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
      [launchUrl]: () => SUCCESS,
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
    stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () => ({
        ...SUCCESS,
        lines: [{ ...SUCCESS.lines[0], strictSolverStatus: 'FEASIBLE' }],
      }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Générer quand même' }))

    expect(await screen.findByText(/L’optimalité mathématique n’a pas pu être démontrée/)).toBeInTheDocument()
    expect(screen.queryByText(/prouvé optimal/)).not.toBeInTheDocument()
  })

  it('says a truly OPTIMAL result was proven optimal', async () => {
    stubApi({ [preflightUrl]: () => makePreflight(), [launchUrl]: () => SUCCESS })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Générer quand même' }))

    expect(await screen.findByText(/prouvé optimal/)).toBeInTheDocument()
  })

  it('reuses the real UNSAT diagnostic instead of inventing a cause in React', async () => {
    stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () => ({
        ...SUCCESS,
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
      }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer le planning' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Générer quand même' }))

    expect(await screen.findByText('Indisponibilité déclarée (1)')).toBeInTheDocument()
    expect(screen.getByText('Au moins une garde n’a aucun candidat éligible.')).toBeInTheDocument()
    expect(
      screen.getByText(/Relâcher le repos minimum d’équipe permettrait de couvrir 1 garde de plus\./),
    ).toBeInTheDocument()
  })
})

describe('Règles de génération (docs/decisions.md D137)', () => {
  it('is not offered before the primary line is known', () => {
    renderActions()
    expect(screen.queryByRole('button', { name: 'Règles de génération' })).not.toBeInTheDocument()
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

    fireEvent.click(screen.getByRole('button', { name: 'Règles de génération' }))
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

    fireEvent.click(screen.getByRole('button', { name: 'Règles de génération' }))
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

    fireEvent.click(screen.getByRole('button', { name: 'Règles de génération' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Activer' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/Impossible d’activer/)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})
