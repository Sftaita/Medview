export type PlanningLineType = 'PRIMARY' | 'SECONDARY'

export type PlanningPeriodStatus = 'DRAFT' | 'GENERATED' | 'VALIDATED' | 'PUBLISHED' | 'ARCHIVED'

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
  /** Creator or team OWNER/ADMIN: may open the generation preflight and launch a generation (D129). */
  canGenerate?: boolean
  /** Creator or team OWNER/ADMIN: may view reassignment candidates and reassign a duty (D131). */
  canManageCalendar?: boolean
  /** Creator or team OWNER/ADMIN: may run the publication preflight and publish (D133). */
  canPublish?: boolean
  /** Creator or team OWNER/ADMIN: may configure a line — its weekly structure and how it is covered (D162/D167). */
  canManageLineStructure?: boolean
  /** Name of the line the caller takes part in — their lines, comma-separated, when several (D160); null when in none (dashboard). */
  myLineName?: string | null
  /** People with a membership over one of the active lines, each counted once (dashboard). */
  memberCount?: number
  /** At least one active line is published (dashboard). */
  published?: boolean
  /** Active lines of the planning (plannings list, D169). */
  lineCount?: number
  /** An availability collection is open (plannings list, D169). */
  collecting?: boolean
  startsAt: string
  endsAt: string
  timezone: string
  createdAt: string
  updatedAt: string
  /** Today in the planning's timezone (docs/planning-cycles.md §4.1). */
  today?: string
  /** The period that contains today, else null. */
  currentPeriod?: PlanningPeriodSummary | null
  /** The first period starting after today, else null. */
  nextPeriod?: PlanningPeriodSummary | null
  /** The last period — what a card shows once every period is over (lot L6). */
  latestPeriod?: PlanningPeriodSummary
  periodCount?: number
  currentPeriodStableId?: string | null
  nextPeriodStableId?: string | null
}

/** A period's own status, derived by the server (docs/planning-cycles.md §4.2). */
export type PlanningCycleStatus =
  'preparation' | 'collecting' | 'generated' | 'partially_published' | 'published'

/** What a planning card reads of a period (§7.1, §7.2). `endsAt` is exclusive, `lastDay` the last day included. */
export type PlanningPeriodSummary = {
  stableId: string
  number: number
  startsAt: string
  endsAt: string
  lastDay: string
  status: PlanningCycleStatus
  timeState: 'past' | 'current' | 'future'
  /** docs/decisions.md D194: lines that count, published, incomplete, without demand. */
  lineCounts: { counting: number; published: number; incomplete: number; withoutDemand: number }
  publishedAt: string | null
  lastPublishedAt: string | null
  generatedAt: string | null
  /** The open collections' progress; `uncoveredDays` = days of the period no collection covers. */
  collections: {
    open: number
    closed: number
    acknowledged: number
    expected: number
    deadline: string | null
    uncoveredDays: number
  }
}

/** Whether a line takes part in a period, and why (docs/planning-cycles.md §4.3, D194). */
export type LineApplicability = {
  state: 'INACTIVE' | 'NOT_IN_PERIOD' | 'NO_DEMAND' | 'INCOMPLETE' | 'APPLICABLE'
  reasons: string[]
  dutyCount?: number
  recurringPatternCount?: number
  conditional?: boolean
}

/** A full period (§7.2). */
export type PlanningPeriodView = PlanningPeriodSummary & {
  lines: {
    lineStableId: string
    planningPeriodStableId: string
    status: PlanningPeriodStatus
    applicability: LineApplicability | null
  }[]
  isLast: boolean
  /** docs/decisions.md D208: the dates a write accepts — whether the start (first period) or the end can move now. */
  canEditStart: boolean
  canEditEnd: boolean
  canDelete: boolean
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
  /** Lifecycle of the line's period: DRAFT, GENERATED, VALIDATED, PUBLISHED, ARCHIVED. */
  periodStatus?: PlanningPeriodStatus
  /** The line's period in each planning period (docs/planning-cycles.md §7). */
  periods?: {
    periodStableId: string
    number: number
    planningPeriodStableId: string
    status: PlanningPeriodStatus
    /** People whose membership intersects the period, each once (lot L7). */
    memberCount?: number
  }[]
  createdAt: string
  updatedAt: string
}

export type PlanningDetail = PlanningSummary & {
  lines: PlanningLineSummary[]
  /** Every period, in order (docs/planning-cycles.md §7.1). */
  periods?: PlanningPeriodView[]
  /** The creator, and the creation of periods switched on (MEDVUE_PERIOD_CREATION_ENABLED). */
  canCreatePeriod?: boolean
  /** May open the collection of a period — same flag, availability right. */
  canOpenPeriodCollection?: boolean
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
