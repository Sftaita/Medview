export type MemberRole = 'OWNER' | 'ADMIN' | 'MEMBER'

export type PilotMemberRow = {
  memberStableId: string
  userStableId: string
  firstName: string
  lastName: string
  role: MemberRole
  team: { stableId: string; name: string }
  membershipEnd: string | null
  /**
   * docs/decisions.md D214: told by the announcement and the reminders (false:
   * left before the window, or an inactive account). Never a confirmation —
   * without any unavailability, a person is available.
   */
  participant: boolean
  /** The last change of their calendar on the period's dates (the journal, D215). */
  lastAvailabilityChangeAt: string | null
  /** UNAVAILABLE periods intersecting the planning period. */
  unavailabilityCount: number
  lastReminderAt: string | null
}

export type PilotSummary = {
  participantCount: number
  /** Those an announcement or a reminder reaches. */
  concernedCount: number
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
  /** The indicative deadline for updating the calendars — gates nothing (D214). */
  availabilityDeadline: string | null
  /** Whole days since the deadline; null when there is none or it is not passed. */
  deadlineOverdueDays: number | null
  openCollectionCount: number
  summary: PilotSummary
  members: PilotMemberRow[]
  /** The period shown (docs/planning-cycles.md §7.4). */
  period?: { stableId: string; number: number }
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
  status: 'PARTICIPANT' | 'WITHDRAWN'
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

export type ParticipantRemindersResult = {
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
  | 'DEADLINE_PASSED'
  | 'VALIDATION_WILL_BE_INVALIDATED'
  | 'LINE_WITHOUT_MEMBERS'
  | 'AMBIGUOUS_COVERAGE_SOURCE'
  | 'COVERAGE_SOURCE_MISSING'
  | 'NO_MEMBER_IN_PERIOD'
  | 'NO_LINE_TO_GENERATE'
  | 'SOURCE_OF_PUBLISHED_LINE'
  | 'BLOCK_CUT_BY_PERIOD_BOUNDARY'
  | 'HISTORICAL_BLOCK_REMAINDER'
  | 'PERIOD_END_CUTS_BLOCK'

export type PreflightIssue = {
  code: PreflightIssueCode
  lineStableId: string | null
  lineName: string | null
}

/** GENERATE: this launch generates the line; KEEP: locked, kept as it is; EXCLUDE: nothing demanded in the period. */
export type GenerationLineDecision = 'GENERATE' | 'KEEP' | 'EXCLUDE'

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
    /** docs/decisions.md D164/D167 — the line a reinforcement line depends on; its reinforcements are not counted. */
    demandSourceLineName: string | null
    /** docs/decisions.md D195: what the launch does with the line. */
    decision?: GenerationLineDecision
  }[]
  blockers: PreflightIssue[]
  warnings: PreflightIssue[]
  canGenerate: boolean
  /** docs/decisions.md D195: the scope this preflight was read with, and the one that would generate the lines not locked. */
  scope?: 'unlocked_lines' | null
  requiredScope?: 'unlocked_lines' | null
  /** The period read (docs/planning-cycles.md §7.4). */
  period?: { stableId: string; number: number }
  /** Participants whose SurgicalHub leave is imported, and how fresh it is (docs/surgicalhub-integration.md §7.5). */
  surgicalHub?: SurgicalHubPreflightParticipant[]
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
  /** Null only for a conditional line not resolved because its source line failed (docs/decisions.md D164). */
  generationStableId: string | null
  status: string | null
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
  /** A conditional line's demand, per unit (docs/decisions.md D164) — null for an independent line. */
  demand: {
    requiredUnitCount: number
    notRequiredUnitCount: number
    undeterminedUnitCount: number
  } | null
}

/** A finished generation's per-line result — the `outcome` of a GENERATE job. */
export type LaunchResult = {
  lines: LaunchLineResult[]
}

export type PlanningJobStatus = 'QUEUED' | 'RUNNING' | 'SUCCEEDED' | 'FAILED'

/**
 * One engine operation run by the worker, outside any HTTP request
 * (docs/decisions.md D149) — GET /api/plannings/{id}/jobs/latest is the
 * only source of truth for "en cours / terminé / échoué", never local state.
 * `outcome.lines` is a LaunchLineResult (GENERATE) or a CompletionLineResult
 * (COMPLETE); `failureCode` is a stable code, never an internal message.
 */
export type PlanningJob = {
  stableId: string
  kind: 'GENERATE' | 'COMPLETE'
  status: PlanningJobStatus
  requestedBy: { firstName: string; lastName: string }
  createdAt: string
  startedAt: string | null
  finishedAt: string | null
  failureCode: string | null
  outcome: { lines: unknown[]; coverage: 'COMPLETE' | 'INCOMPLETE' | null } | null
}

/** POST .../generations and .../complete: 202 with the queued job. */
/** One associated participant in the SurgicalHub check of a launch (decision D8). */
export type SurgicalHubParticipant = {
  userStableId: string
  firstName: string
  lastName: string
  lastSuccessfulSyncAt: string | null
  error: string | null
}

export type SurgicalHubPreflightParticipant = Omit<SurgicalHubParticipant, 'error'> & {
  lastSyncError: string | null
}

export type PlanningJobResponse = {
  job: PlanningJob
  /** Refreshing SurgicalHub failed for these people: generated with their last leave (warnings) or by override. */
  surgicalHub?: { warnings: SurgicalHubParticipant[]; overridden: SurgicalHubParticipant[] }
}

/** 409 surgicalhub_data_stale / 403 surgicalhub_override_forbidden of a launch. */
export type SurgicalHubStaleRefusal = {
  error: 'surgicalhub_data_stale' | 'surgicalhub_override_forbidden'
  participants: SurgicalHubParticipant[]
  canOverride: boolean
}

/** GET/POST .../rule-set(/activate) — never DRAFT/ACTIVE/RETIRED, version or
 * stableId: a manager only ever sees whether generation rules are active. */
export type RuleSetStatus = {
  active: boolean
  activatedAt?: string
  effectiveFrom?: string
}

/** docs/decisions.md D217 — one person, as the history names them. */
export type HistoryPerson = { stableId: string; firstName: string; lastName: string }

export type HistoryGenerationRef = {
  generationStableId: string
  lineName: string | null
  capturedAt: string | null
}

/** A change of a personal calendar (the journal, D215). */
export type HistoryAvailabilityEntry = {
  kind: 'availability'
  at: string
  eventStableId: string
  action: 'CREATED' | 'RESCHEDULED' | 'DELETED'
  origin: 'USER' | 'SURGICAL_HUB_SYNC'
  source: 'MANUAL' | 'SURGICAL_HUB'
  user: HistoryPerson
  actor: HistoryPerson | null
  availabilityStableId: string
  previous: { type: 'UNAVAILABLE' | 'PREFER_DUTY'; startsAt: string; endsAt: string } | null
  new: { type: 'UNAVAILABLE' | 'PREFER_DUTY'; startsAt: string; endsAt: string } | null
  /** Made after this generation read the calendars: it did not change it. */
  afterGeneration: HistoryGenerationRef | null
  /** Later generations whose snapshot holds this very entry with these very dates. */
  usedBy: HistoryGenerationRef[]
  /** What it met when it was made (D216). */
  impacts: { conflicts: unknown[] }[]
}

/** A generation of one line of the period, from its snapshot (D129). */
export type HistoryGenerationEntry = {
  kind: 'generation'
  at: string
  generationStableId: string
  line: { stableId: string; name: string } | null
  launchedBy: HistoryPerson | null
  requestedAt: string
  capturedAt: string | null
  finishedAt: string | null
  status: string
  coverage: string | null
  failureReason: string | null
  capturedUnavailabilityCount: number
  /** Only when the history is narrowed to one participant. */
  capturedUnavailabilities?: { stableId: string; startsAt: string; endsAt: string; source: string }[]
}

/** A change of a duty's holder (DutyAssignmentEvent): a reassignment or a swap — never a declaration. */
export type HistoryAssignmentEntry = {
  kind: 'assignment'
  at: string
  eventStableId: string
  author: HistoryPerson
  swap: boolean
  wasPublished: boolean
  duty: { stableId: string; date: string; lineName: string | null }
  from: HistoryPerson | null
  to: HistoryPerson | null
}

export type HistoryEntry = HistoryAvailabilityEntry | HistoryGenerationEntry | HistoryAssignmentEntry

/** GET /api/plannings/{id}/history — managers only. */
export type PlanningHistory = {
  period: { stableId: string; number: number }
  timezone: string
  member: { memberStableId: string; userStableId: string; firstName: string; lastName: string } | null
  entries: HistoryEntry[]
}
