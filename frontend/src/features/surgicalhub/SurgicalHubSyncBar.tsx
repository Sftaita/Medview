import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '../../components/Icon'
import { ApiError } from '../../lib/apiClient'
import { fetchMySurgicalHub, syncSurgicalHub } from './api'
import { formatSyncDate, SYNC_ERROR_MESSAGE } from './messages'
import type { SurgicalHubLink } from './types'

type Props = {
  /** Called after a synchronisation that may have changed the calendar. */
  onSynced: () => void
}

/**
 * The SurgicalHub line of the personal calendar (docs/surgicalhub-integration.md §10):
 * last successful synchronisation, « Synchroniser SurgicalHub », and what went
 * wrong in plain words. Renders nothing for someone who never associated an
 * account — the calendar works exactly as before for them.
 */
export function SurgicalHubSyncBar({ onSynced }: Props) {
  const [link, setLink] = useState<SurgicalHubLink | null>(null)
  const [syncing, setSyncing] = useState(false)
  const [message, setMessage] = useState<{ tone: 'info' | 'error'; text: string } | null>(null)

  const load = useCallback(async () => {
    try {
      setLink((await fetchMySurgicalHub()).link)
    } catch {
      // A summary line, never a gate: without it the calendar still works.
    }
  }, [])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  if (link === null) {
    return null
  }

  if (link.status === 'SUSPENDED') {
    return (
      <p role="status" className="alert alert--warning sh-bar">
        <Icon name="alert" size={18} strokeWidth={2} />
        <span>
          SurgicalHub ne reconnaît plus votre association : vos congés repris sont conservés tels quels mais
          ne sont plus mis à jour. <Link to="/account">Associer à nouveau ou dissocier</Link>
        </span>
      </p>
    )
  }
  if (link.status === 'REVOKED_REMOTE') {
    return (
      <p role="status" className="alert alert--info sh-bar">
        <Icon name="alert" size={18} strokeWidth={2} />
        <span>
          L’association avec SurgicalHub a pris fin côté SurgicalHub : vos congés ne sont plus repris.{' '}
          <Link to="/account">Associer à nouveau</Link>
        </span>
      </p>
    )
  }
  if (link.status !== 'ACTIVE') {
    return null
  }

  async function handleSync() {
    setSyncing(true)
    setMessage(null)
    try {
      const { outcome, link: next } = await syncSurgicalHub()
      setLink(next)
      if (outcome.status === 'SYNCED') {
        const changes = outcome.created + outcome.updated + outcome.removed
        setMessage({
          tone: 'info',
          text:
            changes === 0
              ? 'Vos congés SurgicalHub sont à jour.'
              : 'Vos congés SurgicalHub ont été mis à jour.',
        })
      } else if (outcome.status === 'FAILED' && outcome.error) {
        setMessage({
          tone: 'error',
          text: `${SYNC_ERROR_MESSAGE[outcome.error]} Vos congés déjà repris restent pris en compte.`,
        })
      }
      onSynced()
    } catch (err) {
      setMessage({
        tone: 'error',
        text:
          err instanceof ApiError && err.status === 429
            ? 'Vous avez synchronisé plusieurs fois récemment : réessayez un peu plus tard.'
            : 'La synchronisation n’a pas pu être lancée. Vérifiez votre connexion puis réessayez.',
      })
    } finally {
      setSyncing(false)
    }
  }

  const failedSinceSuccess =
    link.lastSyncError !== null &&
    (link.lastSuccessfulSyncAt === null || (link.lastSyncAttemptAt ?? '') > link.lastSuccessfulSyncAt)

  return (
    <div className="sh-bar card">
      <div className="sh-bar__text">
        <span className="sh-bar__source">SurgicalHub</span>
        <span className="muted">
          {link.lastSuccessfulSyncAt
            ? `Congés repris ${formatSyncDate(link.lastSuccessfulSyncAt)}`
            : 'Première reprise de vos congés en attente'}
        </span>
        {failedSinceSuccess && !message && link.lastSyncError && (
          <span className="sh-bar__warning">{SYNC_ERROR_MESSAGE[link.lastSyncError]}</span>
        )}
        {message && (
          <span role={message.tone === 'error' ? 'alert' : 'status'} className={`sh-bar__${message.tone}`}>
            {message.text}
          </span>
        )}
      </div>
      <button
        type="button"
        className="btn btn--secondary btn--sm"
        onClick={() => void handleSync()}
        disabled={syncing}
      >
        <Icon name="refresh" size={16} strokeWidth={2} />
        {syncing ? 'Synchronisation…' : 'Synchroniser SurgicalHub'}
      </button>
    </div>
  )
}
