import type { PlanningPeriodStatus } from '../types'

/** One real, already-computed reason a candidate could not be assigned — never a fabricated causality. */
export type PlanningResultCandidateReason = {
  candidateFirstName: string
  candidateLastName: string
  /** Plain-language labels, e.g. "indisponible". */
  reasons: string[]
}

export type PlanningResultAssignment = {
  stableId: string
  source: 'AUTO' | 'MANUAL'
  locked: boolean
  teamMemberStableId: string
  user: { stableId: string; firstName: string; lastName: string }
}

export type PlanningResultDuty = {
  dutyStableId: string
  /** "YYYY-MM-DD", local date. */
  date: string
  startsAt: string
  endsAt: string
  timezone: string
  dutyType: { stableId: string; code: string; name: string }
  required: boolean
  grouped: boolean
  /** The atomic block this duty belongs to (docs/decisions.md D148) — drawn and edited as one unit. */
  groupInstanceStableId: string | null
  /** The block's pattern name, e.g. "Week-end". */
  groupLabel: string | null
  /** Every date of the block, sorted — null for a standalone duty. */
  groupDates: string[] | null
  covered: boolean
  /** Non-null exactly when `covered` is true. */
  assignment: PlanningResultAssignment | null
  /** Only ever populated for an uncovered REQUIRED duty; empty when no cause is known — never invented. */
  reasons: PlanningResultCandidateReason[]
}

export type PlanningLineType = 'PRIMARY' | 'SECONDARY'

/**
 * One line's result, independently following D125 ("the most recent
 * COMPLETED generation"). `generationStableId: null` means no generation
 * has completed yet for this line — a real, distinct state, never a fake
 * 0/0 coverage.
 */
export type PlanningResultLine = {
  lineStableId: string
  lineName: string
  lineType: PlanningLineType
  generationStableId: string | null
  generatedAt: string | null
  coverageStatus: 'COMPLETE' | 'INCOMPLETE' | null
  requiredDutyCount: number
  coveredRequiredDutyCount: number
  uncoveredRequiredDutyCount: number
  duties: PlanningResultDuty[]
}

/** GET /api/plannings/{id}/result. */
export type PlanningResult = {
  planningStableId: string
  lines: PlanningResultLine[]
}

// --- Dynamic calendar (docs/decisions.md D131) -------------------------------------

/** One constituent Duty of the block being reassigned — a single Duty is a block of one. */
export type ReassignmentBlockDuty = {
  dutyStableId: string
  date: string
  startsAt: string
  endsAt: string
  dutyTypeName: string
}

/**
 * One member who can really take the duty (or the whole block) right now —
 * of the duty's own line, with no blocking reason (docs/decisions.md D144):
 * an impossible candidate is never listed. The server revalidates anyway.
 */
export type ReassignmentCandidate = {
  teamMemberStableId: string
  firstName: string
  lastName: string
}

/** GET /api/plannings/{id}/duties/{duty}/reassignment-candidates. */
export type ReassignmentCandidatesView = {
  groupInstanceStableId: string | null
  groupLabel: string | null
  blockDuties: ReassignmentBlockDuty[]
  generationStableId: string
  /** The concurrency identity a save/removal must echo back. */
  currentTeamMemberStableId: string | null
  currentAssignee: ReassignmentCandidate | null
  /** Replacements only — the current holder is never among them. */
  candidates: ReassignmentCandidate[]
}

/** POST /api/plannings/{id}/complete — one entry per active line (docs/decisions.md D145). */
export type CompletionLineResult = {
  lineStableId: string
  lineName: string
  status: 'completed' | 'nothing_to_complete' | 'not_generated' | 'solver_failed'
  holeCount: number
  filledUnitCount: number
  remainingUncoveredRequiredUnitCount: number
}

// --- Publication state (docs/decisions.md D143) -------------------------------------

type PersonName = { firstName: string; lastName: string }

/** One duty whose holder differs from the last diffusion. `null` = uncovered. */
export type PublicationChange = {
  dutyStableId: string
  date: string
  lineStableId: string
  lineName: string
  groupInstanceStableId: string | null
  before: PersonName | null
  after: PersonName | null
}

export type PublicationHistoryItem = {
  stableId: string
  kind: 'FIRST' | 'UPDATE'
  publishedAt: string
  publishedBy: PersonName
  changedDutyCount: number
  recipientCount: number
  sentCount: number
}

/**
 * GET /api/plannings/{id}/publication-state. `hasUnpublishedChanges`,
 * `changes` and `history` are only present for someone who can publish.
 */
export type PublicationState = {
  published: boolean
  firstPublishedAt: string | null
  lastPublishedAt: string | null
  lastPublishedBy: PersonName | null
  hasUnpublishedChanges?: boolean
  changes?: PublicationChange[]
  history?: PublicationHistoryItem[]
}

// --- Statistics (docs/decisions.md D132) -------------------------------------------

export type Weekday = 'MON' | 'TUE' | 'WED' | 'THU' | 'FRI' | 'SAT' | 'SUN'

/** One member's real, current duty count by weekday and by AllocationFamily —
 * purely descriptive, never a judgment (docs/decisions.md D132/D137).
 * `countsByFamily` is keyed by AllocationFamily name, built dynamically from
 * this group's real generation, never a hardcoded list; the empty-string key
 * groups duties with no family. */
export type StatisticsMemberRow = {
  teamMemberStableId: string
  firstName: string
  lastName: string
  countsByWeekday: Record<Weekday, number>
  countsByFamily: Record<string, number>
  total: number
  /** Keyed by duty type name — only the types this member really holds. */
  countsByDutyType: Record<string, number>
  /** Σ of each duty type's workload value — the domain's own "charge pondérée". */
  weightedLoad: number
}

/** One PlanningLine's rows — the same grouping as the rest of the calendar. */
export type StatisticsGroup = {
  groupStableId: string
  groupLabel: string
  members: StatisticsMemberRow[]
}

/** One statistics perimeter, with the exact dates it actually covers. */
export type StatisticsScope = {
  startsAt: string
  endsAt: string
  groups: StatisticsGroup[]
}

/** GET /api/plannings/{id}/statistics. */
export type PlanningStatistics = {
  currentPeriod: StatisticsScope
  cumulative: StatisticsScope
}

// --- Publication (docs/decisions.md D133) -------------------------------------------

export type PublicationLineReadiness = {
  lineStableId: string
  lineName: string
  periodStatus: PlanningPeriodStatus
  hasGeneration: boolean
}

export type PublicationDutyRef = {
  dutyStableId: string
  date: string
  dutyTypeName: string
}

export type PublicationMemberRef = {
  teamMemberStableId: string
  firstName: string
  lastName: string
}

export type InvalidPublicationAssignment = {
  duty: PublicationDutyRef
  member: PublicationMemberRef
  reason: string
}

export type PublicationConflict = {
  duty: PublicationDutyRef
  member: PublicationMemberRef
  reason: string
}

/** GET /api/plannings/{id}/publication-preflight — read-only, never modifies anything. */
export type PublicationPreflight = {
  publishable: boolean
  /** Same checks, except an uncovered duty on an already-published line (a removal being announced). */
  republishable: boolean
  lines: PublicationLineReadiness[]
  uncoveredDuties: PublicationDutyRef[]
  inconsistentGroups: { groupInstanceStableId: string }[]
  invalidAssignments: InvalidPublicationAssignment[]
  conflicts: PublicationConflict[]
}

export type PublicationLineResult = {
  lineStableId: string
  lineName: string
  periodStatus: PlanningPeriodStatus
  alreadyPublished: boolean
}

/** POST /api/plannings/{id}/publish and /republish. */
export type PublicationResult = {
  lines: PublicationLineResult[]
  publication: { stableId: string; kind: 'FIRST' | 'UPDATE'; publishedAt: string; changedDutyCount: number }
  recipientCount: number
  sentCount: number
}
