import { useCallback, useEffect, useRef, useState } from 'react'
import { Icon } from '../../components/Icon'
import { ApiError } from '../../lib/apiClient'
import { fetchMySurgicalHub, issueSurgicalHubCode, unlinkSurgicalHub } from './api'
import { formatSyncDate, SYNC_ERROR_MESSAGE } from './messages'
import type { SurgicalHubLinkCode, SurgicalHubState } from './types'

/** How long « Dissocier » waits for its confirming second click (same as « Tout effacer »). */
const CONFIRM_MS = 4000

function timeOf(iso: string): string {
  return new Date(iso).toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' })
}

/**
 * « Mon compte » → SurgicalHub (docs/surgicalhub-integration.md §4, §10).
 * The person generates a short-lived code here and types it into SurgicalHub
 * (Profil → Intégration MedVue), or gives it to a SurgicalHub administrator.
 * The code is shown once and never stored in the page beyond this screen.
 */
export function SurgicalHubAccountSection() {
  const [state, setState] = useState<SurgicalHubState | null>(null)
  const [code, setCode] = useState<SurgicalHubLinkCode | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)
  const confirmTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const load = useCallback(async () => {
    try {
      setState(await fetchMySurgicalHub())
    } catch {
      setError('Impossible de charger l’état de l’association SurgicalHub.')
    }
  }, [])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
    return () => {
      if (confirmTimer.current) clearTimeout(confirmTimer.current)
    }
  }, [load])

  // The code is useless once expired: drop it from the screen then.
  useEffect(() => {
    if (!code) return
    const timer = setTimeout(
      () => setCode(null),
      Math.max(0, new Date(code.expiresAt).getTime() - Date.now()),
    )
    return () => clearTimeout(timer)
  }, [code])

  async function handleIssue() {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      setCode(await issueSurgicalHubCode())
    } catch (err) {
      setError(
        err instanceof ApiError && err.status === 429
          ? 'Vous avez généré beaucoup de codes récemment : réessayez un peu plus tard.'
          : 'Le code n’a pas pu être généré. Réessayez.',
      )
    } finally {
      setBusy(false)
    }
  }

  async function handleUnlink() {
    if (!confirming) {
      setConfirming(true)
      confirmTimer.current = setTimeout(() => setConfirming(false), CONFIRM_MS)
      return
    }
    if (confirmTimer.current) clearTimeout(confirmTimer.current)
    setConfirming(false)
    setBusy(true)
    setError(null)
    try {
      await unlinkSurgicalHub()
      setNotice(
        'Association supprimée. Vos congés SurgicalHub à venir ont été retirés de votre calendrier ; les congés passés et en cours sont conservés.',
      )
      await load()
    } catch {
      setError('La dissociation a échoué. Réessayez.')
    } finally {
      setBusy(false)
    }
  }

  if (state === null) {
    return error ? (
      <p role="alert" className="alert alert--error">
        <Icon name="alert" size={18} strokeWidth={2} />
        <span>{error}</span>
      </p>
    ) : null
  }

  const link = state.link
  const active = link?.status === 'ACTIVE'
  const suspended = link?.status === 'SUSPENDED'
  const unlinkButton = (
    <button
      type="button"
      className="btn btn--ghost btn--sm"
      onClick={() => void handleUnlink()}
      disabled={busy}
    >
      {confirming ? 'Confirmer : dissocier' : 'Dissocier SurgicalHub'}
    </button>
  )

  return (
    <section className="card sh-account" aria-labelledby="sh-account-title">
      <h2 id="sh-account-title" className="sh-account__title">
        SurgicalHub
      </h2>

      {active && link ? (
        <>
          <p>
            Associé au compte SurgicalHub de <strong>{link.surgicalHubName}</strong>{' '}
            {formatSyncDate(link.linkedAt)}
            {link.linkedByAdministrator ? ', par un administrateur SurgicalHub' : ''}.
          </p>
          <p className="muted">
            Vos congés SurgicalHub apparaissent automatiquement dans votre calendrier, en lecture seule.{' '}
            {link.lastSuccessfulSyncAt
              ? `Dernière reprise ${formatSyncDate(link.lastSuccessfulSyncAt)}.`
              : 'Première reprise dans quelques minutes.'}
            {link.lastSyncError && link.lastSyncAttemptAt !== link.lastSuccessfulSyncAt
              ? ` ${SYNC_ERROR_MESSAGE[link.lastSyncError]}`
              : ''}
          </p>
          <p className="muted">
            MedVue ne modifie jamais vos congés et n’envoie aucune donnée de planning à SurgicalHub.
          </p>
          {unlinkButton}
        </>
      ) : (
        <>
          {suspended && link && (
            <p role="status" className="alert alert--warning">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>
                SurgicalHub ne reconnaît plus votre association avec le compte de {link.surgicalHubName}
                {link.suspendedAt ? ` (constaté ${formatSyncDate(link.suspendedAt)})` : ''}. Vos congés repris
                sont conservés tels quels mais ne sont plus mis à jour. Saisissez un nouveau code dans
                SurgicalHub pour reprendre, ou dissociez : vos congés SurgicalHub à venir seront alors
                retirés.
              </span>
            </p>
          )}
          {link?.status === 'REVOKED_REMOTE' && (
            <p className="muted">
              L’association précédente a pris fin côté SurgicalHub
              {link.revokedAt ? ` ${formatSyncDate(link.revokedAt)}` : ''}.
            </p>
          )}
          {!state.available && (
            <p className="muted">
              La reprise des congés SurgicalHub n’est pas encore disponible sur ce serveur.
            </p>
          )}
          <p>
            Associez votre compte SurgicalHub pour retrouver automatiquement vos congés dans votre calendrier
            MedVue, sans les encoder deux fois.
          </p>
          {code ? (
            <div className="sh-account__code" role="status">
              <span className="muted">Votre code, valable jusqu’à {timeOf(code.expiresAt)} :</span>
              <strong className="sh-account__value tnum">{code.code}</strong>
              <span className="muted">
                Saisissez-le dans SurgicalHub, Profil → Intégration MedVue (ou donnez-le à un administrateur
                SurgicalHub). Il ne sert qu’une fois.
              </span>
            </div>
          ) : null}
          <button
            type="button"
            className="btn btn--secondary btn--sm"
            onClick={() => void handleIssue()}
            disabled={busy}
          >
            {code ? 'Générer un nouveau code' : 'Générer un code d’association'}
          </button>
          {suspended && unlinkButton}
        </>
      )}

      {notice && (
        <p role="status" className="alert alert--info">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>{notice}</span>
        </p>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
    </section>
  )
}
