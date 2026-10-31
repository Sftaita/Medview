import type { CalendarConflict, CalendarImpact } from './types'

/**
 * One entry per planning period (docs/decisions.md D216): a save made of
 * several requests (an edit split over several periods of the calendar) may
 * meet the same period more than once — its days are joined and its duties
 * listed once each.
 */
export function mergeImpacts(impacts: CalendarImpact[]): CalendarImpact[] {
  const byPeriod = new Map<string, CalendarImpact>()
  for (const impact of impacts) {
    const known = byPeriod.get(impact.period.stableId)
    if (!known) {
      byPeriod.set(impact.period.stableId, { ...impact, conflicts: uniqueConflicts(impact.conflicts) })
      continue
    }
    byPeriod.set(impact.period.stableId, {
      ...known,
      lines: [...new Set([...known.lines, ...impact.lines])],
      published: known.published || impact.published,
      generatedAt: latest(known.generatedAt, impact.generatedAt),
      from: impact.from < known.from ? impact.from : known.from,
      to: impact.to > known.to ? impact.to : known.to,
      conflicts: uniqueConflicts([...known.conflicts, ...impact.conflicts]),
    })
  }
  return [...byPeriod.values()].sort((a, b) =>
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
