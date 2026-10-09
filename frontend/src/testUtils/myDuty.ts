import type { MyDuty } from '../features/duties/types'

/** A duty unit of GET /api/me/duties, dates as local calendar days. */
export function makeMyDuty(dates: string[], overrides: Partial<MyDuty> = {}): MyDuty {
  return {
    key: dates.join('|'),
    dutyStableId: `duty-${dates[0]}`,
    planningStableId: 'p1',
    planningName: 'Gardes 2026-2027',
    lineStableId: 'l1',
    lineName: 'Première ligne',
    dutyTypeName: 'Garde',
    blockName: null,
    dates,
    startsAt: `${dates[0]}T00:00:00+02:00`,
    endsAt: `${dates[dates.length - 1]}T23:59:00+02:00`,
    conditional: false,
    coverageState: null,
    swappable: true,
    swapRequestStableId: null,
    ...overrides,
  }
}
