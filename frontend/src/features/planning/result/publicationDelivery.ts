import type { PublicationResult } from './types'

/**
 * docs/decisions.md D172: an email the transport refused is not lost — the
 * server retries it (`app:publication-notifications:retry`) — but the person
 * who published is told, never shown "everyone was informed" when it is not
 * true yet.
 */
export function undeliveredMessage(
  result: Pick<PublicationResult, 'recipientCount' | 'sentCount'>,
): string | null {
  const missing = result.recipientCount - result.sentCount
  if (missing <= 0) return null
  return missing === 1
    ? '1 email n’a pas pu être envoyé pour l’instant : il sera renvoyé automatiquement.'
    : `${missing} emails n’ont pas pu être envoyés pour l’instant : ils seront renvoyés automatiquement.`
}
