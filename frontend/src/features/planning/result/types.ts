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
 * One PlanningTeamMember as a candidate — always the whole live candidate
 * pool, never filtered down to only the selectable ones: `blockingReasons`
 * is the real, already-translated reason a disabled candidate stays
 * visible for (never simply hidden).
 */
export type ReassignmentCandidate = {
  teamMemberStableId: string
  firstName: string
  lastName: string
  selectable: boolean
  isCurrent: boolean
  blockingReasons: string[]
}

/** GET /api/plannings/{id}/duties/{duty}/reassignment-candidates. */
export type ReassignmentCandidatesView = {
  groupInstanceStableId: string | null
  blockDuties: ReassignmentBlockDuty[]
  generationStableId: string
  currentTeamMemberStableId: string | null
  candidates: ReassignmentCandidate[]
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

/** POST /api/plannings/{id}/publish. */
export type PublicationResult = {
  lines: PublicationLineResult[]
}
