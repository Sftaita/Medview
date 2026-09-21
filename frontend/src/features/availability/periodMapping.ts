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

export type SavePlan = {
  /** Stored periods that no longer match anything on screen. */
  toDelete: UserAvailabilityPeriod[]
  /** Runs on screen that are not stored yet. */
  toCreate: DayRange[]
}

/**
 * What must change on the server so that it matches the screen. A stored
 * period whose days are exactly a run on screen is left alone (untouched, so
 * it keeps its identifier and any time of day it might carry).
 */
export function planSave(stored: UserAvailabilityPeriod[], screen: DayRange[]): SavePlan {
  const unmatched = mergeRanges(screen).map((range) => ({ range, matched: false }))
  const toDelete: UserAvailabilityPeriod[] = []

  for (const period of stored) {
    const covered = periodToRange(period)
    const match = unmatched.find(
      (entry) =>
        !entry.matched &&
        entry.range.type === covered.type &&
        entry.range.start === covered.start &&
        entry.range.end === covered.end,
    )
    if (match) {
      match.matched = true
    } else {
      toDelete.push(period)
    }
  }

  return { toDelete, toCreate: unmatched.filter((entry) => !entry.matched).map((entry) => entry.range) }
}

/** Convenience for the dashboard: ranges that are not entirely in the past. */
export function upcomingRanges(ranges: DayRange[], todayIndex: number): DayRange[] {
  return ranges.filter((range) => range.end >= todayIndex)
}
