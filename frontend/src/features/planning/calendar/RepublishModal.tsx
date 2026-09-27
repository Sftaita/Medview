import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchPublicationPreflight, republishPlanning } from '../result/api'
import type { PublicationChange, PublicationPreflight, PublicationResult } from '../result/types'

type Props = {
  planningStableId: string
  changes: PublicationChange[]
  onClose: () => void
  onRepublished: (result: PublicationResult) => void
}

const DAY = new Intl.DateTimeFormat('fr-BE', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  timeZone: 'UTC',
})

function holder(person: { firstName: string; lastName: string } | null): string {
  return person ? `${person.firstName} ${person.lastName}` : 'Non attribué'
}

/**
 * "Republier les modifications" (docs/decisions.md D143): lists what
 * changed since the last diffusion and who will be told — only the people
 * concerned by an impacted date. The server re-checks the calendar and
 * recomputes the changes at the moment of the click; this list is a preview.
 */
export function RepublishModal({ planningStableId, changes, onClose, onRepublished }: Props) {
  const [preflight, setPreflight] = useState<PublicationPreflight | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<PublicationResult | null>(null)
  const inFlight = useRef(false)

  useEffect(() => {
    let cancelled = false
    fetchPublicationPreflight(planningStableId)
      .then((value) => {
        if (!cancelled) setPreflight(value)
      })
      .catch(() => {
        if (!cancelled) setLoadError('Impossible de contrôler le planning.')
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId])

  async function confirm() {
    if (inFlight.current) return
    inFlight.current = true
    setSending(true)
    setError(null)
    try {
      const value = await republishPlanning(planningStableId)
      setResult(value)
      onRepublished(value)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      inFlight.current = false
      setSending(false)
    }
  }

  // One row per changed unit: a block's days share its group id and move together.
  const units: { key: string; dates: string[]; change: PublicationChange }[] = []
  for (const change of changes) {
    const key = change.groupInstanceStableId ?? change.dutyStableId
    const existing = units.find((unit) => unit.key === key)
    if (existing) existing.dates.push(change.date)
    else units.push({ key, dates: [change.date], change })
  }

  return (
    <Overlay
      title={result ? 'Modifications republiées' : 'Republier les modifications ?'}
      onClose={onClose}
      dismissible={!sending}
      footer={
        result ? (
          <button type="button" className="btn btn--primary" onClick={onClose} data-autofocus>
            Fermer
          </button>
        ) : (
          <>
            <button type="button" className="btn btn--secondary" onClick={onClose} disabled={sending}>
              Annuler
            </button>
            {preflight?.republishable && (
              <button
                type="button"
                className="btn btn--primary"
                onClick={confirm}
                disabled={sending}
                aria-busy={sending}
              >
                {sending ? 'Envoi…' : 'Republier'}
              </button>
            )}
          </>
        )
      }
    >
      {result ? (
        <p className="alert alert--success">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>
            Planning republié. {result.recipientCount} personne{result.recipientCount > 1 ? 's' : ''}{' '}
            concernée
            {result.recipientCount > 1 ? 's ont' : ' a'} été informée{result.recipientCount > 1 ? 's' : ''}{' '}
            par email.
          </span>
        </p>
      ) : (
        <>
          <ul className="cal-changes" aria-label="Modifications non publiées">
            {units.map(({ key, dates, change }) => (
              <li key={key}>
                <span className="cal-changes__when">
                  {dates.length > 1
                    ? `Du ${DAY.format(new Date(`${dates[0]}T00:00:00Z`))} au ${DAY.format(new Date(`${dates[dates.length - 1]}T00:00:00Z`))}`
                    : DAY.format(new Date(`${dates[0]}T00:00:00Z`))}{' '}
                  · {change.lineName}
                </span>
                <span>
                  {holder(change.before)} → <strong>{holder(change.after)}</strong>
                </span>
              </li>
            ))}
          </ul>
          <p className="muted">
            Un email détaillant ces modifications sera envoyé uniquement aux personnes concernées par les
            dates modifiées : l’ancien et le nouveau titulaire, et les personnes de garde ces mêmes jours sur
            les autres lignes.
          </p>
          {!preflight && !loadError && (
            <p role="status" className="muted">
              Contrôle du planning…
            </p>
          )}
          {preflight && !preflight.republishable && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>
                Republication impossible : le calendrier contient des affectations qui ne sont plus valides ou
                incohérentes. Corrigez-les d’abord.
              </span>
            </p>
          )}
        </>
      )}
      {(loadError || error) && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{loadError ?? error}</span>
        </p>
      )}
    </Overlay>
  )
}

function errorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    const code = (err.body as { error?: string } | null)?.error
    if (code === 'no_changes') return 'Il n’y a plus aucune modification à publier.'
    if (code === 'publication_in_progress') return 'Une publication de ce planning est déjà en cours.'
    if (code === 'not_publishable')
      return 'Le calendrier a changé et ne peut plus être republié tel quel. Fermez et réessayez.'
  }
  return 'La republication a échoué.'
}
