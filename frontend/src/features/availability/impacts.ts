import type { CalendarConflict, CalendarImpact } from './types'

/**
 * One entry per planning period (docs/decisions.md D216, D218): a save made
 * of several requests (an edit split over several periods of the calendar,
 * a deletion then a creation) may meet the same period more than once — its
 * days are joined and its duties listed once each. A day both given back and
 * taken again in the same save did not change: it is not told, nor is a duty
 * on it; a period left with nothing to tell is dropped.
 */
export function mergeImpacts(impacts: CalendarImpact[]): CalendarImpact[] {
  const byPeriod = new Map<string, CalendarImpact>()
  for (const impact of impacts) {
    const known = byPeriod.get(impact.period.stableId)
    if (!known) {
      byPeriod.set(impact.period.stableId, { ...impact })
      continue
    }
    byPeriod.set(impact.period.stableId, {
      ...known,
      lines: [...new Set([...known.lines, ...impact.lines])],
      published: known.published || impact.published,
      generatedAt: latest(known.generatedAt, impact.generatedAt),
      addedDays: [...known.addedDays, ...impact.addedDays],
      releasedDays: [...known.releasedDays, ...impact.releasedDays],
      conflicts: [...known.conflicts, ...impact.conflicts],
    })
  }

  const merged: CalendarImpact[] = []
  for (const impact of byPeriod.values()) {
    const added = new Set(impact.addedDays)
    const released = new Set(impact.releasedDays)
    const addedDays = [...added].filter((day) => !released.has(day)).sort()
    const releasedDays = [...released].filter((day) => !added.has(day)).sort()
    const all = [...addedDays, ...releasedDays].sort()
    if (all.length === 0) continue
    merged.push({
      ...impact,
      addedDays,
      releasedDays,
      from: all[0],
      to: all[all.length - 1],
      conflicts: uniqueConflicts(impact.conflicts).filter((conflict) =>
        conflict.dates.some((date) => addedDays.includes(date)),
      ),
    })
  }
  return merged.sort((a, b) =>
    a.from === b.from ? a.planningName.localeCompare(b.planningName, 'fr') : a.from < b.from ? -1 : 1,
  )
}

function uniqueConflicts(conflicts: CalendarConflict[]): CalendarConflict[] {
  const seen = new Set<string>()
  return conflicts.filter((conflict) => {
    const key = `${conflict.lineName}|${conflict.dates.join(',')}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

function latest(a: string | null, b: string | null): string | null {
  if (a === null) return b
  if (b === null) return a
  return new Date(a) > new Date(b) ? a : b
}
