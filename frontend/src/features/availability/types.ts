export type UserAvailabilityType = 'UNAVAILABLE' | 'PREFER_DUTY'

export type UserAvailabilityPeriod = {
  stableId: string
  type: UserAvailabilityType
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
