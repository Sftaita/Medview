import { apiFetch, apiFetchBlob } from '../../../lib/apiClient'
import { withPeriod } from '../../periods/api'
import type {
  DependentImpact,
  PlanningResult,
  PublicationState,
  PlanningStatistics,
  PublicationPreflight,
  PublicationResult,
  ReassignmentCandidatesView,
} from './types'
import type { PlanningJobResponse } from '../pilot/types'

/**
 * The whole-period coverage picture of a planning (docs/decisions.md D130):
 * every REQUIRED duty of each line's current generation, covered or not,
 * with the real reasons for an uncovered one. `from`/`to` only restrict
 * which duties are *returned* — the coverage counts always describe the
 * whole period, whatever month is on screen.
 */
export function fetchPlanningResult(
  planningStableId: string,
  filter: { from?: string; to?: string; period?: string } = {},
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
): Promise<CalendarWriteResult> {
  return apiFetch<CalendarWriteResult>(`/api/plannings/${planningStableId}/duties/${dutyStableId}/reassign`, {
    method: 'POST',
    body: { teamMemberStableId, expectedCurrentTeamMemberStableId },
  })
}

/**
 * "Retirer l'affectation" (docs/decisions.md D144): the whole block becomes
 * uncovered on purpose. `expectedCurrentTeamMemberStableId` is the holder the
 * editor showed; a stale one fails with a 409.
 */
export function unassignDuty(
  planningStableId: string,
  dutyStableId: string,
  expectedCurrentTeamMemberStableId: string,
): Promise<CalendarWriteResult> {
  return apiFetch<CalendarWriteResult>(`/api/plannings/${planningStableId}/duties/${dutyStableId}/unassign`, {
    method: 'POST',
    body: { expectedCurrentTeamMemberStableId },
  })
}

/**
 * "Compléter automatiquement" (docs/decisions.md D145): fills the holes only, every existing
 * assignment kept. Answers at once with a QUEUED job run by the worker (D149).
 */
export function completePlanning(
  planningStableId: string,
  periodStableId?: string,
): Promise<PlanningJobResponse> {
  return apiFetch<PlanningJobResponse>(
    withPeriod(`/api/plannings/${planningStableId}/complete`, periodStableId),
    {
      method: 'POST',
      body: {},
    },
  )
}

/** Published or not, since when, and — for a manager — what changed since the last diffusion. */
export function fetchPublicationState(
  planningStableId: string,
  periodStableId?: string,
): Promise<PublicationState> {
  return apiFetch<PublicationState>(
    withPeriod(`/api/plannings/${planningStableId}/publication-state`, periodStableId),
  )
}

/**
 * "Republier les modifications": emails only the people concerned by an
 * impacted date. `newLines`: lines never published yet, published with it —
 * only when chosen explicitly (docs/decisions.md D196).
 */
export function republishPlanning(
  planningStableId: string,
  newLines: string[] = [],
  periodStableId?: string,
): Promise<PublicationResult> {
  return apiFetch<PublicationResult>(
    withPeriod(`/api/plannings/${planningStableId}/republish`, periodStableId),
    {
      method: 'POST',
      body: newLines.length > 0 ? { newLines } : {},
    },
  )
}

/** The PDF of the last diffusion, exactly as it was published. */
export function fetchPublicationPdf(planningStableId: string, periodStableId?: string): Promise<Blob> {
  return apiFetchBlob(withPeriod(`/api/plannings/${planningStableId}/publication.pdf`, periodStableId))
}

/** The stored PDF of one past publication, byte for byte (docs/decisions.md D173, D196). */
export function fetchPublicationHistoryPdf(
  planningStableId: string,
  publicationStableId: string,
): Promise<Blob> {
  return apiFetchBlob(`/api/plannings/${planningStableId}/publications/${publicationStableId}/pdf`)
}

/**
 * Duty counts by weekday — the selected period, the published cumulative and
 * the provisional line periods (docs/decisions.md D132, D201). Purely
 * descriptive, read from the live current calendar state; never a fairness
 * judgment. `periodStableId` is required by the server on a planning with
 * several periods.
 */
export function fetchPlanningStatistics(
  planningStableId: string,
  periodStableId?: string,
): Promise<PlanningStatistics> {
  return apiFetch<PlanningStatistics>(
    withPeriod(`/api/plannings/${planningStableId}/statistics`, periodStableId),
  )
}

/**
 * Read-only: whether the *current* calendar (never the solver's original
 * historical result) can really be published right now (docs/decisions.md
 * D133).
 */
export function fetchPublicationPreflight(
  planningStableId: string,
  periodStableId?: string,
): Promise<PublicationPreflight> {
  return apiFetch<PublicationPreflight>(
    withPeriod(`/api/plannings/${planningStableId}/publication-preflight`, periodStableId),
  )
}

/**
 * Publishes the planning — the server always re-runs the real preflight
 * here, never trusting a GET loaded moments earlier. Without `lines`, all or
 * nothing; with `lines`, those lines only (docs/decisions.md D196).
 */
export function publishPlanning(
  planningStableId: string,
  lines?: string[],
  periodStableId?: string,
): Promise<PublicationResult> {
  return apiFetch<PublicationResult>(
    withPeriod(`/api/plannings/${planningStableId}/publish`, periodStableId),
    {
      method: 'POST',
      body: lines ? { lines } : {},
    },
  )
}

/**
 * The answer of a reassignment or a removal: what it did to the
 * reinforcements depending on the changed duty (docs/decisions.md D165) —
 * computed by the backend, only presented here.
 */
export type CalendarWriteResult = { status: string; dependentImpacts?: DependentImpact[] }
