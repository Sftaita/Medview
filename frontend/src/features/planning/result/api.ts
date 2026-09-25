import { apiFetch } from '../../../lib/apiClient'
import type {
  PlanningResult,
  PlanningStatistics,
  PublicationPreflight,
  PublicationResult,
  ReassignmentCandidatesView,
} from './types'

/**
 * The whole-period coverage picture of a planning (docs/decisions.md D130):
 * every REQUIRED duty of each line's current generation, covered or not,
 * with the real reasons for an uncovered one. `from`/`to` only restrict
 * which duties are *returned* — the coverage counts always describe the
 * whole period, whatever month is on screen.
 */
export function fetchPlanningResult(
  planningStableId: string,
  filter: { from?: string; to?: string } = {},
): Promise<PlanningResult> {
  const query = new URLSearchParams()
  for (const [name, value] of Object.entries(filter)) {
    if (value) query.set(name, value)
  }
  const suffix = query.size > 0 ? `?${query}` : ''
  return apiFetch<PlanningResult>(`/api/plannings/${planningStableId}/result${suffix}`)
}

/**
 * The live candidate pool for one Duty (or its whole atomic block — the
 * backend detects that automatically, docs/decisions.md D131). Never
 * cached across calls: reopening the modal always re-reads the real
 * current state.
 */
export function fetchReassignmentCandidates(
  planningStableId: string,
  dutyStableId: string,
): Promise<ReassignmentCandidatesView> {
  return apiFetch<ReassignmentCandidatesView>(
    `/api/plannings/${planningStableId}/duties/${dutyStableId}/reassignment-candidates`,
  )
}

/**
 * Saves a reassignment — the only moment anything is persisted (D131 §16):
 * choosing a candidate in the modal never calls this by itself.
 * `expectedCurrentTeamMemberStableId` must be exactly what the candidates
 * view reported as `currentTeamMemberStableId` when the modal opened; a
 * stale value fails with a 409 the caller must handle.
 */
export function reassignDuty(
  planningStableId: string,
  dutyStableId: string,
  teamMemberStableId: string,
  expectedCurrentTeamMemberStableId: string | null,
): Promise<{ status: string }> {
  return apiFetch<{ status: string }>(`/api/plannings/${planningStableId}/duties/${dutyStableId}/reassign`, {
    method: 'POST',
    body: { teamMemberStableId, expectedCurrentTeamMemberStableId },
  })
}

/**
 * Duty counts by weekday, two perimeters — "this period" and "cumulative"
 * (docs/decisions.md D132). Purely descriptive, read from the live current
 * calendar state; never a fairness judgment.
 */
export function fetchPlanningStatistics(planningStableId: string): Promise<PlanningStatistics> {
  return apiFetch<PlanningStatistics>(`/api/plannings/${planningStableId}/statistics`)
}

/**
 * Read-only: whether the *current* calendar (never the solver's original
 * historical result) can really be published right now (docs/decisions.md
 * D133).
 */
export function fetchPublicationPreflight(planningStableId: string): Promise<PublicationPreflight> {
  return apiFetch<PublicationPreflight>(`/api/plannings/${planningStableId}/publication-preflight`)
}

/**
 * Publishes the planning — the server always re-runs the real preflight
 * here, never trusting a GET loaded moments earlier.
 */
export function publishPlanning(planningStableId: string): Promise<PublicationResult> {
  return apiFetch<PublicationResult>(`/api/plannings/${planningStableId}/publish`, {
    method: 'POST',
    body: {},
  })
}
