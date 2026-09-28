import type { PlanningResultDuty, PlanningResultLine } from '../result/types'

/** Where a duty sits inside its atomic block — null for a standalone duty. */
export type BlockPart = 'single' | 'first' | 'middle' | 'last'

export type CalendarItem = {
  duty: PlanningResultDuty
  blockPart: BlockPart | null
  /** Several duties that day on the same line: the duty type is shown to tell them apart. */
  showType: boolean
}

export type CalendarDay = {
  /** "YYYY-MM-DD" */
  date: string
  /** ISO weekday, 1 = Monday … 7 = Sunday. */
  weekday: number
  /** One entry per line, in line order; empty = no duty that day on that line. */
  cells: CalendarItem[][]
}

export type CalendarWeek = {
  /** "YYYY-MM-DD" of the Monday of this ISO week (may be in the previous month). */
  monday: string
  days: CalendarDay[]
}

export type CalendarMonth = {
  /** "YYYY-MM" */
  key: string
  weeks: CalendarWeek[]
}

export type CalendarModel = {
  lines: { stableId: string; name: string }[]
  months: CalendarMonth[]
  /** What "Compléter automatiquement" can act on: uncovered duties and missing required reinforcements. */
  uncoveredCount: number
  /** docs/decisions.md D167 — of which: reinforcements required but not assigned. */
  missingReinforcementCount: number
  /** Reinforcements whose need cannot be evaluated (never completable, never "not required"). */
  undeterminedCount: number
  /** Reinforcements no longer required but still held — real assignments, a warning only. */
  superfluousCount: number
}

/** Pure calendar arithmetic on "YYYY-MM-DD" strings — no timezone involved. */
export function addDays(date: string, days: number): string {
  const [y, m, d] = date.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10)
}

export function isoWeekday(date: string): number {
  const [y, m, d] = date.split('-').map(Number)
  const day = new Date(Date.UTC(y, m - 1, d)).getUTCDay()
  return day === 0 ? 7 : day
}

function blockPart(duty: PlanningResultDuty): BlockPart | null {
  const dates = duty.groupDates
  if (!duty.groupInstanceStableId || !dates || dates.length === 0) return null
  if (dates.length === 1) return 'single'
  if (duty.date === dates[0]) return 'first'
  if (duty.date === dates[dates.length - 1]) return 'last'
  return 'middle'
}

/**
 * The vertical calendar (docs/decisions.md D148): every date of the planning
 * ([startsAt, endsAt) — endsAt exclusive), grouped by month then ISO week,
 * one column per line. Only lines with a generation get a column; a date
 * with nothing on a line is an empty cell, never a fabricated one.
 */
export function buildCalendar(
  lines: PlanningResultLine[],
  startsAt: string,
  endsAtExclusive: string,
): CalendarModel {
  const generated = lines.filter((line) => line.generationStableId !== null)
  const byLineAndDate = generated.map((line) => {
    const map = new Map<string, PlanningResultDuty[]>()
    for (const duty of line.duties.filter(isShown)) {
      map.set(duty.date, [...(map.get(duty.date) ?? []), duty])
    }
    return map
  })

  // Only what "Compléter automatiquement" can act on: never a reinforcement nobody needs, never an undetermined one.
  let uncoveredCount = 0
  let missingReinforcementCount = 0
  let undeterminedCount = 0
  let superfluousCount = 0
  for (const line of generated) {
    uncoveredCount += line.duties.filter((duty) => !duty.covered && isShown(duty) && !isUndetermined(duty)).length
    missingReinforcementCount += line.duties.filter((duty) => duty.demand?.state === 'REQUIRED_UNASSIGNED').length
    undeterminedCount += line.duties.filter(isUndetermined).length
    superfluousCount += line.duties.filter((duty) => duty.demand?.state === 'NOT_REQUIRED_ASSIGNED').length
  }

  const months: CalendarMonth[] = []
  for (let date = startsAt; date < endsAtExclusive; date = addDays(date, 1)) {
    const monthKey = date.slice(0, 7)
    let month = months[months.length - 1]
    if (!month || month.key !== monthKey) {
      month = { key: monthKey, weeks: [] }
      months.push(month)
    }

    const weekday = isoWeekday(date)
    const monday = addDays(date, 1 - weekday)
    let week = month.weeks[month.weeks.length - 1]
    if (!week || week.monday !== monday) {
      week = { monday, days: [] }
      month.weeks.push(week)
    }

    week.days.push({
      date,
      weekday,
      cells: byLineAndDate.map((map) => {
        const duties = map.get(date) ?? []
        return duties.map((duty) => ({ duty, blockPart: blockPart(duty), showType: duties.length > 1 }))
      }),
    })
  }

  return {
    lines: generated.map((line) => ({ stableId: line.lineStableId, name: line.lineName })),
    months,
    uncoveredCount,
    missingReinforcementCount,
    undeterminedCount,
    superfluousCount,
  }
}

/**
 * docs/decisions.md D166 — the backend's live state decides: a conditional
 * duty nobody needs and nobody holds is not part of the calendar (never a
 * "Non attribué" gap). An intrinsic duty (demand null) is always shown.
 */
export function isShown(duty: PlanningResultDuty): boolean {
  return duty.demand?.state !== 'NOT_REQUIRED_UNASSIGNED'
}

/** A reinforcement whose demand cannot be evaluated — its own explicit label, never "Non attribué". */
export function isUndetermined(duty: PlanningResultDuty): boolean {
  return duty.demand?.state === 'UNDETERMINED'
}

/**
 * docs/decisions.md D167 — how one duty reads on the calendar, straight from
 * the backend's live state (`demand.state`): never re-deriving whether a
 * reinforcement is required. An intrinsic duty (demand null) reads as before.
 */
export type DutyPresentation = {
  /** Shown instead of a name when nobody holds it. */
  gap: string | null
  /** A short qualifier next to a name, e.g. "Renfort non requis". */
  tag: string | null
  tone: 'normal' | 'missing' | 'undetermined' | 'superfluous'
  /** Spoken context for assistive technologies. */
  spoken: string
}

export function presentDuty(duty: PlanningResultDuty): DutyPresentation {
  const held = duty.assignment !== null
  switch (duty.demand?.state) {
    case 'REQUIRED_ASSIGNED':
      return { gap: null, tag: null, tone: 'normal', spoken: 'renfort requis, attribué' }
    case 'REQUIRED_UNASSIGNED':
      return { gap: 'Renfort requis — non attribué', tag: null, tone: 'missing', spoken: 'renfort requis, non attribué' }
    case 'NOT_REQUIRED_ASSIGNED':
      return { gap: null, tag: 'Renfort non requis', tone: 'superfluous', spoken: 'renfort non requis, encore attribué' }
    case 'UNDETERMINED':
      return held
        ? { gap: null, tag: 'Renfort non évalué', tone: 'undetermined', spoken: 'besoin de renfort non évaluable' }
        : { gap: 'Renfort non évalué', tag: null, tone: 'undetermined', spoken: 'besoin de renfort non évaluable' }
    case 'NOT_REQUIRED_UNASSIGNED':
      // Never reaches the calendar (isShown), kept for completeness.
      return { gap: null, tag: 'Pas de renfort', tone: 'normal', spoken: 'pas de renfort nécessaire' }
    default:
      return held
        ? { gap: null, tag: null, tone: 'normal', spoken: '' }
        : { gap: 'Non attribué', tag: null, tone: 'missing', spoken: 'non attribué' }
  }
}
