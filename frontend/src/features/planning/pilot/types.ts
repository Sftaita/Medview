/** "PENDING" ("En attente"), "ACKNOWLEDGED" (shown "Confirmé"), "NOT_EXPECTED" (not counted). Never inferred from the number of unavailabilities. */
export type MemberCollectionState = 'PENDING' | 'ACKNOWLEDGED' | 'NOT_EXPECTED'

export type MemberRole = 'OWNER' | 'ADMIN' | 'MEMBER'

export type PilotMemberRow = {
  memberStableId: string
  userStableId: string
  firstName: string
  lastName: string
  role: MemberRole
  team: { stableId: string; name: string }
  membershipEnd: string | null
  collectionState: MemberCollectionState
  /** Latest confirmation among the relevant collections. */
  acknowledgedAt: string | null
  acknowledgementKind: 'CONFIRMED' | 'NO_UNAVAILABILITY' | null
  lastAvailabilityChangeAt: string | null
  pendingCollectionCount: number
  /** UNAVAILABLE periods intersecting the planning period. */
  unavailabilityCount: number
  lastReminderAt: string | null
}

export type PilotSummary = {
  participantCount: number
  expectedCount: number
  confirmedCount: number
  pendingCount: number
  notExpectedCount: number
  unavailabilityCount: number
}

/** GET /api/plannings/{id}/collection-status — managers only. */
export type CollectionStatus = {
  planning: {
    stableId: string
    name: string
    startsAt: string
    /** Exclusive. */
    endsAt: string
    /** Inclusive, for display. */
    lastDay: string
    timezone: string
  }
  /** Informative "date souhaitée de fin d'encodage" — gates nothing. */
  availabilityDeadline: string | null
  /** Whole days since the deadline; null when there is none or it is not passed. */
  deadlineOverdueDays: number | null
  openCollectionCount: number
  summary: PilotSummary
  members: PilotMemberRow[]
}

export type MemberPeriod = {
  stableId: string
  type: 'UNAVAILABLE' | 'PREFER_DUTY'
  startsAt: string
  endsAt: string
}

export type MemberCollectionResponse = {
  collectionStableId: string
  startsAt: string
  lastDay: string
  collectionStatus: 'OPEN' | 'CLOSED'
  status: 'PENDING' | 'ACKNOWLEDGED' | 'WITHDRAWN'
  acknowledgedAt: string | null
  acknowledgementKind: 'CONFIRMED' | 'NO_UNAVAILABILITY' | null
}

export type ReminderEntry = {
  stableId: string
  sentAt: string
  sentByName: string
  channel: 'EMAIL'
  bulk: boolean
}

/** GET /api/plannings/{id}/members/{memberId}/availability-status. */
export type MemberStatusDetail = {
  member: PilotMemberRow
  participation: {
    membershipStart: string
    membershipEnd: string | null
    participationPeriods: { validFrom: string; validTo: string | null; participationFactor: number }[]
    nonParticipationPeriods: { stableId: string; startsAt: string; endsAt: string }[]
  }
  collections: MemberCollectionResponse[]
  unavailabilities: MemberPeriod[]
  preferences: MemberPeriod[]
  reminders: ReminderEntry[]
}

export type ReminderSent = {
  stableId: string | null
  userStableId: string
  sentAt: string | null
  lastReminderAt: string | null
  bulk: boolean | null
}

export type PendingRemindersResult = {
  targetedCount: number
  sentCount: number
  skippedRecentlyCount: number
  failedCount: number
  sent: ReminderSent[]
}

export type PlanningSettingsResult = {
  availabilityDeadline: string | null
  deadlineOverdueDays: number | null
  openCollectionCount: number
}

export type PreflightIssueCode =
  | 'NO_ACTIVE_RULE_SET'
  | 'NO_DUTIES'
  | 'PERIOD_LOCKED'
  | 'NO_SOLVER_PARAMETER_SET'
  | 'PENDING_MEMBERS'
  | 'DEADLINE_PASSED'
  | 'VALIDATION_WILL_BE_INVALIDATED'
  | 'LINE_WITHOUT_MEMBERS'

export type PreflightIssue = {
  code: PreflightIssueCode
  lineStableId: string | null
  lineName: string | null
}

/** GET /api/plannings/{id}/generation-preflight — informative, captures nothing. */
export type GenerationPreflight = {
  planning: {
    stableId: string
    name: string
    startsAt: string
    endsAt: string
    lastDay: string
    timezone: string
  }
  participantCount: number
  confirmedCount: number
  pendingCount: number
  notExpectedCount: number
  unavailabilityCount: number
  availabilityDeadline: string | null
  deadlineOverdueDays: number | null
  lines: {
    stableId: string
    name: string
    type: 'PRIMARY' | 'SECONDARY'
    memberCount: number
    dutyCount: number
    periodStatus: string
    hasActiveRuleSet: boolean
    /** REQUIRED unit count per AllocationFamily name (docs/decisions.md D137);
     * the empty-string key groups units with no family. Never hardcoded names —
     * always rendered from what this line's structure actually configured. */
    familyUnitCounts: Record<string, number>
  }[]
  blockers: PreflightIssue[]
  warnings: PreflightIssue[]
  canGenerate: boolean
}

/** What the "Règles de repos" section of the generation dialog sends
 * (docs/decisions.md D105/D137, App\Service\RestPolicyRequestParser). Omitted
 * fields mean both policies stay disabled — never a guessed default. */
export type RestPolicyChoice = {
  legalMinRestEnabled: boolean
  legalMinRestHours: number | null
  teamMinRestEnabled: boolean
  teamMinRestHours: number | null
}

export type CandidateExclusion = {
  candidateId: string
  exclusions: { reason: string; context: Record<string, unknown> }[]
}

export type UnassignedDutyDiagnostic = {
  dutyUnitStableKey: string
  critical: boolean
  candidateExclusions: CandidateExclusion[]
}

export type StructuralDiagnosticEntry = {
  code: string
  dutyUnitStableKey: string
}

export type DiagnosticRelaxationEntry = {
  ruleCode: string
  tier: string
  phrasing: string
  disclaimer: string
}

/** `App\Service\UnsatReportPresenter::toArray()` — reused verbatim, never a
 * second shape reconstructed in React (docs/decisions.md D137). */
export type UnsatDiagnosticsPayload = {
  strictSolverStatus: string
  partialSolverStatus: string | null
  requiredDutyCount: number
  assignedDutyCount: number
  unassignedDuties: UnassignedDutyDiagnostic[]
  structuralDiagnostics: StructuralDiagnosticEntry[]
  solverAnalysis: { available: boolean }
  diagnosticRelaxations: DiagnosticRelaxationEntry[]
  existingDataConflict: { type: string; message: string } | null
}

export type LaunchLineResult = {
  lineStableId: string
  lineName: string
  generationStableId: string
  status: string
  error: string | null
  coverageStatus: 'COMPLETE' | 'INCOMPLETE' | null
  strictSolverStatus: string | null
  partialSolverStatus: string | null
  assignmentCount: number | null
  unassignedDutyCount: number | null
  /** Keyed by ObjectivePhaseId — true only once genuinely proven optimal for
   * that phase; a FEASIBLE result must never be presented as "optimal". */
  optimality: Record<string, boolean> | null
  diagnostics: UnsatDiagnosticsPayload | null
  snapshot: { capturedAt: string; memberCount: number; unavailableCount: number } | null
}

/** POST /api/plannings/{id}/generations. */
export type LaunchResult = {
  planningStableId: string
  lines: LaunchLineResult[]
}

/** GET/POST .../rule-set(/activate) — never DRAFT/ACTIVE/RETIRED, version or
 * stableId: a manager only ever sees whether generation rules are active. */
export type RuleSetStatus = {
  active: boolean
  activatedAt?: string
  effectiveFrom?: string
}
