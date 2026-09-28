import { describe, expect, it } from 'vitest'
import type { LiveCoverageState, LiveDutyDemand, PlanningResultDuty, PlanningResultLine } from '../result/types'
import { addDays, buildCalendar, isoWeekday } from './calendarModel'

function demand(state: LiveCoverageState): LiveDutyDemand {
  return {
    state,
    required: state === 'UNDETERMINED' ? null : state.startsWith('REQUIRED'),
    reason: 'HOLDER_HAS_NO_TRIGGER',
    superfluous: state === 'NOT_REQUIRED_ASSIGNED',
    triggeringDutyStableIds: [],
    triggeringDates: [],
    dayReason: 'HOLDER_HAS_NO_TRIGGER',
    weekday: 'TUESDAY',
    sourceDutyStableId: 's1',
    sourceDate: '2026-10-06',
    sourceHolder: null,
    trigger: null,
  }
}

function duty(date: string, overrides: Partial<PlanningResultDuty> = {}): PlanningResultDuty {
  return {
    dutyStableId: `d-${date}-${overrides.dutyType?.code ?? 'G'}`,
    date,
    startsAt: `${date}T08:00:00+01:00`,
    endsAt: `${date}T20:00:00+01:00`,
    timezone: 'Europe/Brussels',
    dutyType: { stableId: 't', code: 'G', name: 'Garde' },
    required: true,
    grouped: false,
    groupInstanceStableId: null,
    groupLabel: null,
    groupDates: null,
    covered: true,
    assignment: null,
    reasons: [],
    demand: null,
    ...overrides,
  }
}

function line(name: string, duties: PlanningResultDuty[], generated = true): PlanningResultLine {
  return {
    lineStableId: `l-${name}`,
    lineName: name,
    lineType: 'PRIMARY',
    generationStableId: generated ? `g-${name}` : null,
    generatedAt: null,
    coverageStatus: 'COMPLETE',
    requiredDutyCount: duties.length,
    coveredRequiredDutyCount: duties.length,
    uncoveredRequiredDutyCount: 0,
    undeterminedDutyCount: 0,
    superfluousDutyCount: 0,
    notRequiredDutyCount: 0,
    duties,
  }
}

describe('calendar arithmetic', () => {
  it('adds days across months and years, and knows ISO weekdays', () => {
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
    expect(addDays('2027-03-01', -1)).toBe('2027-02-28')
    expect(isoWeekday('2026-10-05')).toBe(1)
    expect(isoWeekday('2026-10-11')).toBe(7)
  })
})

describe('buildCalendar', () => {
  it('lists every date of the period, grouped by month then ISO week, one column per generated line', () => {
    const model = buildCalendar(
      [
        line('Principale', [duty('2026-10-05')]),
        line('Secondaire', [duty('2026-10-05')]),
        line('Vide', [], false),
      ],
      '2026-10-01',
      '2026-11-03',
    )

    expect(model.lines.map((l) => l.name)).toEqual(['Principale', 'Secondaire'])
    expect(model.months.map((m) => m.key)).toEqual(['2026-10', '2026-11'])
    const october = model.months[0]
    expect(october.weeks[0].monday).toBe('2026-09-28')
    expect(october.weeks[0].days.map((d) => d.date)).toEqual([
      '2026-10-01',
      '2026-10-02',
      '2026-10-03',
      '2026-10-04',
    ])
    expect(october.weeks.flatMap((w) => w.days)).toHaveLength(31)
    expect(model.months[1].weeks.flatMap((w) => w.days)).toHaveLength(2)

    const monday5 = october.weeks[1].days[0]
    expect(monday5.date).toBe('2026-10-05')
    expect(monday5.cells).toHaveLength(2)
    expect(monday5.cells[0]).toHaveLength(1)
    expect(october.weeks[1].days[1].cells[0]).toEqual([])
  })

  it('marks the position of each day inside its atomic block', () => {
    const block = {
      groupInstanceStableId: 'b1',
      groupLabel: 'Week-end',
      groupDates: ['2026-10-09', '2026-10-10', '2026-10-11'],
      grouped: true,
    }
    const model = buildCalendar(
      [
        line('Principale', [
          duty('2026-10-08'),
          duty('2026-10-09', block),
          duty('2026-10-10', block),
          duty('2026-10-11', block),
        ]),
      ],
      '2026-10-05',
      '2026-10-12',
    )
    const days = model.months[0].weeks[0].days
    expect(days[3].cells[0][0].blockPart).toBeNull()
    expect(days[4].cells[0][0].blockPart).toBe('first')
    expect(days[5].cells[0][0].blockPart).toBe('middle')
    expect(days[6].cells[0][0].blockPart).toBe('last')
  })

  it('counts uncovered duties and shows the duty type only when a line has several duties that day', () => {
    const model = buildCalendar(
      [
        line('Principale', [
          duty('2026-10-05', { dutyType: { stableId: 'j', code: 'J', name: 'Jour' } }),
          duty('2026-10-05', { dutyType: { stableId: 'n', code: 'N', name: 'Nuit' }, covered: false }),
          duty('2026-10-06'),
        ]),
      ],
      '2026-10-05',
      '2026-10-07',
    )
    expect(model.uncoveredCount).toBe(1)
    const [monday, tuesday] = model.months[0].weeks[0].days
    expect(monday.cells[0].map((i) => i.showType)).toEqual([true, true])
    expect(tuesday.cells[0][0].showType).toBe(false)
  })

  it('never shows nor counts a reinforcement nobody needs as a gap (docs/decisions.md D166)', () => {
    const model = buildCalendar(
      [
        line('Renfort', [
          duty('2026-10-05', { covered: false, required: false, demand: demand('NOT_REQUIRED_UNASSIGNED') }),
          duty('2026-10-06', { covered: false, required: false, demand: demand('UNDETERMINED') }),
          duty('2026-10-07', { covered: false, demand: demand('REQUIRED_UNASSIGNED') }),
          duty('2026-10-08', { covered: true, required: false, demand: demand('NOT_REQUIRED_ASSIGNED') }),
        ]),
      ],
      '2026-10-05',
      '2026-10-09',
    )
    const [monday, tuesday, wednesday, thursday] = model.months[0].weeks[0].days
    expect(monday.cells[0]).toEqual([])
    expect(tuesday.cells[0]).toHaveLength(1)
    expect(wednesday.cells[0]).toHaveLength(1)
    expect(thursday.cells[0]).toHaveLength(1)
    // Only what "Compléter automatiquement" can act on: the required, uncovered one.
    expect(model.uncoveredCount).toBe(1)
  })
})
