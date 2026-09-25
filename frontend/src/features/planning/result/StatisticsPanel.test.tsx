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
    expect(screen.getByRole('tab', { name: 'Cumul du planning' })).toHaveAttribute('aria-selected', 'false')

    expect(screen.getByText('Alice Martin')).toBeInTheDocument()
    const row = screen.getByText('Alice Martin').closest('tr')!
    expect(row).toHaveTextContent('3') // total
  })

  it('shows the exact bounds actually covered by the scope', async () => {
    stubStatistics([statistics({ currentPeriod: scope({ startsAt: '2027-01-01', endsAt: '2027-03-01' }) })])
    render(<StatisticsPanel planningStableId="plan-1" refreshKey={0} />)

    expect(await screen.findByText(/1 janvier 2027.*1 mars 2027/)).toBeInTheDocument()
  })

  it('switches to "Cumul du planning" and shows its own distinct values, with an older member visible only there', async () => {
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

    fireEvent.click(screen.getByRole('tab', { name: 'Cumul du planning' }))

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
})
