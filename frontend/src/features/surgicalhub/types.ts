/** docs/surgicalhub-integration.md §4, §10 — the owner's view of their association. */
export type SurgicalHubLinkStatus = 'ACTIVE' | 'SUSPENDED' | 'REVOKED_LOCAL' | 'REVOKED_REMOTE'

export type SurgicalHubSyncError =
  | 'not_configured'
  | 'unreachable'
  | 'unauthorized'
  | 'rate_limited'
  | 'window_too_large'
  | 'server_error'
  | 'invalid_response'
  | 'link_suspended'

export type SurgicalHubLink = {
  status: SurgicalHubLinkStatus
  surgicalHubName: string
  linkedByAdministrator: boolean
  linkedAt: string
  revokedAt: string | null
  /** When SurgicalHub answered that it did not know this association (§9). */
  suspendedAt?: string | null
  lastSyncAttemptAt: string | null
  lastSuccessfulSyncAt: string | null
  lastSyncError: SurgicalHubSyncError | null
}

export type SurgicalHubState = {
  /** False when this server cannot read SurgicalHub at all. */
  available: boolean
  /** The most recent association, whatever its status; null if there never was one. */
  link: SurgicalHubLink | null
}

export type SurgicalHubLinkCode = { code: string; expiresAt: string }

export type SurgicalHubSyncOutcome = {
  status: 'SYNCED' | 'FAILED' | 'REVOKED' | 'SUSPENDED' | 'INACTIVE'
  error: SurgicalHubSyncError | null
  created: number
  updated: number
  removed: number
}
