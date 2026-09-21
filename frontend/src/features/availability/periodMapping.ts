import { dateOfDayIndex, dayIndexOfDate } from './calendarAxis'
import { cutRange, mergeRanges, type DayRange } from './selection'
import type { UpsertUserAvailabilityPeriodInput, UserAvailabilityPeriod } from './types'

/**
 * The whole-day range a stored period covers: from the local day of `startsAt`
 * to the local day of the last instant before `endsAt` (the interval is
 * half-open). A legacy period with a time of day is widened to its days.
 */
export function periodToRange(period: UserAvailabilityPeriod): DayRange {
  const start = dayIndexOfDate(new Date(period.startsAt))
  const lastInstant = new Date(new Date(period.endsAt).getTime() - 1)
  return { start, end: Math.max(start, dayIndexOfDate(lastInstant)), type: period.type }
}

/**
 * What the calendar shows for the stored periods. The backend lets an
 * UNAVAILABLE and a PREFER_DUTY period overlap, the calendar does not (a day
 * has one nature): the hard signal wins, as it does for the future engine.
 */
export function periodsToRanges(periods: UserAvailabilityPeriod[]): DayRange[] {
  const all = periods.map(periodToRange)
  const unavailable = mergeRanges(all.filter((range) => range.type === 'UNAVAILABLE'))
  let preferences = mergeRanges(all.filter((range) => range.type === 'PREFER_DUTY'))

  for (const range of unavailable) {
    preferences = cutRange(preferences, range.start, range.end)
  }

  return mergeRanges([...unavailable, ...preferences])
}

/** API payload for a whole-day run: local midnight of the first day → local midnight after the last. */
export function rangeToInput(range: DayRange): UpsertUserAvailabilityPeriodInput {
  return {
    type: range.type,
    startsAt: dateOfDayIndex(range.start).toISOString(),
    endsAt: dateOfDayIndex(range.end + 1).toISOString(),
  }
}

export type SyncPlan = {
  /** Stored periods that no longer match anything on screen. */
  toDelete: UserAvailabilityPeriod[]
  /** Stored periods whose dates changed on screen: edited in place (PATCH), keeping their identifier. */
  toUpdate: { period: UserAvailabilityPeriod; range: DayRange }[]
  /** Runs on screen that have no stored counterpart. */
  toCreate: DayRange[]
}

function overlapDays(a: DayRange, b: DayRange): number {
  return Math.max(0, Math.min(a.end, b.end) - Math.max(a.start, b.start) + 1)
}

/**
 * What must change on the server so that it matches the screen — the diff
 * behind the autosave. A stored period whose days are exactly a run on screen
 * is left alone (so it keeps its identifier and any time of day it might
 * carry). A run that overlaps a stored period of the same nature *edits* it
 * (a resize or a move is one PATCH, not a delete plus a create). What is left
 * is deleted or created.
 */
export function planSync(stored: UserAvailabilityPeriod[], screen: DayRange[]): SyncPlan {
  const runs = mergeRanges(screen).map((range) => ({ range, matched: false }))
  const periods = stored.map((period) => ({ period, covered: periodToRange(period), matched: false }))

  for (const entry of periods) {
    const match = runs.find(
      (run) =>
        !run.matched &&
        run.range.type === entry.covered.type &&
        run.range.start === entry.covered.start &&
        run.range.end === entry.covered.end,
    )
    if (match) {
      match.matched = true
      entry.matched = true
    }
  }

  const toUpdate: SyncPlan['toUpdate'] = []
  for (const run of runs.filter((candidate) => !candidate.matched)) {
    const best = periods
      .filter((entry) => !entry.matched && entry.covered.type === run.range.type)
      .map((entry) => ({ entry, overlap: overlapDays(entry.covered, run.range) }))
      .filter(({ overlap }) => overlap > 0)
      .sort((a, b) => b.overlap - a.overlap)[0]
    if (best) {
      run.matched = true
      best.entry.matched = true
      toUpdate.push({ period: best.entry.period, range: run.range })
    }
  }

  // Shrinks first: growing a period into dates another one is about to leave would briefly touch it,
  // which the backend (rightly) refuses for two periods of the same nature.
  const covers = (outer: DayRange, inner: DayRange) => outer.start <= inner.start && outer.end >= inner.end
  toUpdate.sort((a, b) => {
    const shrinkA = covers(periodToRange(a.period), a.range) ? 0 : 1
    const shrinkB = covers(periodToRange(b.period), b.range) ? 0 : 1
    return shrinkA - shrinkB
  })

  return {
    toDelete: periods.filter((entry) => !entry.matched).map((entry) => entry.period),
    toUpdate,
    toCreate: runs.filter((run) => !run.matched).map((run) => run.range),
  }
}
/** Convenience for the dashboard: ranges that are not entirely in the past. */
export function upcomingRanges(ranges: DayRange[], todayIndex: number): DayRange[] {
  return ranges.filter((range) => range.end >= todayIndex)
}
