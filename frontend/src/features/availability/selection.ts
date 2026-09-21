import type { UserAvailabilityType } from './types'

/**
 * A run of whole days (inclusive bounds, absolute day indexes) of one nature.
 * Availability is always a whole-day notion: there is no time-of-day here.
 */
export type DayRange = { start: number; end: number; type: UserAvailabilityType }

/** Sorts by start and merges adjacent or overlapping ranges *of the same type*. */
export function mergeRanges(ranges: DayRange[]): DayRange[] {
  const sorted = [...ranges].sort(
    (a, b) => a.start - b.start || (a.type < b.type ? -1 : a.type > b.type ? 1 : 0),
  )
  const merged: DayRange[] = []

  for (const range of sorted) {
    // Adjacent same-type ranges may be separated by ranges of the other type in
    // `sorted`, so look back for the last range of this type.
    const previous = [...merged].reverse().find((candidate) => candidate.type === range.type)
    if (previous && range.start <= previous.end + 1) {
      previous.end = Math.max(previous.end, range.end)
    } else {
      merged.push({ ...range })
    }
  }

  return merged.sort((a, b) => a.start - b.start)
}

/** Removes the days [start, end] from every range, splitting the ones it crosses. */
export function cutRange(ranges: DayRange[], start: number, end: number): DayRange[] {
  const result: DayRange[] = []

  for (const range of ranges) {
    if (range.end < start || range.start > end) {
      result.push(range)
      continue
    }
    if (range.start < start) {
      result.push({ start: range.start, end: start - 1, type: range.type })
    }
    if (range.end > end) {
      result.push({ start: end + 1, end: range.end, type: range.type })
    }
  }

  return result
}

/** Adds [a, b] (any order) as `type`; the new range wins over the old on the shared days. */
export function addRange(ranges: DayRange[], a: number, b: number, type: UserAvailabilityType): DayRange[] {
  const start = Math.min(a, b)
  const end = Math.max(a, b)
  return mergeRanges([...cutRange(ranges, start, end), { start, end, type }])
}

export function rangeAt(ranges: DayRange[], day: number): DayRange | null {
  return ranges.find((range) => day >= range.start && day <= range.end) ?? null
}

/**
 * Click / tap on a day: removes it when already of `type`, converts it when
 * it belongs to the other nature, adds it otherwise.
 */
export function toggleDay(ranges: DayRange[], day: number, type: UserAvailabilityType): DayRange[] {
  const current = rangeAt(ranges, day)
  if (current && current.type === type) {
    return mergeRanges(cutRange(ranges, day, day))
  }
  return addRange(ranges, day, day, type)
}

export function countDays(ranges: DayRange[]): number {
  return ranges.reduce((total, range) => total + (range.end - range.start + 1), 0)
}

export function sameRanges(a: DayRange[], b: DayRange[]): boolean {
  const left = mergeRanges(a)
  const right = mergeRanges(b)
  if (left.length !== right.length) {
    return false
  }
  return left.every(
    (range, i) =>
      range.start === right[i].start && range.end === right[i].end && range.type === right[i].type,
  )
}
