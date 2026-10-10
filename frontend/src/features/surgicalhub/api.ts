import { apiFetch } from '../../lib/apiClient'
import type { SurgicalHubLink, SurgicalHubLinkCode, SurgicalHubState, SurgicalHubSyncOutcome } from './types'

export function fetchMySurgicalHub(): Promise<SurgicalHubState> {
  return apiFetch<SurgicalHubState>('/api/me/surgicalhub')
}

export function issueSurgicalHubCode(): Promise<SurgicalHubLinkCode> {
  return apiFetch<SurgicalHubLinkCode>('/api/me/surgicalhub/link-code', { method: 'POST' })
}

export function unlinkSurgicalHub(): Promise<void> {
  return apiFetch<void>('/api/me/surgicalhub/link', { method: 'DELETE' })
}

export function syncSurgicalHub(): Promise<{
  outcome: SurgicalHubSyncOutcome
  link: SurgicalHubLink | null
}> {
  return apiFetch('/api/me/surgicalhub/sync', { method: 'POST' })
}
