import { apiFetch } from '../../../lib/apiClient'
import type { DemandPolicyInput, DemandPolicyView } from './types'

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
