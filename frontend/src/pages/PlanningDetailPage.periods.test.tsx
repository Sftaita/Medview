import '@testing-library/jest-dom/vitest'
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type {
  LineApplicability,
  PlanningDetail,
  PlanningLineSummary,
  PlanningPeriodStatus,
  PlanningPeriodView,
} from '../features/planning/types'
import type { CollectionStatus, FillLinePlan, FillPreview } from '../features/planning/pilot/types'
import { makeStatus } from '../testUtils/pilotFixtures'
import { status as httpStatus, stubApi, type Route as ApiRoute } from '../testUtils/stubApi'
import { addDays } from '../features/periods/dates'
import { PlanningDetailPage } from './PlanningDetailPage'

/**
 * Lot L6 (docs/planning-cycles.md §8): one permanent planning, its successive
 * periods. Trauma Delta, today 10 October 2026:
 * - period 1, 1 June → 30 September: published, over;
 * - period 2, 1 October → 30 January: partly published (Seniors published, Juniors generated, Renfort without demand);
 * - period 3, 31 January → 30 April: in preparation (Juniors incomplete), no collection yet.
 */

vi.mock('../features/auth/useAuth', () => ({
  useAuth: () => ({ user: { stableId: 'u-1', firstName: 'Camille', lastName: 'Dupont' } }),
}))

beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function line(stableId: string, name: string, type: 'PRIMARY' | 'SECONDARY'): PlanningLineSummary {
  return {
    stableId,
    name,
    type,
    position: 0,
    active: true,
    team: { stableId: `team-${stableId}`, name },
    memberCount: 3,
    planningPeriodStableId: `pp-${stableId}`,
    createdAt: '',
    updatedAt: '',
  }
}

const LINES = [
  line('sen', 'Seniors', 'PRIMARY'),
  line('jun', 'Juniors', 'SECONDARY'),
  line('ren', 'Renfort', 'SECONDARY'),
]

const APPLICABLE: LineApplicability = { state: 'APPLICABLE', reasons: ['DUTIES_MATERIALIZED'] }
const NO_DEMAND: LineApplicability = { state: 'NO_DEMAND', reasons: ['NO_DUTY', 'NO_WEEKLY_STRUCTURE'] }
const INCOMPLETE: LineApplicability = {
  state: 'INCOMPLETE',
  reasons: ['WEEKLY_STRUCTURE', 'NO_ACTIVE_RULE_SET'],
}

function period(
  number: number,
  startsAt: string,
  endsAt: string,
  lastDay: string,
  status: PlanningPeriodView['status'],
  lines: [string, PlanningPeriodStatus, LineApplicability][],
  overrides: Partial<PlanningPeriodView> = {},
): PlanningPeriodView {
  return {
    stableId: `c${number}`,
    number,
    startsAt,
    endsAt,
    lastDay,
    status,
    timeState: 'current',
    lineCounts: {
      counting: 2,
      published: status === 'published' ? 2 : status === 'partially_published' ? 1 : 0,
      incomplete: 0,
      withoutDemand: 1,
    },
    publishedAt: null,
    lastPublishedAt: null,
    generatedAt: null,
    collections: { open: 0, closed: 1, participants: 0, deadline: null, uncoveredDays: 0 },
    lines: lines.map(([lineStableId, lineStatus, applicability]) => ({
      lineStableId,
      planningPeriodStableId: `pp-${lineStableId}-${number}`,
      status: lineStatus,
      applicability,
    })),
    isLast: false,
    canEditStart: false,
    canEditEnd: false,
    canDelete: false,
    ...overrides,
  }
}

const P1 = period(
  1,
  '2026-06-01',
  '2026-10-01',
  '2026-09-30',
  'published',
  [
    ['sen', 'PUBLISHED', APPLICABLE],
    ['jun', 'PUBLISHED', APPLICABLE],
    ['ren', 'DRAFT', NO_DEMAND],
  ],
  { timeState: 'past', publishedAt: '2026-05-20T08:00:00+00:00', generatedAt: '2026-05-18T08:00:00+00:00' },
)
const P2 = period(
  2,
  '2026-10-01',
  '2027-01-31',
  '2027-01-30',
  'partially_published',
  [
    ['sen', 'PUBLISHED', APPLICABLE],
    ['jun', 'GENERATED', APPLICABLE],
    ['ren', 'DRAFT', NO_DEMAND],
  ],
  {
    publishedAt: '2026-09-20T08:00:00+00:00',
    lastPublishedAt: '2026-09-25T08:00:00+00:00',
    generatedAt: '2026-09-18T08:00:00+00:00',
  },
)
const P3 = period(
  3,
  '2027-01-31',
  '2027-05-01',
  '2027-04-30',
  'preparation',
  [
    ['sen', 'DRAFT', APPLICABLE],
    ['jun', 'DRAFT', INCOMPLETE],
    ['ren', 'DRAFT', NO_DEMAND],
  ],
  {
    timeState: 'future',
    isLast: true,
    collections: { open: 0, closed: 0, participants: 0, deadline: null, uncoveredDays: 90 },
  },
)

function planning(overrides: Partial<PlanningDetail> = {}): PlanningDetail {
  return {
    stableId: 'plan-1',
    name: 'Trauma Delta',
    creatorStableId: 'u-1',
    canManage: true,
    canManageAvailability: true,
    canGenerate: true,
    canManageCalendar: true,
    canPublish: true,
    canCreatePeriod: true,
    canOpenPeriodCollection: true,
    participating: true,
    memberCount: 9,
    startsAt: '2026-06-01',
    endsAt: '2027-05-01',
    timezone: 'Europe/Brussels',
    createdAt: '',
    updatedAt: '',
    today: '2026-10-10',
    periodCount: 3,
    lines: LINES,
    periods: [P1, P2, P3],
    ...overrides,
  }
}

const MEMBER = planning({
  canManage: false,
  canManageAvailability: false,
  canGenerate: false,
  canManageCalendar: false,
  canPublish: false,
  canCreatePeriod: false,
  canOpenPeriodCollection: false,
})

/** Shows the URL's query and moves back in the history, like the browser's buttons. */
function Probe() {
  const location = useLocation()
  const navigate = useNavigate()
  return (
    <>
      <output data-testid="search">{location.search}</output>
      <button type="button" onClick={() => navigate(-1)}>
        Retour navigateur
      </button>
    </>
  )
}

/** docs/decisions.md D210: the « Préparer la période » panel of a period, nothing planned yet. */
function blankPreparation(stableId: string) {
  return {
    period: { stableId, number: Number(stableId.slice(1)), startsAt: '2026-10-01', lastDay: '2027-01-31' },
    timezone: 'Europe/Brussels',
    sendTime: '08:00',
    today: '2026-10-24',
    editable: true,
    blockers: [],
    announcement: {
      on: null,
      state: 'none',
      outcome: null,
      executedAt: null,
      emails: { queued: 0, sent: 0, failed: 0, cancelled: 0, pending: 0 },
    },
    targetGenerationOn: null,
    collection: { state: 'not_open', participants: 0 },
    reminders: [],
  }
}

function renderPage(
  detail: () => PlanningDetail,
  extra: Record<string, ApiRoute> = {},
  url = '/plannings/plan-1',
) {
  const api = stubApi({
    'GET /api/plannings/plan-1': () => detail(),
    'GET /api/plannings/plan-1/collection-status': ({ query }) =>
      makeStatus({
        period: { stableId: query.get('period') ?? '', number: Number(query.get('period')?.slice(1)) },
      }),
    'GET /api/plannings/plan-1/availability-collections': () => [],
    'GET /api/me/availability-collections': () => [],
    'GET /api/plannings/plan-1/jobs/latest': () => ({ job: null }),
    ...Object.fromEntries(
      ['c1', 'c2', 'c3', 'c4'].map((id) => [
        `GET /api/plannings/plan-1/periods/${id}/preparation`,
        () => blankPreparation(id),
      ]),
    ),
    ...extra,
  })
  render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route
          path="/plannings/:planningId"
          element={
            <>
              <PlanningDetailPage />
              <Probe />
            </>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
  return api
}

/** The card of the selected period: the one place for its dates, status, progress and countdown. */
const selectedCard = () => document.querySelector('.mv-frise__seg[aria-pressed="true"]') as HTMLElement
/** What can be done on the selected period, under the cards. */
const periodActions = () => screen.getByRole('group', { name: /Actions de la période/ })

describe('PlanningDetailPage — periods (lot L6)', () => {
  it('opens on the current period, with its own status, its lines and what generating it would do', async () => {
    const api = renderPage(() => planning())

    const periods = await screen.findByRole('region', { name: 'Périodes du planning' })
    expect(within(periods).getByText('Périodes du planning · 3')).toBeInTheDocument()
    expect(within(selectedCard()).getByText(/Période 2/)).toBeInTheDocument()
    expect(within(selectedCard()).getAllByText('Partiellement publiée').length).toBeGreaterThan(0)
    expect(screen.getByTestId('search')).toHaveTextContent('')
    await waitFor(() =>
      expect(
        api.requests('GET', '/api/plannings/plan-1/collection-status').map((r) => r.query.get('period')),
      ).toEqual(['c2']),
    )

    const steps = screen.getByRole('region', { name: 'Préparation de la période 2' })
    expect(
      within(steps).getByText('1 / 2 lignes publiées · dernière diffusion le 25 sept. 2026'),
    ).toBeInTheDocument()
    const lines = within(steps).getByRole('list', { name: 'Lignes de la période 2' })
    expect(within(within(lines).getByText('Seniors').closest('li')!).getByText('Publiée')).toBeInTheDocument()
    expect(
      within(within(lines).getByText('Juniors').closest('li')!).getByText('Générée, non publiée'),
    ).toBeInTheDocument()
    const renfort = within(lines).getByText('Renfort').closest('li')!
    expect(within(renfort).getByText('Aucune garde demandée')).toBeInTheDocument()
    expect(within(renfort).getByText(/Ne bloque ni la génération ni la publication/)).toBeInTheDocument()

    // Partly published, a line already generated (Juniors): the main action protects what is assigned (D222) —
    // "Compléter la période 2"; replacing a calendar is only "Régénérer entièrement…", in the menu.
    expect(screen.queryByRole('button', { name: 'Générer la période 2' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Compléter la période 2' })).toBeInTheDocument()
  })

  it('follows the period in the URL, keeps older periods readable and moves back with the browser', async () => {
    const api = renderPage(() => planning(), {}, '/plannings/plan-1?periode=1')

    await screen.findByRole('region', { name: 'Périodes du planning' })
    expect(within(selectedCard()).getByText(/Période 1/)).toBeInTheDocument()
    expect(within(periodActions()).getByText(/Consultation de l’historique/)).toBeInTheDocument()
    // A fully published period: no (re)generation offered.
    expect(screen.queryByRole('button', { name: /Générer la période/ })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Période suivante' }))
    expect(screen.getByTestId('search')).toHaveTextContent('?periode=2')
    expect(within(selectedCard()).getByText(/Période 2/)).toBeInTheDocument()
    await waitFor(() =>
      expect(
        api.requests('GET', '/api/plannings/plan-1/collection-status').map((r) => r.query.get('period')),
      ).toEqual(['c1', 'c2']),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Retour navigateur' }))
    expect(screen.getByTestId('search')).toHaveTextContent('?periode=1')
    expect(within(selectedCard()).getByText(/Période 1/)).toBeInTheDocument()
  })

  it('counts the members of each line in the selected period, never in the default one (lot L7)', async () => {
    const counted = (stableId: string, counts: number[]) => ({
      ...LINES.find((l) => l.stableId === stableId)!,
      memberCount: 99,
      periods: counts.map((memberCount, index) => ({
        periodStableId: `c${index + 1}`,
        number: index + 1,
        planningPeriodStableId: `pp-${stableId}-${index + 1}`,
        status: 'DRAFT' as const,
        memberCount,
      })),
    })
    renderPage(
      () =>
        planning({
          lines: [counted('sen', [5, 4, 6]), counted('jun', [2, 1, 1]), counted('ren', [0, 0, 0])],
        }),
      {},
      '/plannings/plan-1?periode=2',
    )

    const lines = await screen.findByRole('list', { name: 'Lignes de garde' })
    expect(within(lines).getByText('Seniors · 4 membres')).toBeInTheDocument()
    expect(within(lines).getByText('Juniors · 1 membre')).toBeInTheDocument()
    expect(within(lines).queryByText(/99/)).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Période précédente' }))
    expect(
      await within(screen.getByRole('list', { name: 'Lignes de garde' })).findByText('Seniors · 5 membres'),
    ).toBeInTheDocument()
  })

  it('falls back to the default period when the URL names one that does not exist', async () => {
    renderPage(() => planning(), {}, '/plannings/plan-1?periode=42')

    await screen.findByRole('region', { name: 'Périodes du planning' })
    expect(within(selectedCard()).getByText(/Période 2/)).toBeInTheDocument()
  })

  it('never shows the availability follow-up of a period no longer selected', async () => {
    let releaseFirst: (value: CollectionStatus) => void = () => {}
    renderPage(
      () => planning(),
      {
        'GET /api/plannings/plan-1/collection-status': ({ query }) =>
          query.get('period') === 'c1'
            ? new Promise<CollectionStatus>((resolve) => {
                releaseFirst = resolve
              })
            : makeStatus({
                period: { stableId: 'c2', number: 2 },
                summary: { participantCount: 7, concernedCount: 7, unavailabilityCount: 0 },
              }),
      },
      '/plannings/plan-1?periode=1',
    )

    await screen.findByRole('region', { name: 'Périodes du planning' })
    fireEvent.click(screen.getByRole('button', { name: 'Période suivante' }))
    expect(await screen.findByRole('tab', { name: 'Indisponibilités (7)' })).toBeInTheDocument()

    releaseFirst(
      makeStatus({
        period: { stableId: 'c1', number: 1 },
        summary: { participantCount: 99, concernedCount: 99, unavailabilityCount: 0 },
      }),
    )
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.getByRole('tab', { name: 'Indisponibilités (7)' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Indisponibilités (99)' })).not.toBeInTheDocument()
  })

  it('says what an incomplete line misses, and opens the collection of a period in preparation', async () => {
    let detail = planning()
    const api = renderPage(
      () => detail,
      {
        'POST /api/plannings/plan-1/periods/c3/availability-collections': () => {
          detail = planning({
            periods: [
              P1,
              P2,
              {
                ...P3,
                status: 'collecting',
                collections: { ...P3.collections, open: 1, participants: 9, uncoveredDays: 0 },
              },
            ],
          })
          return httpStatus(201, { stableId: 'col-3', period: { stableId: 'c3', number: 3 } })
        },
      },
      '/plannings/plan-1?periode=3',
    )

    const steps = await screen.findByRole('region', { name: 'Préparation de la période 3' })
    const juniors = within(steps).getByText('Juniors').closest('li')!
    expect(within(juniors).getByText('Configuration incomplète')).toBeInTheDocument()
    expect(within(juniors).getByText('À compléter : règles de génération non activées.')).toBeInTheDocument()
    expect(within(selectedCard()).getByText('Période 3')).toBeInTheDocument()

    fireEvent.click(within(steps).getByRole('button', { name: 'Ouvrir la collecte' }))
    await waitFor(() =>
      expect(api.requests('POST', '/api/plannings/plan-1/periods/c3/availability-collections')).toHaveLength(
        1,
      ),
    )
    expect(await within(steps).findByText('Collecte ouverte')).toBeInTheDocument()
    expect(screen.getByText('Collecte des disponibilités ouverte pour la période 3')).toHaveAttribute(
      'role',
      'status',
    )
  })

  it('says why a collection could not be opened', async () => {
    renderPage(
      () => planning(),
      {
        'POST /api/plannings/plan-1/periods/c3/availability-collections': () =>
          httpStatus(409, { error: 'period_fully_collected', message: '' }),
      },
      '/plannings/plan-1?periode=3',
    )

    const steps = await screen.findByRole('region', { name: 'Préparation de la période 3' })
    fireEvent.click(within(steps).getByRole('button', { name: 'Ouvrir la collecte' }))
    expect(await within(steps).findByRole('alert')).toHaveTextContent(
      'Tous les jours de cette période sont déjà couverts par une collecte.',
    )
  })

  it('shows a plain member no calendar for a period whose lines are only generated, never asking the server for it (D204)', async () => {
    const draft = {
      ...P3,
      status: 'generated' as const,
      lines: P3.lines.map((l) => ({ ...l, status: 'GENERATED' as const })),
    }
    const api = renderPage(
      () => planning({ ...MEMBER, periods: [P1, P2, draft] }),
      {},
      '/plannings/plan-1?periode=3',
    )

    await screen.findByRole('region', { name: 'Périodes du planning' })
    fireEvent.click(screen.getByRole('tab', { name: /Gardes/ }))
    expect(screen.getByText('Pas encore de gardes pour la période 3')).toBeInTheDocument()
    expect(screen.getByText(/dès que la période aura été générée et publiée/)).toBeInTheDocument()
    expect(api.requests('GET', '/api/plannings/plan-1/result')).toHaveLength(0)
  })

  it('gives a plain member the periods to read, and nothing to create, open, generate or publish', async () => {
    renderPage(() => MEMBER)

    await screen.findByRole('region', { name: 'Périodes du planning' })
    expect(screen.queryByRole('button', { name: /Créer la période suivante/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Période suivante.*dès le/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Générer/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Plus d’actions' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Période suivante' }))
    const steps = await screen.findByRole('region', { name: 'Préparation de la période 3' })
    expect(within(steps).queryByRole('button', { name: /Ouvrir/ })).not.toBeInTheDocument()
  })
})

describe('PlanningDetailPage — one main action, whatever the period (docs/decisions.md D228)', () => {
  /** Period 1 published and complete; period 2 never generated. */
  const NEVER_GENERATED = period(
    2,
    '2026-10-01',
    '2027-01-31',
    '2027-01-30',
    'preparation',
    [
      ['sen', 'DRAFT', APPLICABLE],
      ['jun', 'DRAFT', APPLICABLE],
      ['ren', 'DRAFT', NO_DEMAND],
    ],
    { timeState: 'future', isLast: true },
  )
  /** Period 1 generated; period 2 too: both read "Compléter" until their analysis says otherwise. */
  const GENERATED = (number: number) =>
    period(
      number,
      number === 1 ? '2026-06-01' : '2026-10-01',
      number === 1 ? '2026-10-01' : '2027-01-31',
      number === 1 ? '2026-09-30' : '2027-01-30',
      'generated',
      [
        ['sen', 'GENERATED', APPLICABLE],
        ['jun', 'GENERATED', APPLICABLE],
        ['ren', 'DRAFT', NO_DEMAND],
      ],
      { generatedAt: '2026-05-18T08:00:00+00:00' },
    )

  const plan = (overrides: Partial<FillLinePlan>): FillLinePlan => ({
    lineStableId: 'sen',
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
  })
  const answer = (lines: FillLinePlan[]): FillPreview => ({ lines, blockers: [], needsRestPolicy: false })
  const COVERED = answer([plan({})])
  const HOLES_TO_COMPLETE = answer([plan({ action: 'COMPLETE', uncoveredRequiredDutyCount: 2 })])
  const HOLES_PUBLISHED = answer([
    plan({
      periodStatus: 'PUBLISHED',
      action: 'EXCLUDED',
      reason: 'published',
      uncoveredRequiredDutyCount: 2,
    }),
  ])

  const slots = () => document.querySelectorAll('.pd-main-action')
  const slot = () => slots()[0] as HTMLElement
  const head = () => document.querySelector('.pd-head-actions') as HTMLElement
  const primaryButtons = () => head().querySelectorAll('.pd-btn-primary')
  const menuEntries = () => {
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    const entries = screen.getAllByRole('menuitem').map((item) => item.textContent)
    fireEvent.click(screen.getByRole('button', { name: 'Plus d’actions' }))
    return entries
  }

  it('going from a complete period to one never generated: same place, same menu, only the label changes', async () => {
    const api = renderPage(
      () => planning({ periods: [P1, NEVER_GENERATED], periodCount: 2 }),
      {
        'GET /api/plannings/plan-1/fill-preview': ({ query }) =>
          query.get('period') === 'c1' ? COVERED : answer([plan({ action: 'GENERATE', generated: false })]),
      },
      '/plannings/plan-1?periode=1',
    )

    await screen.findByRole('region', { name: 'Périodes du planning' })
    await waitFor(() => expect(within(slot()).getByRole('status')).toHaveTextContent('Période complète'))
    expect(slots()).toHaveLength(1)
    expect(primaryButtons()).toHaveLength(0)
    const children = head().children.length
    const menu = menuEntries()
    expect(menu).toEqual(['Modifier le nom'])

    fireEvent.click(screen.getByRole('button', { name: 'Période suivante' }))

    // At once, before the analysis of the new period comes back: never two main buttons.
    expect(slots()).toHaveLength(1)
    expect(primaryButtons()).toHaveLength(1)
    expect(within(slot()).getByRole('button', { name: 'Générer la période 2' })).toBeInTheDocument()
    expect(within(slot()).queryByText('Période complète')).not.toBeInTheDocument()
    expect(head().children.length).toBe(children)

    await waitFor(() =>
      expect(
        api.requests('GET', '/api/plannings/plan-1/fill-preview').map((r) => r.query.get('period')),
      ).toContain('c2'),
    )
    expect(slots()).toHaveLength(1)
    expect(primaryButtons()).toHaveLength(1)
    expect(head().children.length).toBe(children)
    expect(menuEntries()).toEqual(menu)
    expect(
      screen.queryByRole('button', { name: /Générer le planning|postes non pourvus/ }),
    ).not.toBeInTheDocument()
  })

  it('an answer for the period just left never decides the action of this one', async () => {
    const held: Record<string, ((value: FillPreview) => void)[]> = { c1: [], c2: [] }
    let c1Immediate = true
    renderPage(
      () => planning({ periods: [GENERATED(1), { ...GENERATED(2), isLast: true }], periodCount: 2 }),
      {
        'GET /api/plannings/plan-1/fill-preview': ({ query }) => {
          const id = query.get('period') ?? ''
          if (id === 'c1' && c1Immediate) return COVERED
          return new Promise<FillPreview>((resolve) => held[id].push(resolve))
        },
      },
      '/plannings/plan-1?periode=1',
    )

    await screen.findByRole('region', { name: 'Périodes du planning' })
    await waitFor(() => expect(within(slot()).getByRole('status')).toHaveTextContent('Période complète'))

    // Period 2 is still being analysed: what is known of period 1 says nothing of it.
    fireEvent.click(screen.getByRole('button', { name: 'Période suivante' }))
    expect(within(slot()).getByRole('button', { name: 'Compléter la période 2' })).toBeInTheDocument()
    expect(screen.queryByText('Période complète')).not.toBeInTheDocument()
    await waitFor(() => expect(held.c2.length).toBeGreaterThan(0))

    // Its own answer decides.
    await act(async () => held.c2.forEach((resolve) => resolve(HOLES_PUBLISHED)))
    await waitFor(() =>
      expect(within(slot()).getByRole('status')).toHaveTextContent('2 gardes à attribuer à la main'),
    )

    // Back on period 1 then away again: period 2 keeps what it knew of itself, and an answer of period 1
    // arriving late changes nothing.
    c1Immediate = false
    fireEvent.click(screen.getByRole('button', { name: 'Période précédente' }))
    await waitFor(() => expect(held.c1.length).toBeGreaterThan(0))
    held.c2.length = 0
    fireEvent.click(screen.getByRole('button', { name: 'Période suivante' }))
    await waitFor(() => expect(held.c2.length).toBeGreaterThan(0))
    await act(async () => held.c1.forEach((resolve) => resolve(COVERED)))
    expect(screen.queryByText('Période complète')).not.toBeInTheDocument()
    expect(within(slot()).getByRole('status')).toHaveTextContent('2 gardes à attribuer à la main')

    await act(async () => held.c2.forEach((resolve) => resolve(HOLES_TO_COMPLETE)))
    expect(within(slot()).getByRole('button', { name: 'Compléter la période 2' })).toBeInTheDocument()
  })

  it('points to the main action from the empty "Gardes" tab, with no button of its own', async () => {
    renderPage(
      () => planning({ periods: [P1, NEVER_GENERATED], periodCount: 2 }),
      {},
      '/plannings/plan-1?periode=2',
    )

    await screen.findByRole('region', { name: 'Périodes du planning' })
    fireEvent.click(screen.getByRole('tab', { name: /Gardes/ }))
    const empty = screen.getByText('Pas encore de gardes pour la période 2').closest('div')!
    expect(within(empty).getByText(/Utilisez « Générer la période 2 » en haut de page/)).toBeInTheDocument()
    expect(within(empty).queryByRole('button')).not.toBeInTheDocument()
    expect(within(slot()).getByRole('button', { name: 'Générer la période 2' })).toBeInTheDocument()
  })
})

describe('PlanningDetailPage — creating the next period (lot L6)', () => {
  const P4 = period(
    4,
    '2027-05-01',
    '2027-08-01',
    '2027-07-31',
    'preparation',
    [
      ['sen', 'DRAFT', APPLICABLE],
      ['jun', 'DRAFT', INCOMPLETE],
      ['ren', 'DRAFT', NO_DEMAND],
    ],
    { timeState: 'future', isLast: true },
  )

  function openDialog() {
    fireEvent.click(screen.getAllByRole('button', { name: /Créer la période suivante/ })[0])
    return screen.getByRole('dialog', { name: 'Créer la période suivante' })
  }

  it('shows the dates without ambiguity, creates the period with the exclusive end and selects it', async () => {
    let detail = planning()
    const api = renderPage(() => detail, {
      'POST /api/plannings/plan-1/periods': () => {
        detail = planning({ periods: [P1, P2, { ...P3, isLast: false }, P4], periodCount: 4 })
        return httpStatus(201, { ...P4, historicalBlockCuts: [] })
      },
    })

    await screen.findByRole('region', { name: 'Périodes du planning' })
    const dialog = openDialog()
    expect(within(dialog).getByText('Sam. 1 mai 2027')).toBeInTheDocument()
    expect(within(dialog).getByText('Lendemain du dernier jour de la période 3')).toBeInTheDocument()
    expect(within(dialog).getByText('Dernier jour inclus : vendredi 30 avril 2027')).toBeInTheDocument()
    expect(
      within(dialog).getByText(
        /^Elle reste rattachée à .+ : \d+ lignes? et \d+ membres?, sans rien recréer\.$/,
      ),
    ).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: /3 mois/ }))
    expect(
      within(dialog).getByText(
        'Gardes jusqu’au samedi 31 juillet 2027 inclus. Une période suivante commencerait le dimanche 1 août 2027.',
      ),
    ).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer la période' }))

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/periods')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/periods')[0].body).toEqual({
      endsAt: '2027-08-01',
      previousPeriodStableId: 'c3',
    })
    await waitFor(() => expect(screen.getByTestId('search')).toHaveTextContent('?periode=4'))
    expect(screen.queryByRole('dialog', { name: 'Créer la période suivante' })).not.toBeInTheDocument()
    expect(within(selectedCard()).getByText(/Période 4/)).toBeInTheDocument()
    expect(screen.getByText(/Période 4 créée · 1 mai → 31 juil\. 2027/)).toBeInTheDocument()
  })

  it('asks for the explicit confirmation of a historical cut block (D192) before creating the period', async () => {
    let call = 0
    const api = renderPage(() => planning(), {
      'POST /api/plannings/plan-1/periods': () => {
        call += 1
        if (call === 1) {
          return httpStatus(409, {
            error: 'historical_block_cut_confirmation_required',
            message: '',
            details: [
              {
                lineStableId: 'sen',
                lineName: 'Seniors',
                blockName: 'Week-end',
                boundary: '2027-05-01',
                firstDay: '2027-04-30',
                lastDay: '2027-05-02',
                earlierDays: ['2027-04-30'],
                remainderDays: ['2027-05-01', '2027-05-02'],
              },
            ],
          })
        }
        return httpStatus(201, { ...P3, stableId: 'c4', number: 4, historicalBlockCuts: [] })
      },
    })

    await screen.findByRole('region', { name: 'Périodes du planning' })
    const dialog = openDialog()
    fireEvent.click(within(dialog).getByRole('button', { name: /3 mois/ }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer la période' }))

    const alert = await within(dialog).findByRole('alert')
    expect(alert).toHaveTextContent('Week-end · Seniors')
    expect(alert).toHaveTextContent(
      'les jours Sam. 1 mai, Dim. 2 mai deviennent la suite de ce bloc dans la période 4',
    )
    expect(alert).toHaveTextContent(
      'Je confirme : ces jours forment la suite du bloc dans la période 4 et seront attribués d’un seul tenant.',
    )
    const confirm = within(dialog).getByRole('button', { name: 'Confirmer et créer la période' })
    expect(confirm).toBeDisabled()
    fireEvent.click(within(alert).getByRole('checkbox'))
    expect(confirm).toBeEnabled()
    fireEvent.click(confirm)

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/periods')).toHaveLength(2))
    expect(api.requests('POST', '/api/plannings/plan-1/periods')[0].body).not.toHaveProperty(
      'acceptHistoricalBlockCuts',
    )
    expect(api.requests('POST', '/api/plannings/plan-1/periods')[1].body).toMatchObject({
      acceptHistoricalBlockCuts: true,
    })
  })

  it('explains a refused date, a period too short and a sequence changed meanwhile', async () => {
    const replies = [
      httpStatus(422, {
        error: 'period_boundary_splits_block',
        message: '',
        details: [
          {
            lineStableId: 'sen',
            lineName: 'Seniors',
            blockName: 'Week-end',
            boundary: '2027-08-01',
            firstDay: '2027-07-30',
            lastDay: '2027-08-01',
            earlierDays: [],
            remainderDays: [],
          },
        ],
      }),
      httpStatus(409, { error: 'job_in_progress', message: '' }),
      httpStatus(409, { error: 'period_sequence_changed', message: '' }),
    ]
    renderPage(() => planning(), { 'POST /api/plannings/plan-1/periods': () => replies.shift() })

    await screen.findByRole('region', { name: 'Périodes du planning' })
    const dialog = openDialog()
    fireEvent.click(within(dialog).getByRole('button', { name: /3 mois/ }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer la période' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('couperait un bloc de gardes en deux')

    fireEvent.click(within(dialog).getByRole('button', { name: /4 mois/ }))
    expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer la période' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Une génération ou une complétion est en cours',
    )

    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer la période' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Les périodes du planning ont changé')
    expect(within(dialog).getByRole('button', { name: 'Créer la période' })).toBeDisabled()
    expect(within(dialog).getByRole('button', { name: 'Recharger les périodes' })).toBeInTheDocument()

    fireEvent.change(within(dialog).getByLabelText('Dernier jour (inclus)'), {
      target: { value: '2027-05-03' },
    })
    expect(within(dialog).getByText('La fin doit être au moins 7 jours après le début.')).toBeInTheDocument()
  })

  it('offers no creation at all while the backend has it switched off', async () => {
    renderPage(
      () => planning({ canCreatePeriod: false, canOpenPeriodCollection: false }),
      {},
      '/plannings/plan-1?periode=3',
    )

    const steps = await screen.findByRole('region', { name: 'Préparation de la période 3' })
    expect(screen.queryByRole('button', { name: /Créer la période suivante/ })).not.toBeInTheDocument()
    expect(within(steps).queryByRole('button', { name: /Ouvrir la collecte/ })).not.toBeInTheDocument()
  })
})

/** docs/decisions.md D207: the server's options — a last day on a Friday or a Saturday cuts the weekend block. */
function dateOptions(query: URLSearchParams, historical = false) {
  const days = []
  for (let day = query.get('from') ?? ''; day <= (query.get('to') ?? ''); day = addDays(day, 1)) {
    const weekday = new Date(`${day}T00:00:00Z`).getUTCDay()
    const reasons =
      weekday === 5 || weekday === 6
        ? [{ code: 'period_boundary_splits_block', cuts: [{ ...WEEKEND, boundary: addDays(day, 1) }] }]
        : []
    days.push({ date: day, allowed: reasons.length === 0, reasons })
  }
  return {
    period: query.get('period') === 'next' ? null : { stableId: query.get('period'), number: 3 },
    edge: query.get('edge'),
    fixedStart: query.get('period') === 'next' ? '2027-05-01' : '2027-01-31',
    current: query.get('period') === 'next' ? null : '2027-04-30',
    editable: true,
    blockers: [],
    historicalStartCuts: historical
      ? [
          {
            ...WEEKEND,
            boundary: '2027-05-01',
            earlierDays: ['2027-04-30'],
            remainderDays: ['2027-05-01', '2027-05-02'],
          },
        ]
      : [],
    days,
  }
}

const WEEKEND = {
  lineStableId: 'sen',
  lineName: 'Seniors',
  blockName: 'Week-end',
  boundary: '',
  firstDay: '2027-04-30',
  lastDay: '2027-05-02',
  earlierDays: [],
  remainderDays: [],
}

/** docs/decisions.md D225: new dates that touch no calendar. */
const NOTHING_TO_CONFIRM = {
  allowed: true,
  reasons: [],
  changes: [],
  retiredDutyCount: 0,
  retiredAssignedCount: 0,
  retiredPublishedCount: 0,
  retiredUnits: [],
  holders: [],
  additions: [],
  collections: [],
  swapRequestCount: 0,
  confirmationRequired: false,
  republishPeriods: [],
  recipients: [],
  canNotify: false,
  notifyBlock: null,
  pendingChanges: 0,
  impactHash: 'hash-0',
}

/** The answer of PATCH period: the period, and what its new dates did. */
const saved = (overrides = {}) => ({
  ...P3,
  endsAt: '2027-04-05',
  lastDay: '2027-04-04',
  generationOutdated: false,
  addedDutyCount: 0,
  retiredDutyCount: 0,
  republished: 0,
  ...overrides,
})

describe('PlanningDetailPage — choosing the dates of a period on a calendar (D207)', () => {
  it('creates the next period on a possible day, after confirming the cut weekend told upfront', async () => {
    const api = renderPage(() => planning(), {
      'GET /api/plannings/plan-1/periods/date-options': ({ query }) => dateOptions(query, true),
      'POST /api/plannings/plan-1/periods': () =>
        httpStatus(201, { ...P3, stableId: 'c4', number: 4, historicalBlockCuts: [] }),
    })

    await screen.findByRole('region', { name: 'Périodes du planning' })
    fireEvent.click(screen.getAllByRole('button', { name: /Créer la période suivante/ })[0])
    const dialog = screen.getByRole('dialog', { name: 'Créer la période suivante' })
    expect(await within(dialog).findByText(/coupe un bloc de gardes :/)).toBeInTheDocument()
    expect(
      within(dialog).getByText(
        /les jours Sam\. 1 mai, Dim\. 2 mai deviennent la suite de ce bloc dans la période 4/,
      ),
    ).toBeInTheDocument()
    expect(api.requests('GET', '/api/plannings/plan-1/periods/date-options')[0].query.get('period')).toBe(
      'next',
    )

    fireEvent.click(
      await within(dialog).findByRole('button', { name: /^vendredi 30 juillet 2027, impossible/ }),
    )
    expect(
      within(dialog).getByText(/^Impossible : Couperait le bloc « Week-end » \(Seniors\)/),
    ).toBeInTheDocument()
    const create = within(dialog).getByRole('button', { name: 'Confirmer et créer la période' })
    expect(create).toBeDisabled()

    fireEvent.click(within(dialog).getByRole('button', { name: /^jeudi 29 juillet 2027, possible/ }))
    expect(create).toBeDisabled()
    fireEvent.click(within(dialog).getByRole('checkbox'))
    expect(create).toBeEnabled()
    fireEvent.click(create)

    await waitFor(() => expect(api.requests('POST', '/api/plannings/plan-1/periods')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings/plan-1/periods')[0].body).toEqual({
      endsAt: '2027-07-30',
      previousPeriodStableId: 'c3',
      acceptHistoricalBlockCuts: true,
    })
  })

  it('changes the end of a period from its card, with the exclusive end and the date it replaces', async () => {
    const api = renderPage(
      () => planning(),
      {
        'GET /api/plannings/plan-1/periods/date-options': ({ query }) => dateOptions(query),
        'GET /api/plannings/plan-1/periods/c3/date-change-preview': () => NOTHING_TO_CONFIRM,
        'PATCH /api/plannings/plan-1/periods/c3': () => saved(),
      },
      '/plannings/plan-1?periode=3',
    )

    await screen.findByRole('region', { name: 'Périodes du planning' })
    fireEvent.click(within(periodActions()).getByRole('button', { name: /Modifier les dates/ }))
    const dialog = screen.getByRole('dialog', { name: 'Modifier les dates' })
    expect(
      within(dialog).getByText('Le lendemain du dernier jour de la période 2 : les deux bougent ensemble.'),
    ).toBeInTheDocument()
    fireEvent.click(await within(dialog).findByRole('button', { name: /^samedi 3 avril 2027, impossible/ }))
    expect(within(dialog).getByRole('button', { name: 'Enregistrer les dates' })).toBeDisabled()
    fireEvent.click(within(dialog).getByRole('button', { name: /^dimanche 4 avril 2027, possible/ }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer les dates' }))

    await waitFor(() => expect(api.requests('PATCH', '/api/plannings/plan-1/periods/c3')).toHaveLength(1))
    expect(api.requests('PATCH', '/api/plannings/plan-1/periods/c3')[0].body).toEqual({
      endsAt: '2027-04-05',
      expectedEndsAt: '2027-05-01',
    })
    expect(await screen.findByText('Dates de la période 3 enregistrées')).toBeInTheDocument()
    expect(screen.queryByRole('dialog', { name: 'Modifier les dates' })).not.toBeInTheDocument()
  })

  it('confirms new dates that touch a calendar: the plan read and the choice to tell go with the request (D225)', async () => {
    const api = renderPage(
      () => planning(),
      {
        'GET /api/plannings/plan-1/periods/date-options': ({ query }) => dateOptions(query),
        'GET /api/plannings/plan-1/periods/c3/date-change-preview': () => ({
          ...NOTHING_TO_CONFIRM,
          confirmationRequired: true,
          impactHash: 'hash-c3',
          canNotify: true,
          recipients: ['Alice Bernard'],
        }),
        'PATCH /api/plannings/plan-1/periods/c3': () => saved({ retiredDutyCount: 2, republished: 1 }),
      },
      '/plannings/plan-1?periode=3',
    )

    await screen.findByRole('region', { name: 'Périodes du planning' })
    fireEvent.click(within(periodActions()).getByRole('button', { name: /Modifier les dates/ }))
    const dialog = screen.getByRole('dialog', { name: 'Modifier les dates' })
    fireEvent.click(await within(dialog).findByRole('button', { name: /^dimanche 4 avril 2027, possible/ }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer les dates' }))
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Confirmer et avertir' }))

    await waitFor(() => expect(api.requests('PATCH', '/api/plannings/plan-1/periods/c3')).toHaveLength(1))
    expect(api.requests('PATCH', '/api/plannings/plan-1/periods/c3')[0].body).toEqual({
      endsAt: '2027-04-05',
      expectedEndsAt: '2027-05-01',
      confirmation: 'hash-c3',
      notify: true,
    })
    expect(
      api.requests('GET', '/api/plannings/plan-1/periods/c3/date-change-preview')[0].query.get('endsAt'),
    ).toBe('2027-04-05')
    expect(
      await screen.findByText(
        'Dates enregistrées · planning republié, les personnes concernées sont averties',
      ),
    ).toBeInTheDocument()
  })

  it('says new duties are waiting, and how to fill them, once the dates are saved', async () => {
    renderPage(
      () => planning(),
      {
        'GET /api/plannings/plan-1/periods/date-options': ({ query }) => dateOptions(query),
        'GET /api/plannings/plan-1/periods/c3/date-change-preview': () => NOTHING_TO_CONFIRM,
        'PATCH /api/plannings/plan-1/periods/c3': () => saved({ addedDutyCount: 14 }),
      },
      '/plannings/plan-1?periode=3',
    )

    await screen.findByRole('region', { name: 'Périodes du planning' })
    fireEvent.click(within(periodActions()).getByRole('button', { name: /Modifier les dates/ }))
    const dialog = screen.getByRole('dialog', { name: 'Modifier les dates' })
    fireEvent.click(await within(dialog).findByRole('button', { name: /^dimanche 4 avril 2027, possible/ }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer les dates' }))

    expect(
      await screen.findByText(
        'Dates enregistrées · 14 nouvelles gardes à attribuer : utilisez « Compléter la période 3 »',
      ),
    ).toBeInTheDocument()
  })

  it('has a single way to change dates: no end correction, whatever the period', async () => {
    renderPage(() => planning(), {}, '/plannings/plan-1?periode=2')
    await screen.findByRole('region', { name: 'Préparation de la période 2' })
    expect(within(periodActions()).getByRole('button', { name: /Modifier les dates/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Corriger la fin/ })).not.toBeInTheDocument()
  })

  it('offers no date change to someone who cannot manage the periods', async () => {
    renderPage(() => planning({ canCreatePeriod: false }), {}, '/plannings/plan-1?periode=3')
    await screen.findByRole('region', { name: 'Préparation de la période 3' })
    expect(screen.queryByRole('button', { name: /Modifier les dates/ })).not.toBeInTheDocument()
  })
})
