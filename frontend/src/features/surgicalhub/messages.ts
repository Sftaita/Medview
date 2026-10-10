import type { SurgicalHubSyncError } from './types'

/** What a failed synchronisation means for the person — never a technical detail. */
export const SYNC_ERROR_MESSAGE: Record<SurgicalHubSyncError, string> = {
  not_configured: 'La synchronisation avec SurgicalHub n’est pas disponible sur ce serveur.',
  unreachable: 'SurgicalHub est momentanément injoignable.',
  unauthorized: 'SurgicalHub a refusé la connexion de MedVue. Contactez le support.',
  rate_limited: 'SurgicalHub est très sollicité, réessayez dans quelques minutes.',
  window_too_large: 'Trop de congés à reprendre en une fois. Contactez le support.',
  server_error: 'SurgicalHub rencontre un problème.',
  invalid_response: 'La réponse de SurgicalHub est inattendue.',
  link_suspended:
    'SurgicalHub ne reconnaît plus votre association : vos congés repris sont conservés mais ne sont plus mis à jour.',
}

/** "le 12/10/2026 à 14:05", in the viewer's local time. */
export function formatSyncDate(iso: string): string {
  const date = new Date(iso)
  const day = date.toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric' })
  const time = date.toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' })

  return `le ${day} à ${time}`
}
