export type PlanningLineType = 'PRIMARY' | 'SECONDARY'

export type PlanningSummary = {
  stableId: string
  name: string
  creatorStableId: string
  /** Computed server-side via PlanningVoter::MANAGE — never derive this from creatorStableId, the frontend has no way to know its own stableId (see docs/decisions.md D078). */
  canManage: boolean
  /** The caller is themself in the candidate pool (has an open membership) — independent of canManage (D123). */
  participating?: boolean
  /** Creator or team OWNER/ADMIN: may follow up availability collections (D124). */
  canManageAvailability?: boolean
  startsAt: string
  endsAt: string
  timezone: string
  createdAt: string
  updatedAt: string
}

export type PlanningLineTeam = {
  stableId: string
  name: string
  /** Server-decided: the creator of the planning or an OWNER/ADMIN of this team. */
  canInvite?: boolean
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
  /** "M'inclure dans le planning": makes the creator a participant of the primary line. */
  includeMe?: boolean
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

export type TeamInvitationStatus = 'PENDING' | 'ACCEPTED' | 'EXPIRED' | 'REVOKED'

/** A pending offer for someone with no account yet — never a TeamMember. */
export type TeamInvitation = {
  stableId: string
  email: string
  firstName: string
  lastName: string
  role: 'OWNER' | 'ADMIN' | 'MEMBER'
  status: TeamInvitationStatus
  invitedByName: string
  expiresAt: string
  createdAt: string
}

export type InviteToTeamInput = {
  email: string
  firstName: string
  lastName: string
}

export type InviteStatus =
  'USER_ADDED' | 'INVITATION_CREATED' | 'ALREADY_MEMBER' | 'INVITATION_ALREADY_PENDING'

export type InviteResult = {
  status: InviteStatus
  /** False when a notification email was due but could not be sent (the change itself stands). */
  emailSent: boolean
  member: PlanningTeamMember | null
  invitation: TeamInvitation | null
}

export type PlanningAssignment = {
  stableId: string
  dutyStableId: string
  /** The duty's local date, "YYYY-MM-DD". */
  date: string
  startsAt: string
  endsAt: string
  timezone: string
  dutyType: { stableId: string; code: string; name: string }
  lineStableId: string | null
  lineName: string | null
  source: string
  locked: boolean
  user: { stableId: string; firstName: string; lastName: string }
}

/** What the engine's actual assignments add up to for one person — counted, never re-estimated. */
export type PersonSummary = {
  userStableId: string
  totalDuties: number
  weightedWorkload: number
  fridays: number
  saturdays: number
  sundays: number
  weekendDays: number
  byDutyType: { dutyTypeStableId: string; code: string; name: string; count: number }[]
}

export type PlanningAssignments = {
  generations: {
    stableId: string
    lineStableId: string
    lineName: string
    generatedAt: string | null
    coverageStatus: string | null
  }[]
  assignments: PlanningAssignment[]
  summary: PersonSummary | null
}
