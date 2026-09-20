import { apiFetch } from '../../lib/apiClient'
import type { JoinedTeam } from '../auth/types'

/** What GET /api/invitations/{token} returns: enough to prefill the sign-up form, nothing internal. */
export type InvitationInfo = {
  email: string
  proposedFirstName: string
  proposedLastName: string
  teamName: string
  planningName: string
  inviterName: string
  expiresAt: string
  accountExists: boolean
}

export function fetchInvitation(token: string): Promise<InvitationInfo> {
  return apiFetch<InvitationInfo>(`/api/invitations/${encodeURIComponent(token)}`, { skipAuth: true })
}

export function acceptInvitation(token: string): Promise<{ joinedTeams: JoinedTeam[] }> {
  return apiFetch<{ joinedTeams: JoinedTeam[] }>(`/api/invitations/${encodeURIComponent(token)}/accept`, {
    method: 'POST',
    body: {},
  })
}

/** The API's `error` code of a failed invitation lookup/registration, when there is one. */
export function invitationErrorCode(body: unknown): string | null {
  return typeof (body as { error?: unknown } | null)?.error === 'string'
    ? (body as { error: string }).error
    : null
}

export function describeInvitationError(status: number, body: unknown): string {
  const code = invitationErrorCode(body)
  if (status === 404 || code === 'invitation_not_found') {
    return 'Cette invitation est introuvable. Vérifiez le lien reçu par email.'
  }
  if (code === 'invitation_expired') {
    return 'Cette invitation a expiré. Demandez à la personne qui vous a invité de vous en envoyer une nouvelle.'
  }
  if (code === 'invitation_revoked') {
    return 'Cette invitation a été annulée par la personne qui vous a invité.'
  }
  if (code === 'invitation_already_used') {
    return 'Cette invitation a déjà été utilisée. Si vous avez déjà créé votre compte, connectez-vous.'
  }
  if (status === 429) {
    return 'Trop de tentatives. Merci de réessayer dans un instant.'
  }
  return 'Impossible de charger cette invitation. Merci de réessayer.'
}
