import type { MemberCollectionState, MemberRole } from './types'

export const ROLE_LABEL: Record<MemberRole, string> = {
  OWNER: 'Propriétaire',
  ADMIN: 'Admin',
  MEMBER: 'Membre',
}

/** "Confirmé" / "En attente" — explicit answers only; having no unavailability never reads as confirmed. */
export const COLLECTION_STATE_TAG: Record<MemberCollectionState, { label: string; tone: string }> = {
  ACKNOWLEDGED: { label: 'Confirmé', tone: 'tag--green' },
  PENDING: { label: 'En attente', tone: 'tag--amber' },
  NOT_EXPECTED: { label: 'Non attendu', tone: '' },
}
