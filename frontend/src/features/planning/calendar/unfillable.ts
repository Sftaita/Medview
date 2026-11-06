import type { PublicationUncoveredUnit } from '../result/types'

/** The posts of one line that nobody can fill (docs/decisions.md D234), as the preflight classified them. */
export type UnfillableGroup = {
  lineStableId: string
  lineName: string
  /** Days without holder (a block counts each of its days). */
  dayCount: number
  /** Of which reinforcements (conditional posts). */
  reinforcementCount: number
  /** At least one of them has no member of the line at all on its dates — the coverage start is worth checking. */
  noMemberOnDate: boolean
  /** The first day a member joins the line after those dates. */
  firstMembershipStart: string | null
  /** Every date, sorted. */
  dates: string[]
  linePublished: boolean
  dutyStableIds: string[]
}

/**
 * Groups, by line, the existing posts the server says nobody can fill
 * (`vacancy.unfillable`): neither the completion nor a manual assignment can
 * give them a holder. Nothing is decided here — a post not created yet, or one
 * an older server does not classify, is never said impossible.
 */
export function unfillableGroups(units: PublicationUncoveredUnit[] | undefined): UnfillableGroup[] {
  const groups = new Map<string, UnfillableGroup>()
  for (const unit of units ?? []) {
    if (!unit.materialized || unit.vacancy.unfillable !== true) continue
    const group = groups.get(unit.lineStableId) ?? {
      lineStableId: unit.lineStableId,
      lineName: unit.lineName,
      dayCount: 0,
      reinforcementCount: 0,
      noMemberOnDate: false,
      firstMembershipStart: null,
      dates: [],
      linePublished: unit.linePublished === true,
      dutyStableIds: [],
    }
    group.dayCount += unit.days.length
    if (unit.conditional) group.reinforcementCount += unit.days.length
    if (unit.vacancy.code === 'NO_MEMBER_ON_DATE') {
      group.noMemberOnDate = true
      const start = unit.vacancy.firstMembershipStart
      if (start && (!group.firstMembershipStart || start < group.firstMembershipStart)) {
        group.firstMembershipStart = start
      }
    }
    group.dates.push(...unit.dates)
    group.dutyStableIds.push(...unit.dutyStableIds)
    groups.set(unit.lineStableId, group)
  }
  return [...groups.values()].map((group) => ({ ...group, dates: [...group.dates].sort() }))
}
