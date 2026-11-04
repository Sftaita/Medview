import { apiFetch } from '../../../lib/apiClient'
import type {
  CoverageStartOutcome,
  CoverageStartPlan,
  DemandPolicyInput,
  DemandPolicyView,
  DemandPreview,
} from './types'

/** The line's active demand policy, its possible sources and the backend's warnings (docs/decisions.md D162). */
export function fetchDemandPolicy(lineStableId: string): Promise<DemandPolicyView> {
  return apiFetch<DemandPolicyView>(`/api/planning-lines/${lineStableId}/demand-policy`)
}

/**
 * Replaces the policy. The answer is the server's state afterwards — the only
 * truth the screen shows (an identical submission creates no new version).
 */
export function saveDemandPolicy(lineStableId: string, input: DemandPolicyInput): Promise<DemandPolicyView> {
  return apiFetch<DemandPolicyView>(`/api/planning-lines/${lineStableId}/demand-policy`, {
    method: 'PUT',
    body: input,
  })
}

/**
 * The reinforcement duties the configuration being edited would ask for, from the source line's current calendar
 * (docs/decisions.md D224) — computed by the server, nothing is saved.
 */
export function previewDemandPolicy(lineStableId: string, input: DemandPolicyInput): Promise<DemandPreview> {
  return apiFetch<DemandPreview>(`/api/planning-lines/${lineStableId}/demand-policy/preview`, {
    method: 'POST',
    body: input,
  })
}

/** "Début de couverture de cette ligne" (docs/decisions.md D233). */
export function fetchCoverageStart(lineStableId: string): Promise<{ coverageStartsOn: string | null }> {
  return apiFetch(`/api/planning-lines/${lineStableId}/coverage-start`)
}

/** What this date would do, computed by the server: nothing is written. */
export function previewCoverageStart(
  lineStableId: string,
  coverageStartsOn: string | null,
): Promise<CoverageStartPlan> {
  return apiFetch(`/api/planning-lines/${lineStableId}/coverage-start/preview`, {
    method: 'POST',
    body: { coverageStartsOn },
  })
}

/** Saves the date against the very plan the manager read (`confirmation`). */
export function saveCoverageStart(
  lineStableId: string,
  coverageStartsOn: string | null,
  confirmation: string,
): Promise<CoverageStartOutcome> {
  return apiFetch(`/api/planning-lines/${lineStableId}/coverage-start`, {
    method: 'PUT',
    body: { coverageStartsOn, confirmation },
  })
}

/** Creates, empty, the reinforcements required since a reassignment of the source line. */
export function createMissingReinforcements(
  lineStableId: string,
  periodStableId: string,
): Promise<{ createdDutyCount: number }> {
  return apiFetch(`/api/planning-lines/${lineStableId}/missing-reinforcements`, {
    method: 'POST',
    body: { periodStableId },
  })
}
