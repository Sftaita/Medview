import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../../lib/apiClient'
import { stubApi, type Route } from '../../../testUtils/stubApi'
import type { PlanningResultDuty, PlanningResultLine, PublicationState } from '../result/types'
import type { PlanningDetail } from '../types'
import { PlanningCalendar } from './PlanningCalendar'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-02T10:00:00Z'))
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const PLANNING: PlanningDetail = {
  stableId: 'plan-1',
  name: 'Gardes',
  creatorStableId: 'u0',
  canManage: false,
  startsAt: '2026-10-01',
  endsAt: '2027-01-01',
  timezone: 'Europe/Brussels',
  createdAt: '',
  updatedAt: '',
  lines: [],
}

const MANAGER = { ...PLANNING, canManageCalendar: true, canPublish: true }

function duty(
  id: string,
  date: string,
  who: string | null,
  overrides: Partial<PlanningResultDuty> = {},
): PlanningResultDuty {
  const [firstName, lastName] = (who ?? ' ').split(' ')
  return {
    dutyStableId: id,
    date,
    startsAt: `${date}T08:00:00+02:00`,
    endsAt: `${date}T20:00:00+02:00`,
    timezone: 'Europe/Brussels',
    dutyType: { stableId: 't', code: 'G', name: 'Garde' },
    required: true,
    grouped: false,
    groupInstanceStableId: null,
    groupLabel: null,
    groupDates: null,
    covered: who !== null,
    assignment: who
      ? {
          stableId: `a-${id}`,
          source: 'AUTO',
          locked: false,
          teamMemberStableId: `m-${firstName}`,
          user: { stableId: `u-${firstName}`, firstName, lastName },
        }
      : null,
    reasons: [],
    demand: null,
    ...overrides,
  }
}

const BLOCK = {
  groupInstanceStableId: 'b1',
  groupLabel: 'Week-end',
  groupDates: ['2026-10-09', '2026-10-10', '2026-10-11'],
  grouped: true,
}

function line(id: string, name: string, duties: PlanningResultDuty[]): PlanningResultLine {
  return {
    lineStableId: id,
    lineName: name,
    lineType: id === 'l1' ? 'PRIMARY' : 'SECONDARY',
    generationStableId: `g-${id}`,
    generatedAt: null,
    coverageStatus: 'COMPLETE',
    requiredDutyCount: duties.length,
    coveredRequiredDutyCount: duties.filter((d) => d.covered).length,
    uncoveredRequiredDutyCount: duties.filter((d) => !d.covered).length,
    undeterminedDutyCount: 0,
    superfluousDutyCount: 0,
    notRequiredDutyCount: 0,
    duties,
  }
}

const RESULT = {
  planningStableId: 'plan-1',
  lines: [
    line('l1', 'Ligne principale', [
      duty('p5', '2026-10-05', 'Anne Dupont'),
      duty('p6', '2026-10-06', null),
      duty('p9', '2026-10-09', 'Anne Dupont', BLOCK),
      duty('p10', '2026-10-10', 'Anne Dupont', BLOCK),
      duty('p11', '2026-10-11', 'Anne Dupont', BLOCK),
    ]),
    line('l2', 'Ligne secondaire', [duty('s5', '2026-10-05', 'Xavier Durand')]),
  ],
}

const NOT_PUBLISHED: PublicationState = {
  published: false,
  firstPublishedAt: null,
  lastPublishedAt: null,
  lastPublishedBy: null,
  hasUnpublishedChanges: false,
  changes: [],
  history: [],
}

function setup(
  state: PublicationState = NOT_PUBLISHED,
  extra: Record<string, Route> = {},
  result: unknown = RESULT,
) {
  return stubApi({
    'GET /api/plannings/plan-1/result': () => result,
    'GET /api/plannings/plan-1/publication-state': () => state,
    ...extra,
  })
}

describe('PlanningCalendar', () => {
  it('lays out dates vertically with one column per line, weeks, blocks and uncovered duties', async () => {
    setup()
    render(<PlanningCalendar planning={PLANNING} />)

    const table = await screen.findByRole('table')
    expect(within(table).getByRole('columnheader', { name: 'Ligne principale' })).toBeInTheDocument()
    expect(within(table).getByRole('columnheader', { name: 'Ligne secondaire' })).toBeInTheDocument()
    expect(screen.getByText('Octobre 2026')).toBeInTheDocument()
    expect(within(table).getByText('Semaine du 5 octobre')).toBeInTheDocument()

    const monday = within(table)
      .getByRole('rowheader', { name: /05\/10/ })
      .closest('tr')!
    expect(within(monday).getByText('Anne Dupont')).toBeInTheDocument()
    expect(within(monday).getByText('Xavier Durand')).toBeInTheDocument()

    const tuesday = within(table)
      .getByRole('rowheader', { name: /06\/10/ })
      .closest('tr')!
    expect(within(tuesday).getByText('⚠ Non attribué')).toBeInTheDocument()

    // The block: labelled once, on its first day, every day drawn as part of it.
    expect(within(table).getAllByText('Bloc Week-end')).toHaveLength(1)
    const friday = within(table)
      .getByRole('rowheader', { name: /09\/10/ })
      .closest('tr')!
    const sunday = within(table)
      .getByRole('rowheader', { name: /11\/10/ })
      .closest('tr')!
    expect(friday.querySelector('.cal-block--first')).not.toBeNull()
    expect(sunday.querySelector('.cal-block--last')).not.toBeNull()
    expect(screen.getByText('⚠ 1 garde non attribuée')).toBeInTheDocument()
  })

  it('navigates month by month inside the planning', async () => {
    setup()
    render(<PlanningCalendar planning={PLANNING} />)

    await screen.findByText('Octobre 2026')
    expect(screen.getByRole('button', { name: 'Mois précédent' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))
    expect(screen.getByText('Novembre 2026')).toBeInTheDocument()
  })

  it('is read-only for a member: no edit, no completion, no publication', async () => {
    setup()
    render(<PlanningCalendar planning={PLANNING} />)

    await screen.findByRole('table')
    expect(screen.queryByRole('button', { name: /Modifier :/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Compléter automatiquement' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Statistiques' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Publier le planning' })).not.toBeInTheDocument()
  })

  it('opens the editor of a duty for a manager — any day of a block opens the block', async () => {
    setup(NOT_PUBLISHED, {
      'GET /api/plannings/plan-1/duties/p10/reassignment-candidates': () => ({
        groupInstanceStableId: 'b1',
        groupLabel: 'Week-end',
        blockDuties: [
          { dutyStableId: 'p9', date: '2026-10-09', startsAt: '', endsAt: '', dutyTypeName: 'Garde' },
          { dutyStableId: 'p10', date: '2026-10-10', startsAt: '', endsAt: '', dutyTypeName: 'Garde' },
          { dutyStableId: 'p11', date: '2026-10-11', startsAt: '', endsAt: '', dutyTypeName: 'Garde' },
        ],
        generationStableId: 'g-l1',
        currentTeamMemberStableId: 'm-Anne',
        currentAssignee: { teamMemberStableId: 'm-Anne', firstName: 'Anne', lastName: 'Dupont' },
        candidates: [],
      }),
    })
    render(<PlanningCalendar planning={MANAGER} />)

    fireEvent.click(
      await screen.findByRole('button', { name: /Modifier : Anne Dupont — bloc Week-end, 2026-10-10/ }),
    )
    expect(await screen.findByText('Modifier le bloc de garde')).toBeInTheDocument()
  })

  it('requests the completion from the page, which follows the queued job (docs/decisions.md D149)', async () => {
    setup()
    let accept: () => void = () => {}
    const onRequestCompletion = vi.fn(() => new Promise<void>((resolve) => (accept = resolve)))
    render(<PlanningCalendar planning={MANAGER} onRequestCompletion={onRequestCompletion} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Compléter automatiquement' }))
    expect(onRequestCompletion).toHaveBeenCalledTimes(1)
    expect(await screen.findByRole('button', { name: 'Envoi…' })).toBeDisabled()
    accept()
    expect(await screen.findByRole('button', { name: 'Compléter automatiquement' })).toBeEnabled()
  })

  it('says so when a completion cannot be requested because a job is already running', async () => {
    setup()
    const onRequestCompletion = vi.fn(() =>
      Promise.reject(new ApiError(409, { error: 'job_in_progress' }, 'x')),
    )
    render(<PlanningCalendar planning={MANAGER} onRequestCompletion={onRequestCompletion} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Compléter automatiquement' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Une génération ou une complétion est déjà en cours',
    )
  })

  it('disables completion and publication while a job runs', async () => {
    setup({
      ...NOT_PUBLISHED,
      published: true,
      lastPublishedAt: '2026-10-01T08:00:00+00:00',
      hasUnpublishedChanges: true,
      changes: [],
    })
    render(<PlanningCalendar planning={MANAGER} onRequestCompletion={vi.fn()} jobActive />)

    expect(await screen.findByRole('button', { name: 'Compléter automatiquement' })).toBeDisabled()
    // The publication state arrives on its own request: wait for it, never assume it came first.
    expect(await screen.findByRole('button', { name: 'Republier les modifications' })).toBeDisabled()
  })

  it('disables "Compléter automatiquement" when nothing is uncovered', async () => {
    setup(
      NOT_PUBLISHED,
      {},
      {
        planningStableId: 'plan-1',
        lines: [line('l1', 'Ligne principale', [duty('p5', '2026-10-05', 'Anne Dupont')])],
      },
    )
    render(<PlanningCalendar planning={MANAGER} />)

    await screen.findByRole('table')
    expect(screen.getByRole('button', { name: 'Compléter automatiquement' })).toBeDisabled()
  })

  it('offers "Publier le planning" before the first publication', async () => {
    setup()
    render(<PlanningCalendar planning={MANAGER} />)

    expect(await screen.findByText('Non publié')).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Publier le planning' })).toBeEnabled()
    expect(screen.queryByRole('button', { name: 'PDF de la dernière diffusion' })).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Exporter' }),
      'Nothing to export before a publication.',
    ).not.toBeInTheDocument()
  })

  it('once published: shows the date, the PDF, and a disabled "Republier" while nothing changed', async () => {
    setup({
      ...NOT_PUBLISHED,
      published: true,
      firstPublishedAt: '2026-10-01T08:00:00+00:00',
      lastPublishedAt: '2026-10-01T08:00:00+00:00',
    })
    render(<PlanningCalendar planning={MANAGER} />)

    expect(await screen.findByText('Planning publié')).toBeInTheDocument()
    expect(screen.getByText('Dernière diffusion le 1 octobre 2026 à 10:00')).toBeInTheDocument()
    // Two different documents, never confused: the frozen diffusion and the calendar as it is now.
    expect(screen.getByRole('button', { name: 'PDF de la dernière diffusion' })).toHaveAttribute(
      'title',
      'Le planning tel qu’il a été diffusé, sans les modifications faites depuis',
    )
    expect(screen.getByRole('button', { name: 'Exporter' })).toHaveAttribute(
      'title',
      'PDF ou Excel du calendrier tel qu’il est maintenant',
    )
    expect(screen.getByRole('button', { name: 'Republier les modifications' })).toBeDisabled()
    expect(screen.queryByText(/Modifications non publiées/)).not.toBeInTheDocument()
  })

  it('shows "Modifications non publiées" and opens the republication with the changes', async () => {
    setup(
      {
        ...NOT_PUBLISHED,
        published: true,
        lastPublishedAt: '2026-10-01T08:00:00+00:00',
        hasUnpublishedChanges: true,
        changes: [
          {
            dutyStableId: 'p6',
            date: '2026-10-06',
            lineStableId: 'l1',
            lineName: 'Ligne principale',
            groupInstanceStableId: null,
            before: { firstName: 'Anne', lastName: 'Dupont' },
            after: { firstName: 'Bruno', lastName: 'Martin' },
            beforeShown: true,
            afterShown: true,
          },
        ],
      },
      {
        'GET /api/plannings/plan-1/publication-preflight': () => ({
          publishable: true,
          republishable: true,
          lines: [],
          uncoveredDuties: [],
          inconsistentGroups: [],
          invalidAssignments: [],
          conflicts: [],
        }),
      },
    )
    render(<PlanningCalendar planning={MANAGER} />)

    expect(await screen.findByText('Modifications non publiées (1)')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Republier les modifications' }))
    expect(await screen.findByText('Republier les modifications ?')).toBeInTheDocument()
    const changes = screen.getByRole('list', { name: 'Modifications non publiées' })
    expect(within(changes).getByText('Bruno Martin')).toBeInTheDocument()
    expect(within(changes).getByText(/mardi 6 octobre · Ligne principale/)).toBeInTheDocument()
  })

  it('announces a removed reinforcement nobody needs any more as "Pas de renfort", never "Non attribué" (D166)', async () => {
    setup(
      {
        ...NOT_PUBLISHED,
        published: true,
        lastPublishedAt: '2026-10-01T08:00:00+00:00',
        hasUnpublishedChanges: true,
        changes: [
          {
            dutyStableId: 'r6',
            date: '2026-10-06',
            lineStableId: 'l2',
            lineName: 'Renfort',
            groupInstanceStableId: null,
            before: { firstName: 'Carol', lastName: 'Dubois' },
            after: null,
            beforeShown: true,
            afterShown: false,
          },
        ],
      },
      {
        'GET /api/plannings/plan-1/publication-preflight': () => ({
          publishable: true,
          republishable: true,
          lines: [],
          uncoveredDuties: [],
          inconsistentGroups: [],
          invalidAssignments: [],
          conflicts: [],
          undeterminedDuties: [],
          superfluousCoverages: [],
        }),
      },
    )
    render(<PlanningCalendar planning={MANAGER} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Republier les modifications' }))
    const changes = await screen.findByRole('list', { name: 'Modifications non publiées' })
    expect(within(changes).getByText('Pas de renfort')).toBeInTheDocument()
    expect(within(changes).queryByText('Non attribué')).not.toBeInTheDocument()
  })

  it('a member sees the publication status and can download the PDF, never republish', async () => {
    setup({
      published: true,
      firstPublishedAt: '2026-10-01T08:00:00+00:00',
      lastPublishedAt: '2026-10-01T08:00:00+00:00',
      lastPublishedBy: null,
    })
    render(<PlanningCalendar planning={PLANNING} />)

    expect(await screen.findByText('Planning publié')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'PDF de la dernière diffusion' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Republier les modifications' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Exporter' }))
    expect(await screen.findByRole('dialog', { name: 'Exporter le planning' })).toBeInTheDocument()
  })
})
