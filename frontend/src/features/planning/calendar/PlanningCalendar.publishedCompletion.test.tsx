import '@testing-library/jest-dom/vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../../../testUtils/stubApi'
import type { PlanningResultDuty, PlanningResultLine, PublicationState } from '../result/types'
import type { PlanningDetail, PlanningPeriodStatus } from '../types'
import { buildCalendar } from './calendarModel'
import { PlanningCalendar } from './PlanningCalendar'

/**
 * docs/decisions.md D223, D228 — the completion never acts on a published
 * (validated, archived) line: the calendar says so next to it, manual editing
 * stays. Launching it is « Compléter la période N », in the header only.
 */

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-02T10:00:00Z'))
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const MANAGER: PlanningDetail = {
  stableId: 'plan-1',
  name: 'Gardes',
  creatorStableId: 'u0',
  canManage: true,
  canManageCalendar: true,
  canPublish: true,
  startsAt: '2026-10-01',
  endsAt: '2026-11-01',
  timezone: 'Europe/Brussels',
  createdAt: '',
  updatedAt: '',
  lines: [],
}

function duty(id: string, date: string, who: string | null): PlanningResultDuty {
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
          teamMemberStableId: `m-${who}`,
          user: { stableId: `u-${who}`, firstName: who, lastName: 'X' },
        }
      : null,
    reasons: [],
    demand: null,
  }
}

function line(
  id: string,
  name: string,
  periodStatus: PlanningPeriodStatus,
  duties: PlanningResultDuty[],
): PlanningResultLine {
  return {
    lineStableId: id,
    lineName: name,
    lineType: id === 'l1' ? 'PRIMARY' : 'SECONDARY',
    generationStableId: `g-${id}`,
    generatedAt: null,
    coverageStatus: 'INCOMPLETE',
    requiredDutyCount: duties.length,
    coveredRequiredDutyCount: duties.filter((d) => d.covered).length,
    uncoveredRequiredDutyCount: duties.filter((d) => !d.covered).length,
    undeterminedDutyCount: 0,
    superfluousDutyCount: 0,
    notRequiredDutyCount: 0,
    periodStatus,
    duties,
  }
}

const PUBLISHED_STATE: PublicationState = {
  published: true,
  firstPublishedAt: '2026-10-01T08:00:00+00:00',
  lastPublishedAt: '2026-10-01T08:00:00+00:00',
  lastPublishedBy: { firstName: 'Chef', lastName: 'X' },
  hasUnpublishedChanges: true,
  changes: [],
  history: [],
}

const SENIORS_WITH_HOLE = line('l1', 'Seniors', 'PUBLISHED', [
  duty('s5', '2026-10-05', 'Anne'),
  duty('s6', '2026-10-06', null),
])

function setup(lines: PlanningResultLine[]) {
  return stubApi({
    'GET /api/plannings/plan-1/result': () => ({ planningStableId: 'plan-1', lines }),
    'GET /api/plannings/plan-1/publication-state': () => PUBLISHED_STATE,
    'GET /api/plannings/plan-1/publication-preflight': () => ({ invalidAssignments: [], lines: [] }),
  })
}

describe('The calendar, the completion and published lines (docs/decisions.md D223, D228)', () => {
  it('counts only the holes of lines that may be completed', () => {
    const model = buildCalendar(
      [
        SENIORS_WITH_HOLE,
        line('l2', 'Juniors', 'GENERATED', [duty('j5', '2026-10-05', null), duty('j6', '2026-10-06', null)]),
        line('l3', 'Ancienne', 'ARCHIVED', [duty('a5', '2026-10-05', null)]),
      ],
      '2026-10-01',
      '2026-11-01',
    )
    expect(model.uncoveredCount).toBe(4)
    expect(model.completableCount).toBe(2)
    expect(model.lockedLinesWithHoles).toEqual(['Seniors', 'Ancienne'])
  })

  it('says why when only a published line has holes — editing a duty stays possible', async () => {
    setup([SENIORS_WITH_HOLE])
    render(<PlanningCalendar planning={MANAGER} />)

    const notice = await screen.findByText(
      /Seniors est publiée : elle n’est jamais complétée automatiquement/,
    )
    expect(notice.closest('#cal-complete-locked')).toHaveTextContent(
      /jamais complétée automatiquement.*Attribuez ses gardes une à une, puis republiez/,
    )
    expect(screen.queryByRole('button', { name: /Compléter/ })).not.toBeInTheDocument()
    expect(screen.getByText(/— attribuez-la\.$/)).toBeInTheDocument()
    // The manual workflow is untouched: the hole of the published line can still be edited.
    expect(screen.getByRole('button', { name: /^Modifier : .*2026-10-06/ })).toBeEnabled()
  })

  it('points to the header for a draft line next to a published one, and tells which line it leaves alone', async () => {
    setup([SENIORS_WITH_HOLE, line('l2', 'Juniors', 'GENERATED', [duty('j6', '2026-10-06', null)])])
    render(
      <PlanningCalendar
        planning={MANAGER}
        period={{
          stableId: 'c3',
          number: 3,
          startsAt: '2026-10-01',
          endsAt: '2026-11-01',
          lastDay: '2026-10-31',
        }}
      />,
    )

    expect(await screen.findByText(/Seniors est publiée/)).toBeInTheDocument()
    expect(screen.getByText(/ou utilisez « Compléter la période 3 » en haut de page\.$/)).toBeInTheDocument()
    // The only way is the header: no second button down here.
    expect(screen.queryByRole('button', { name: /Compléter/ })).not.toBeInTheDocument()
  })
})
