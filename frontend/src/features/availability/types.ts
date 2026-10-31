export type UserAvailabilityType = 'UNAVAILABLE' | 'PREFER_DUTY'

/** MANUAL: the person's own entry. SURGICAL_HUB: leave imported from SurgicalHub, read-only here. */
export type UserAvailabilitySource = 'MANUAL' | 'SURGICAL_HUB'

export type UserAvailabilityPeriod = {
  stableId: string
  type: UserAvailabilityType
  source: UserAvailabilitySource
  /** False for imported leave: only SurgicalHub changes it (docs/surgicalhub-integration.md §10). */
  editable: boolean
  /** True for the person's own entries, and for imported leave no association keeps in sync any more (§9). */
  deletable?: boolean
  startsAt: string
  endsAt: string
  createdAt: string
  updatedAt: string
}

/** A duty the person holds on the days just declared (published lines only, docs/decisions.md D216). */
export type CalendarConflict = {
  dates: string[]
  lineName: string
  dutyTypeName: string
  blockName: string | null
}

/**
 * docs/decisions.md D216 / D218 — a period already generated whose days a
 * change of the calendar makes unavailable (`addedDays`) or available again
 * (`releasedDays`). Recorded in the calendar, never applied to the planning.
 * Dates are calendar dates ("YYYY-MM-DD"); `from` / `to` (inclusive) span
 * both lists. `conflicts` are the duties held on the days added.
 */
export type CalendarImpact = {
  planningStableId: string
  planningName: string
  period: { stableId: string; number: number; count: number }
  lines: string[]
  generatedAt: string | null
  published: boolean
  addedDays: string[]
  releasedDays: string[]
  from: string
  to: string
  conflicts: CalendarConflict[]
}

/** What a creation or a change answers: the entry, and what the change meets. */
export type SavedUserAvailabilityPeriod = UserAvailabilityPeriod & { impacts?: CalendarImpact[] }

/** What a deletion answers (D218): what the days it gives back meet. */
export type DeletedUserAvailabilityPeriod = { impacts?: CalendarImpact[] } | null

export type UpsertUserAvailabilityPeriodInput = {
  type: UserAvailabilityType
  startsAt: string
  endsAt: string
}
