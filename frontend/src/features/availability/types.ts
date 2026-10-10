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

export type UpsertUserAvailabilityPeriodInput = {
  type: UserAvailabilityType
  startsAt: string
  endsAt: string
}
