export type TeamMemberRole = 'OWNER' | 'ADMIN' | 'MEMBER'

export type TeamMemberSummary = {
  stableId: string
  firstName: string
  lastName: string
  role: TeamMemberRole
  active: boolean
}

export type NonParticipationPeriod = {
  stableId: string
  startsAt: string
  endsAt: string
  createdAt: string
  updatedAt: string
}

export type UpsertNonParticipationInput = {
  startsAt: string
  endsAt: string
}
