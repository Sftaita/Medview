import { apiFetch } from '../../../lib/apiClient'
import type { DemandPolicyInput, DemandPolicyView, DemandPreview } from './types'

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
