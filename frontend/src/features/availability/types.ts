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
 * docs/decisions.md D216 — a period already generated that new unavailable
 * days fall in. Recorded in the calendar, never applied to the planning.
 * Dates are calendar dates ("YYYY-MM-DD"), `to` inclusive.
 */
export type CalendarImpact = {
  planningStableId: string
  planningName: string
  period: { stableId: string; number: number; count: number }
  lines: string[]
  generatedAt: string | null
  published: boolean
  from: string
  to: string
  conflicts: CalendarConflict[]
}

/** What a creation or a change answers: the entry, and what its new days meet. */
export type SavedUserAvailabilityPeriod = UserAvailabilityPeriod & { impacts?: CalendarImpact[] }

export type UpsertUserAvailabilityPeriodInput = {
  type: UserAvailabilityType
  startsAt: string
  endsAt: string
}
