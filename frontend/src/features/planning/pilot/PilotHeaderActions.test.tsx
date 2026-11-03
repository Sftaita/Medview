import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makePreflight } from '../../../testUtils/pilotFixtures'
import { status as httpStatus, stubApi } from '../../../testUtils/stubApi'
import { PilotHeaderActions } from './PilotHeaderActions'
import { ResultBody } from './GenerationModal'
import { COMPLETE_HELP } from './periodAction'
import type { FillLinePlan, LaunchResult, PlanningJob } from './types'

beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

type HeaderProps = Partial<Parameters<typeof PilotHeaderActions>[0]>

function renderActions(props: HeaderProps = {}) {
  const handlers = { onChanged: vi.fn(), onLaunched: vi.fn() }
  render(<PilotHeaderActions planningStableId="plan-1" timezone="Europe/Brussels" {...handlers} {...props} />)
  return handlers
}

const PERIOD_2 = { stableId: 'c2', number: 2, startsAt: '2027-05-01', lastDay: '2027-07-31', named: true }

function linePlan(overrides: Partial<FillLinePlan>): FillLinePlan {
  return {
    lineStableId: 'l1',
    lineName: 'Seniors',
    lineType: 'PRIMARY',
    periodStatus: 'GENERATED',
    generated: true,
    action: 'NOTHING',
    reason: null,
    selectable: false,
    selectedByDefault: false,
    requiredDutyCount: 10,
    coveredRequiredDutyCount: 10,
    uncoveredRequiredDutyCount: 0,
    protectedAssignmentCount: 10,
    blockers: [],
    warnings: [],
    afterSource: false,
    ...overrides,
  }
}

const slot = () => document.querySelector('.pd-main-action') as HTMLElement

/**
 * The confirm button ("Générer" — "Générer quand même" only with a warning) is rendered — disabled — while the preflight loads: find it,
 * then wait until it is enabled. Clicking it at once raced the preflight and was
 * silently ignored on slower runners (the CI), leaving the test waiting forever.
 */
async function enabledConfirm(scope: Pick<typeof screen, 'findByRole'> = screen) {
  const button = await scope.findByRole('button', { name: 'Générer' })
  await waitFor(() => expect(button).toBeEnabled())
  return button
}

/** Opens the header's "⋯" menu and picks an entry. */
function chooseMenu(name: string) {
  fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
  fireEvent.click(screen.getByRole('menuitem', { name }))
}

describe('PilotHeaderActions — one main action (docs/decisions.md D228)', () => {
  it('nothing generated: "Générer la période N", never "Générer le planning", without a "?"', () => {
    renderActions({ period: PERIOD_2 })

    expect(within(slot()).getByRole('button', { name: 'Générer la période 2' })).toBeEnabled()
    expect(screen.queryByRole('button', { name: 'Générer le planning' })).not.toBeInTheDocument()
    expect(within(slot()).queryByRole('button', { name: /^Aide/ })).not.toBeInTheDocument()
  })

  it('offers the generation even before the lines are analysed', () => {
    renderActions({ plans: undefined })

    expect(screen.getByRole('button', { name: 'Générer la période 1' })).toBeEnabled()
  })

  it('a line generated: "Compléter la période N", with a "?" saying what it does', async () => {
    stubApi({
      'GET /api/plannings/plan-1/fill-preview': () => ({ lines: [], blockers: [], needsRestPolicy: false }),
    })
    renderActions({
      period: PERIOD_2,
      hasGeneratedLines: true,
      plans: [linePlan({ action: 'COMPLETE', uncoveredRequiredDutyCount: 2 })],
    })

    expect(screen.queryByRole('button', { name: /^Générer/ })).not.toBeInTheDocument()
    fireEvent.click(within(slot()).getByRole('button', { name: 'Aide : Compléter la période' }))
    expect(within(slot()).getByRole('note')).toHaveTextContent(COMPLETE_HELP)

    fireEvent.click(within(slot()).getByRole('button', { name: 'Compléter la période 2' }))
    expect(await screen.findByRole('dialog', { name: 'Compléter la période 2 ?' })).toBeInTheDocument()
  })

  it('nothing to launch: says the period is complete, in the same place', () => {
    renderActions({ period: PERIOD_2, hasGeneratedLines: true, plans: [linePlan({})] })

    expect(within(slot()).getByRole('status')).toHaveTextContent('Période complète')
    expect(within(slot()).queryByRole('button')).not.toBeInTheDocument()
  })

  it('only holes of published lines: says how many to attribute by hand, and why, nothing to launch', () => {
    renderActions({
      period: PERIOD_2,
      hasGeneratedLines: true,
      plans: [
        linePlan({
          periodStatus: 'PUBLISHED',
          action: 'EXCLUDED',
          reason: 'published',
          uncoveredRequiredDutyCount: 3,
        }),
      ],
    })

    expect(within(slot()).getByRole('status')).toHaveTextContent('3 gardes à attribuer à la main')
    expect(within(slot()).queryByRole('button', { name: /Compléter/ })).not.toBeInTheDocument()
    fireEvent.click(within(slot()).getByRole('button', { name: 'Aide : Gardes à attribuer à la main' }))
    expect(within(slot()).getByRole('note')).toHaveTextContent(/n’est jamais complétée automatiquement/)
  })

  it('a computation running: no second launch, in the same place', () => {
    renderActions({ period: PERIOD_2, busy: true })

    expect(within(slot()).getByRole('status')).toHaveTextContent('Calcul en cours…')
    expect(screen.queryByRole('button', { name: /Générer|Compléter/ })).not.toBeInTheDocument()
  })

  it('never shows two main actions: no "Générer les postes non pourvus" next to it', () => {
    for (const hasGeneratedLines of [false, true]) {
      renderActions({ period: PERIOD_2, hasGeneratedLines })
      expect(document.querySelectorAll('.pd-main-action')).toHaveLength(1)
      expect(within(slot()).getAllByRole('button')).toHaveLength(hasGeneratedLines ? 2 : 1) // the action, its "?"
      expect(screen.queryByRole('button', { name: /postes non pourvus/ })).not.toBeInTheDocument()
      cleanup()
    }
  })

  it('opens the generation dialog when the page asks for it (controlled)', async () => {
    stubApi({ 'GET /api/plannings/plan-1/generation-preflight': () => makePreflight() })
    const onDialogChange = vi.fn()
    renderActions({ dialog: 'generate', onDialogChange })

    expect(await screen.findByRole('dialog')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))
    expect(onDialogChange).toHaveBeenCalledWith(null)
  })
})

describe('PilotHeaderActions — the "⋯" menu', () => {
  const entries = () => {
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    const labels = screen.getAllByRole('menuitem').map((item) => item.textContent)
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    return labels
  }

  it('holds only what concerns the planning: the name, the general week, and replacing a calendar', () => {
    renderActions({ onRename: vi.fn(), canManageStructure: true, hasGeneratedLines: true })

    expect(entries()).toEqual(['Modifier le nom', 'Semaine type', 'Régénérer entièrement…'])
  })

  it('offers "Régénérer entièrement…" only for a period generated and not entirely published', () => {
    renderActions({ onRename: vi.fn(), hasGeneratedLines: false })
    expect(entries()).toEqual(['Modifier le nom'])
    cleanup()

    renderActions({ onRename: vi.fn(), hasGeneratedLines: true, canRegenerate: false })
    expect(entries()).toEqual(['Modifier le nom'])
  })

  it('lists Modifier le nom only when the page allows renaming, and closes the menu on Escape', () => {
    const onRename = vi.fn()
    renderActions({ onRename })

    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    fireEvent.keyDown(window, { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    chooseMenu('Modifier le nom')
    expect(onRename).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('has no Modifier le nom entry without onRename', () => {
    renderActions({ hasGeneratedLines: true })

    expect(entries()).not.toContain('Modifier le nom')
  })
})

describe('Semaine type — the general one of the planning (docs/decisions.md D227)', () => {
  it('is not offered without the right to manage the structure', () => {
    renderActions({ onRename: vi.fn() })
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    expect(screen.queryByRole('menuitem', { name: 'Semaine type' })).not.toBeInTheDocument()
  })

  it('opens, loads the structure of the planning and saves it', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/week-template': () => ({
        blocks: [{ id: 'A', name: 'Week-end', family: 'Week-end', days: ['VEN', 'SAM', 'DIM'] }],
        solo: ['LUN', 'MAR', 'MER', 'JEU'],
        soloFamily: 'Semaine',
        excluded: [],
        lines: [{ stableId: 'line-1', name: 'Seniors', kind: 'GENERAL', periods: [] }],
      }),
      'PUT /api/plannings/plan-1/week-template': () => ({
        blocks: [],
        solo: ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
        soloFamily: 'Semaine',
        excluded: [],
        lines: [],
        appliedTo: ['line-1'],
      }),
    })
    const { onChanged } = renderActions({ canManageStructure: true })

    chooseMenu('Semaine type')
    const dialog = await screen.findByRole('dialog', { name: 'Semaine type du planning' })
    expect(await within(dialog).findByText('Ven · Sam · Dim · 3 jours')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Dissoudre' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1))
    expect(api.requests('PUT', '/api/plannings/plan-1/week-template')).toHaveLength(1)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
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

  it('never counts reinforcements before their source line is assigned — says when they are decided (D167)', async () => {
    const base = makePreflight()
    stubApi({
      [preflightUrl]: () =>
        makePreflight({
          lines: [
            base.lines[0],
            {
              stableId: 'line-2',
              name: 'Renfort',
              type: 'SECONDARY',
              memberCount: 2,
              dutyCount: 30,
              periodStatus: 'DRAFT',
              hasActiveRuleSet: true,
              familyUnitCounts: {},
              demandSourceLineName: 'Seniors',
            },
          ],
        }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    const dialog = await screen.findByRole('dialog', { name: 'Générer le planning ?' })
    expect(await within(dialog).findByText(/20 gardes indépendantes à répartir/)).toBeInTheDocument()
    expect(
      within(dialog).getByText(
        'Ligne « Renfort » : renforts déterminés après l’attribution de la ligne « Seniors »',
      ),
    ).toBeInTheDocument()
    expect(within(dialog).queryByText(/30 gardes/)).not.toBeInTheDocument()
  })

  it('shows the preflight: period, participants, unavailabilities — never a count of confirmations (D214)', async () => {
    stubApi({ [preflightUrl]: () => makePreflight() })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    const dialog = await screen.findByRole('dialog', { name: 'Générer le planning ?' })
    await within(dialog).findByText('16 membres participent')
    expect(within(dialog).getByText('1 janvier 2027 → 31 mars 2027')).toBeInTheDocument()
    expect(
      within(dialog).getByText(
        /Les calendriers sont pris tels qu’ils sont au lancement : sans indisponibilité, un membre est disponible/,
      ),
    ).toBeInTheDocument()
    expect(within(dialog).getByText('42 indisponibilités sont enregistrées')).toBeInTheDocument()
    expect(within(dialog).queryByText(/confirmé|répondu/)).not.toBeInTheDocument()
    expect(
      within(dialog).getByText('Cette génération utilisera l’état actuel des données.'),
    ).toBeInTheDocument()
  })

  it('has nothing to warn about when nobody declared anything: everyone is available', async () => {
    stubApi({ [preflightUrl]: () => makePreflight({ unavailabilityCount: 0 }) })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('16 membres participent')
    expect(within(dialog).queryByRole('list', { name: 'Avertissements' })).not.toBeInTheDocument()
    expect(await enabledConfirm(within(dialog))).toBeInTheDocument()
  })

  it('a passed deadline is one more warning, never a blocker', async () => {
    stubApi({
      [preflightUrl]: () =>
        makePreflight({
          availabilityDeadline: '2026-09-25',
          deadlineOverdueDays: 2,
          warnings: [{ code: 'DEADLINE_PASSED', lineStableId: null, lineName: null }],
        }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('Date limite indicative : 25 septembre 2026')
    expect(
      within(dialog).getByText(/Date limite indicative dépassée depuis 2 jours\. Elle reste indicative/),
    ).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Générer quand même' })).toBeEnabled()
  })

  it('names the selected period in the preflight and the launch, and in the dialog once there are several (lot L6)', async () => {
    const api = stubApi({
      [preflightUrl]: () => makePreflight({ period: { stableId: 'c2', number: 2 } }),
      [launchUrl]: () => httpStatus(202, QUEUED),
    })
    renderActions({ period: PERIOD_2 })

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 2' }))
    const dialog = await screen.findByRole('dialog', { name: 'Générer la période 2 ?' })
    expect(await within(dialog).findByText('Période 2 :')).toBeInTheDocument()
    expect(within(dialog).getByText(/1 mai.*31 juil/)).toBeInTheDocument()
    fireEvent.click(await enabledConfirm(within(dialog)))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/generations')).toHaveLength(1))
    expect(api.requests('GET', '/api/plannings/plan-1/generation-preflight')[0].query.get('period')).toBe(
      'c2',
    )
    const launch = api.requests('POST', '/api/plannings/plan-1/generations')[0]
    expect(launch.query.get('period')).toBe('c2')
    expect(launch.body).not.toHaveProperty('scope')
  })

  it('generates only the lines not published when the manager chooses it — kept, generated and ignored lines told apart (D195)', async () => {
    const lines = (decisions: ('GENERATE' | 'KEEP' | 'EXCLUDE')[]) =>
      ['Seniors', 'Juniors', 'Renfort'].map((name, index) => ({
        ...makePreflight().lines[0],
        stableId: `line-${index}`,
        name,
        decision: decisions[index],
      }))
    const api = stubApi({
      [preflightUrl]: ({ query }) =>
        query.get('scope') === 'unlocked_lines'
          ? makePreflight({
              scope: 'unlocked_lines',
              requiredScope: null,
              lines: lines(['KEEP', 'GENERATE', 'EXCLUDE']),
            })
          : makePreflight({
              canGenerate: false,
              blockers: [{ code: 'PERIOD_LOCKED', lineStableId: 'line-0', lineName: 'Seniors' }],
              requiredScope: 'unlocked_lines',
              lines: lines(['KEEP', 'GENERATE', 'EXCLUDE']),
            }),
      [launchUrl]: () => httpStatus(202, QUEUED),
    })
    renderActions({ period: PERIOD_2 })

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 2' }))
    const dialog = await screen.findByRole('dialog')
    const choose = await within(dialog).findByRole('button', {
      name: 'Générer uniquement les lignes non publiées',
    })
    expect(within(dialog).getByRole('button', { name: 'Générer' })).toBeDisabled()
    expect(within(dialog).getByText(/déjà publiée : jamais régénérée/)).toBeInTheDocument()
    fireEvent.click(choose)

    const kept = await within(dialog).findByText(/déjà publiée : conservée telle quelle/)
    expect(kept.closest('li')).toHaveTextContent('Seniors')
    expect(
      within(dialog)
        .getByText(/sera générée/)
        .closest('li'),
    ).toHaveTextContent('Juniors')
    expect(
      within(dialog)
        .getByText(/aucune garde demandée sur cette période/)
        .closest('li'),
    ).toHaveTextContent('Renfort')
    fireEvent.click(await enabledConfirm(within(dialog)))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/generations')).toHaveLength(1))
    const preflights = api.requests('GET', '/api/plannings/plan-1/generation-preflight')
    expect(preflights.map((r) => r.query.get('scope'))).toEqual([null, 'unlocked_lines'])
    expect(api.requests('POST', '/api/plannings/plan-1/generations')[0].body).toMatchObject({
      scope: 'unlocked_lines',
    })
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

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))
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

  it('is not launched from here while a job runs: the main action says so', () => {
    renderActions({ busy: true })

    expect(screen.queryByRole('button', { name: /^Générer/ })).not.toBeInTheDocument()
    expect(within(slot()).getByRole('status')).toHaveTextContent('Calcul en cours…')
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

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))
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

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    const dialog = await screen.findByRole('dialog')
    const blockers = await within(dialog).findByRole('list', { name: 'Points bloquants' })
    expect(
      within(blockers).getByText(
        'Ligne « Seniors » : les règles de génération ne sont pas activées (Paramètres de la ligne).',
      ),
    ).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Générer' })).toBeDisabled()
  })

  it('explains the blockers of lot L4b in words (docs/decisions.md D195)', async () => {
    stubApi({
      [preflightUrl]: () =>
        makePreflight({
          canGenerate: false,
          blockers: [
            { code: 'NO_MEMBER_IN_PERIOD', lineStableId: 'line-2', lineName: 'Juniors' },
            { code: 'SOURCE_OF_PUBLISHED_LINE', lineStableId: 'line-1', lineName: 'Seniors' },
            { code: 'NO_LINE_TO_GENERATE', lineStableId: null, lineName: null },
          ],
        }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    const dialog = await screen.findByRole('dialog')
    const blockers = await within(dialog).findByRole('list', { name: 'Points bloquants' })
    expect(
      within(blockers).getByText(
        'Ligne « Juniors » : des gardes sont à pourvoir mais aucun membre ne participe à cette période.',
      ),
    ).toBeInTheDocument()
    expect(within(blockers).getByText(/déclenche les renforts d’une ligne déjà publiée/)).toBeInTheDocument()
    expect(within(blockers).getByText(/^Aucune ligne à générer/)).toBeInTheDocument()
    expect(within(blockers).queryByText(/génération impossible/)).not.toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Générer' })).toBeDisabled()
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

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    const dialog = await screen.findByRole('dialog')
    const blockers = await within(dialog).findByRole('list', { name: 'Points bloquants' })
    expect(within(blockers).getByText(/plusieurs gardes de la ligne source/)).toBeInTheDocument()
    expect(
      within(dialog).getByText(/aucune garde sur la ligne source : aucun renfort n’y est possible/),
    ).toBeInTheDocument()
    expect(within(dialog).queryByText(/COVERAGE_SOURCE/)).not.toBeInTheDocument()
  })

  it('says in words when the end of the period cuts a block, or a boundary does (docs/decisions.md D211)', async () => {
    stubApi({
      [preflightUrl]: () =>
        makePreflight({
          canGenerate: false,
          blockers: [{ code: 'BLOCK_CUT_BY_PERIOD_BOUNDARY', lineStableId: 'line-1', lineName: 'Seniors' }],
          warnings: [
            { code: 'PERIOD_END_CUTS_BLOCK', lineStableId: 'line-1', lineName: 'Seniors' },
            { code: 'HISTORICAL_BLOCK_REMAINDER', lineStableId: 'line-1', lineName: 'Seniors' },
          ],
        }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    const dialog = await screen.findByRole('dialog')
    const blockers = await within(dialog).findByRole('list', { name: 'Points bloquants' })
    expect(
      within(blockers).getByText(/la frontière avec une période voisine coupe un bloc de gardes/),
    ).toBeInTheDocument()
    expect(
      within(dialog).getByText(/la fin de la période coupe un bloc de gardes : ce bloc ne sera pas créé/),
    ).toBeInTheDocument()
    expect(within(dialog).getByText(/la période commence au milieu d’un bloc coupé/)).toBeInTheDocument()
    expect(within(dialog).queryByText(/BLOCK|REMAINDER/)).not.toBeInTheDocument()
  })

  it('tells when another generation is already running', async () => {
    stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () => httpStatus(409, { error: 'job_in_progress' }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))
    fireEvent.click(await enabledConfirm())

    expect(await screen.findByRole('alert')).toHaveTextContent(/déjà en cours/)
    expect(screen.getByRole('button', { name: 'Générer' })).toBeEnabled()
  })

  it('reports a preflight that could not load', async () => {
    stubApi({ [preflightUrl]: () => httpStatus(500, {}) })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/Impossible de contrôler/)
  })

  it('shows real family unit counts, never hardcoded names (docs/decisions.md D137)', async () => {
    stubApi({ [preflightUrl]: () => makePreflight() })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

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

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))
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

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(await within(dialog).findByRole('checkbox', { name: /Repos minimum d’équipe/ }))
    fireEvent.change(within(dialog).getByLabelText('Repos minimum d’équipe, en heures'), {
      target: { value: '36' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Générer' }))

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

describe('GenerationModal — SurgicalHub leave (docs/surgicalhub-integration.md §7.5, D8)', () => {
  const preflightUrl = 'GET /api/plannings/plan-1/generation-preflight'
  const launchUrl = 'POST /api/plannings/plan-1/generations'
  const BOB = {
    userStableId: 'user-bob',
    firstName: 'Bob',
    lastName: 'Lambert',
    lastSuccessfulSyncAt: '2026-09-26T08:00:00+00:00',
    error: 'unreachable',
  }

  it('says in the preflight who takes their leave from SurgicalHub', async () => {
    stubApi({
      [preflightUrl]: () =>
        makePreflight({
          surgicalHub: [
            {
              userStableId: 'user-bob',
              firstName: 'Bob',
              lastName: 'Lambert',
              lastSuccessfulSyncAt: null,
              lastSyncError: null,
            },
          ],
        }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))

    const dialog = await screen.findByRole('dialog')
    expect(
      await within(dialog).findByText(/1 participant reprend ses congés de SurgicalHub/),
    ).toBeInTheDocument()
  })

  it('blocks on outdated leave and lets the creator go on only by ticking an explicit choice', async () => {
    const calls = stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: ({ body }) =>
        (body as { overrideStaleSurgicalHubData?: boolean }).overrideStaleSurgicalHubData === true
          ? httpStatus(202, { ...QUEUED, surgicalHub: { warnings: [], overridden: [BOB] } })
          : httpStatus(409, { error: 'surgicalhub_data_stale', participants: [BOB], canOverride: true }),
    })
    const { onLaunched } = renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))
    fireEvent.click(await enabledConfirm())

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Bob Lambert — dernière reprise 26/09/2026 à 10:00')
    const go = screen.getByRole('button', { name: 'Générer avec ces données' })
    expect(go).toBeDisabled()
    expect(onLaunched).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('checkbox', { name: /Je génère quand même/ }))
    fireEvent.click(go)

    expect(
      await screen.findByText(/Générée malgré des congés SurgicalHub non actualisés/),
    ).toBeInTheDocument()
    expect(onLaunched).toHaveBeenCalledWith(QUEUED.job)
    const launches = calls.calls.filter(
      (call) => call.method === 'POST' && call.path.endsWith('/generations'),
    )
    expect(
      launches.map(
        (call) => (call.body as { overrideStaleSurgicalHubData?: boolean }).overrideStaleSurgicalHubData,
      ),
    ).toEqual([undefined, true])
    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('offers no override to someone who is not the creator', async () => {
    stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () =>
        httpStatus(409, { error: 'surgicalhub_data_stale', participants: [BOB], canOverride: false }),
    })
    renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))
    fireEvent.click(await enabledConfirm())

    expect(
      await screen.findByText(/Seul le créateur du planning peut générer malgré tout/),
    ).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: /Je génère quand même/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Générer avec ces données' })).toBeDisabled()
  })

  it('keeps the dialog open after the launch to show leave used without a fresh refresh', async () => {
    stubApi({
      [preflightUrl]: () => makePreflight(),
      [launchUrl]: () => httpStatus(202, { ...QUEUED, surgicalHub: { warnings: [BOB], overridden: [] } }),
    })
    const { onLaunched } = renderActions()

    fireEvent.click(screen.getByRole('button', { name: 'Générer la période 1' }))
    fireEvent.click(await enabledConfirm())

    expect(
      await screen.findByText(/ces congés récents \(moins de 24 heures\) ont été utilisés tels quels/),
    ).toBeInTheDocument()
    expect(screen.getByText(/Bob Lambert/)).toBeInTheDocument()
    expect(onLaunched).toHaveBeenCalled()
  })
})
