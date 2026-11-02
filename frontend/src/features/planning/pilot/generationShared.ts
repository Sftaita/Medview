import { ApiError } from '../../../lib/apiClient'
import type { RestPolicyChoice, SurgicalHubStaleRefusal } from './types'

/** Both rest policies off — the default of every launch dialog, never a value guessed (docs/decisions.md D137). */
export const NO_REST_POLICY: RestPolicyChoice = {
  legalMinRestEnabled: false,
  legalMinRestHours: null,
  teamMinRestEnabled: false,
  teamMinRestHours: null,
}

/** The SurgicalHub refusal of a launch (decision D8), or null for any other error. */
export function surgicalHubRefusal(err: unknown): SurgicalHubStaleRefusal | null {
  if (!(err instanceof ApiError) || (err.status !== 409 && err.status !== 403)) {
    return null
  }
  const body = err.body as Partial<SurgicalHubStaleRefusal> | null
  return body?.error === 'surgicalhub_data_stale' || body?.error === 'surgicalhub_override_forbidden'
    ? (body as SurgicalHubStaleRefusal)
    : null
}
