import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../../../testUtils/stubApi'
import { StatisticsPanel } from './StatisticsPanel'
import type { PlanningStatistics, StatisticsMemberRow, StatisticsScope } from './types'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function row(overrides: Partial<StatisticsMemberRow> = {}): StatisticsMemberRow {
  return {
    teamMemberStableId: 'm1',
    firstName: 'Alice',
    lastName: 'Martin',
    countsByWeekday: { MON: 0, TUE: 1, WED: 0, THU: 0, FRI: 0, SAT: 1, SUN: 1 },
    countsByFamily: { '': 1, 'Week-end': 2 },
    total: 3,
    countsByDutyType: { Garde: 3 },
    weightedLoad: 3,
    ...overrides,
  }
}

function scope(overrides: Partial<StatisticsScope> = {}): StatisticsScope {
  return {
    startsAt: '2027-01-01',
    endsAt: '2027-05-01',
    groups: [{ groupStableId: 'l1', groupLabel: 'Seniors', members: [row()] }],
    ...overrides,
  }
}

function statistics(overrides: Partial<PlanningStatistics> = {}): PlanningStatistics {
  return {
    currentPeriod: scope(),
    cumulative: scope(),
    ...overrides,
  }
}

function stubStatistics(statisticsResponses: PlanningStatistics[]) {
  let call = 0
  return stubApi({
    'GET /api/plannings/plan-1/statistics': () =>
      statisticsResponses[Math.min(call++, statisticsResponses.length - 1)],
  })
}

describe('StatisticsPanel', () => {
  it('defaults to "Cette période" and shows the real weekday counts and total', async () => {
    stubStatistics([statistics()])
    render(<StatisticsPanel planningStableId="plan-1" refreshKey={0} />)

    const tab = await screen.findByRole('tab', { name: 'Cette période' })
    expect(tab).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tab', { name: 'Cumul publié' })).toHaveAttribute('aria-selected', 'false')

    expect(screen.getByText('Alice Martin')).toBeInTheDocument()
    const row = screen.getByText('Alice Martin').closest('tr')!
    expect(row).toHaveTextContent('3') // total
  })

  it('labels a provisional line and never calls an empty published cumulative "not generated" (D201)', async () => {
    stubStatistics([
      statistics({
        currentPeriod: scope({
          groups: [
            {
              groupStableId: 'l1',
              groupLabel: 'Seniors',
              members: [row()],
              publication: 'UNPUBLISHED',
              provisional: true,
            },
          ],
        }),
        cumulative: scope({ groups: [] }),
      }),
    ])
    render(<StatisticsPanel planningStableId="plan-1" refreshKey={0} />)

    expect(
      await screen.findByRole('heading', { name: 'Seniors — provisoire (non publiée)' }),
    ).toBeInTheDocument()
    fireEvent.click(screen.getByRole('tab', { name: 'Cumul publié' }))
    expect(screen.getByText('Aucune garde publiée pour le moment.')).toBeInTheDocument()
    expect(screen.queryByText('Aucune garde générée pour le moment.')).not.toBeInTheDocument()
  })

  it('shows the exact bounds actually covered by the scope, the exclusive end as the last day included', async () => {
    stubStatistics([statistics({ currentPeriod: scope({ startsAt: '2027-01-01', endsAt: '2027-03-01' }) })])
    render(<StatisticsPanel planningStableId="plan-1" refreshKey={0} />)

    expect(await screen.findByText(/1 janvier 2027.*28 février 2027/)).toBeInTheDocument()
  })

  it('switches to "Cumul publié" and shows its own distinct values, with an older member visible only there', async () => {
    stubStatistics([
      statistics({
        currentPeriod: scope({
          groups: [{ groupStableId: 'l1', groupLabel: 'Seniors', members: [row({ total: 3 })] }],
        }),
        cumulative: scope({
          startsAt: '2027-01-01',
          endsAt: '2027-05-01',
          groups: [
            {
              groupStableId: 'l1',
              groupLabel: 'Seniors',
              members: [
                row({ total: 3 }),
                row({ teamMemberStableId: 'm2', firstName: 'Bob', lastName: 'Durand', total: 9 }),
              ],
            },
          ],
        }),
      }),
    ])
    render(<StatisticsPanel planningStableId="plan-1" refreshKey={0} />)

    await screen.findByText('Alice Martin')
    expect(screen.queryByText('Bob Durand')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: 'Cumul publié' }))

    expect(await screen.findByText('Bob Durand')).toBeInTheDocument()
    const bobRow = screen.getByText('Bob Durand').closest('tr')!
    expect(bobRow).toHaveTextContent('9')
  })

  it('renders several groups (lines) with their own labelled table, never mixed', async () => {
    stubStatistics([
      statistics({
        currentPeriod: scope({
          groups: [
            { groupStableId: 'l1', groupLabel: 'Seniors', members: [row()] },
            {
              groupStableId: 'l2',
              groupLabel: 'Renfort',
              members: [row({ teamMemberStableId: 'm2', firstName: 'Bob', lastName: 'Durand', total: 5 })],
            },
          ],
        }),
      }),
    ])
    render(<StatisticsPanel planningStableId="plan-1" refreshKey={0} />)

    expect(await screen.findByText('Seniors')).toBeInTheDocument()
    expect(screen.getByText('Renfort')).toBeInTheDocument()
    expect(screen.getByText('Alice Martin')).toBeInTheDocument()
    expect(screen.getByText('Bob Durand')).toBeInTheDocument()
  })

  it('refetches when refreshKey changes (a reassignment was saved)', async () => {
    const api = stubStatistics([statistics(), statistics()])
    const { rerender } = render(<StatisticsPanel planningStableId="plan-1" refreshKey={0} />)

    await screen.findByText('Alice Martin')
    expect(api.requests('GET', '/api/plannings/plan-1/statistics')).toHaveLength(1)

    rerender(<StatisticsPanel planningStableId="plan-1" refreshKey={1} />)

    await waitFor(() => {
      expect(api.requests('GET', '/api/plannings/plan-1/statistics')).toHaveLength(2)
    })
  })

  it('says so plainly when nothing has been generated yet', async () => {
    stubStatistics([statistics({ currentPeriod: scope({ groups: [] }) })])
    render(<StatisticsPanel planningStableId="plan-1" refreshKey={0} />)

    expect(await screen.findByText('Aucune garde générée pour le moment.')).toBeInTheDocument()
  })

  it('shows real, dynamic family columns — never a hardcoded name (docs/decisions.md D137)', async () => {
    stubStatistics([statistics()])
    render(<StatisticsPanel planningStableId="plan-1" refreshKey={0} />)

    expect(await screen.findByRole('columnheader', { name: 'Week-end' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Sans famille' })).toBeInTheDocument()
    const aliceRow = screen.getByText('Alice Martin').closest('tr')!
    expect(within(aliceRow).getByText('2')).toBeInTheDocument()
    expect(within(aliceRow).getAllByText('1')).not.toHaveLength(0)
  })

  // --- lot L6 (docs/decisions.md D201) -----------------------------------------------------------

  function periodView(stableId: string, number: number): NonNullable<PlanningStatistics['period']> {
    return {
      stableId,
      number,
      startsAt: '2027-05-01',
      endsAt: '2027-08-01',
      lines: [
        { lineStableId: 'l1', lineName: 'Seniors', publication: 'PUBLISHED' },
        { lineStableId: 'l2', lineName: 'Juniors', publication: 'UNPUBLISHED' },
      ],
    }
  }

  it('sends the selected period and names the three views for a manager, the period numbers of each sum', async () => {
    const api = stubStatistics([
      statistics({
        period: periodView('c2', 2),
        currentPeriod: scope({ periodNumbers: [2], throughPeriodNumber: 2 }),
        cumulative: scope({
          periodNumbers: [1, 2],
          throughPeriodNumber: 2,
          groups: [{ groupStableId: 'l1', groupLabel: 'Seniors', members: [row({ total: 7 })] }],
        }),
        provisional: scope({
          periodNumbers: [2],
          groups: [
            {
              groupStableId: 'l2',
              groupLabel: 'Juniors',
              provisional: true,
              members: [row({ teamMemberStableId: 'm9', firstName: 'Zoé', lastName: 'Petit', total: 4 })],
            },
          ],
        }),
      }),
    ])
    render(<StatisticsPanel planningStableId="plan-1" periodStableId="c2" refreshKey={0} />)

    expect(await screen.findByRole('tab', { name: 'Période 2' })).toHaveAttribute('aria-selected', 'true')
    expect(api.requests('GET', '/api/plannings/plan-1/statistics')[0].query.get('period')).toBe('c2')
    expect(screen.getByText('Seniors : publiée')).toBeInTheDocument()
    expect(screen.getByText('Juniors : non publiée')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: 'Cumul publié' }))
    expect(screen.getByText(/périodes 1, 2 incluses/)).toBeInTheDocument()
    expect(screen.getByText(/affectations opérationnelles actuelles des lignes publiées/)).toBeInTheDocument()
    expect(screen.getByText('7')).toBeInTheDocument()
    expect(screen.queryByText('Zoé Petit')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: 'En préparation' }))
    expect(screen.getByText('Zoé Petit')).toBeInTheDocument()
    expect(screen.getByText(/chiffres provisoires, jamais ajoutés au cumul publié/)).toBeInTheDocument()
    expect(screen.getByText(/période 2 incluse/)).toBeInTheDocument()
  })

  it('never offers "En préparation" to a member (null), nor when nothing is provisional', async () => {
    stubStatistics([statistics({ period: periodView('c2', 2), provisional: null })])
    const { unmount } = render(
      <StatisticsPanel planningStableId="plan-1" periodStableId="c2" refreshKey={0} />,
    )
    await screen.findByRole('tab', { name: 'Période 2' })
    expect(screen.getAllByRole('tab')).toHaveLength(2)
    expect(screen.queryByRole('tab', { name: 'En préparation' })).not.toBeInTheDocument()
    unmount()

    stubStatistics([statistics({ period: periodView('c2', 2), provisional: scope({ groups: [] }) })])
    render(<StatisticsPanel planningStableId="plan-1" periodStableId="c2" refreshKey={0} />)
    await screen.findByRole('tab', { name: 'Période 2' })
    expect(screen.queryByRole('tab', { name: 'En préparation' })).not.toBeInTheDocument()
  })

  it('drops a late answer for a period no longer selected', async () => {
    let releaseFirst: (value: PlanningStatistics) => void = () => {}
    stubApi({
      'GET /api/plannings/plan-1/statistics': ({ query }) =>
        query.get('period') === 'c1'
          ? new Promise<PlanningStatistics>((resolve) => {
              releaseFirst = resolve
            })
          : statistics({
              period: periodView('c2', 2),
              currentPeriod: scope({
                groups: [
                  { groupStableId: 'l1', groupLabel: 'Seniors', members: [row({ firstName: 'Deux' })] },
                ],
              }),
            }),
    })
    const { rerender } = render(
      <StatisticsPanel planningStableId="plan-1" periodStableId="c1" refreshKey={0} />,
    )
    rerender(<StatisticsPanel planningStableId="plan-1" periodStableId="c2" refreshKey={0} />)
    expect(await screen.findByText('Deux Martin')).toBeInTheDocument()

    releaseFirst(
      statistics({
        period: { ...periodView('c1', 1) },
        currentPeriod: scope({
          groups: [{ groupStableId: 'l1', groupLabel: 'Seniors', members: [row({ firstName: 'Un' })] }],
        }),
      }),
    )
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByText('Un Martin')).not.toBeInTheDocument()
    expect(screen.getByText('Deux Martin')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Période 2' })).toBeInTheDocument()
  })
})
