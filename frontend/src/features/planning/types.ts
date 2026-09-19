export type PlanningLineType = 'PRIMARY' | 'SECONDARY'

export type PlanningSummary = {
  stableId: string
  name: string
  creatorStableId: string
  /** Computed server-side via PlanningVoter::MANAGE — never derive this from creatorStableId, the frontend has no way to know its own stableId (see docs/decisions.md D078). */
  canManage: boolean
  startsAt: string
  endsAt: string
  timezone: string
  createdAt: string
  updatedAt: string
}

export type PlanningLineTeam = {
  stableId: string
  name: string
}

export type PlanningLineSummary = {
  stableId: string
  name: string
  type: PlanningLineType
  position: number
  active: boolean
  team: PlanningLineTeam
  memberCount?: number
  planningPeriodStableId: string
  createdAt: string
  updatedAt: string
}

export type PlanningDetail = PlanningSummary & {
  lines: PlanningLineSummary[]
}

export type CreatePlanningInput = {
  name: string
  startsAt: string
  endsAt: string
  timezone: string
  primaryTeam: {
    name: string
  }
}

export type CreatePlanningLineInput = {
  name: string
}

export type PlanningTeamMember = {
  stableId: string
  userStableId: string
  firstName: string
  lastName: string
  role: 'OWNER' | 'ADMIN' | 'MEMBER'
  membershipStart: string
  membershipEnd: string | null
  active: boolean
}

export type AddPlanningTeamMemberInput = {
  userStableId: string
  role: 'OWNER' | 'ADMIN' | 'MEMBER'
  membershipStart: string
}
