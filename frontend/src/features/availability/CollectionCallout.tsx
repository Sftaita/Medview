import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '../../components/Icon'
import { ApiError } from '../../lib/apiClient'
import type { AcknowledgementKind, AvailabilityCollection } from './collectionTypes'
import { collectionDays, formatDeadline, formatMoment, formatWindow } from './collectionFormat'
import { useMyAvailability } from './useMyAvailability'

type Props = {
  collection: AvailabilityCollection
  /**
   * 'dashboard' offers "Renseigner mes disponibilités" (opens the calendar on
   * the right window) next to the quick "no unavailability" answer; 'calendar'
   * is shown on the calendar itself, where the user has just filled it in.
   */
  mode: 'dashboard' | 'calendar'
}

function acknowledgeErrorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    const code = (err.body as { error?: string } | null)?.error
    if (code === 'unavailability_exists') {
      return 'Vous avez encore des indisponibilités sur cette période : confirmez plutôt que vos disponibilités sont à jour.'
    }
    if (code === 'collection_closed') {
      return 'Cette collecte est clôturée : elle ne reçoit plus de réponse.'
    }
  }
  if (err instanceof ApiError && err.status === 403) {
    return "Vous n'êtes pas concerné(e) par cette collecte."
  }
  return 'Impossible d’enregistrer votre confirmation. Merci de réessayer.'
}

/**
 * One availability collection, from the point of view of the person who must
 * answer it: what is asked, until when, and the two explicit ways to answer
 * (docs/availability-collection.md §3, §8). "Answered" is only ever the
 * result of pressing one of these — having entered absences is not.
 */
export function CollectionCallout({ collection, mode }: Props) {
  const { ranges, acknowledge, reload } = useMyAvailability()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // `disabled` alone does not stop a fast double click (same reason as the login form).
  const busyRef = useRef(false)

  const response = collection.myResponse
  const windowLabel = formatWindow(collection.startsAt, collection.lastDay)
  const days = collectionDays(collection)
  // "No unavailability" would contradict absences already on screen for this window: do not even offer it.
  const hasAbsenceInWindow = (ranges ?? []).some(
    (range) => range.type === 'UNAVAILABLE' && range.end >= days.start && range.start <= days.end,
  )
  const answered = response?.status === 'ACKNOWLEDGED'
  const changedSince =
    answered &&
    response.lastAvailabilityChangeAt !== null &&
    response.acknowledgedAt !== null &&
    response.lastAvailabilityChangeAt > response.acknowledgedAt

  async function answer(kind: AcknowledgementKind) {
    if (busyRef.current) {
      return
    }
    busyRef.current = true
    setBusy(true)
    setError(null)
    try {
      await acknowledge(collection.stableId, kind)
    } catch (err) {
      setError(acknowledgeErrorMessage(err))
      // "You still have unavailabilities" means the server holds absences this screen does not show
      // (typically another tab): re-read the calendar so the screen agrees with the message.
      if (
        err instanceof ApiError &&
        (err.body as { error?: string } | null)?.error === 'unavailability_exists'
      ) {
        void reload()
      }
    } finally {
      busyRef.current = false
      setBusy(false)
    }
  }

  return (
    <section
      className={`callout${answered ? ' callout--done' : ''}`}
      aria-label={`Disponibilités ${windowLabel}`}
      data-collection={collection.stableId}
    >
      <div className="callout__icon">
        <Icon name={answered ? 'check' : 'calendarX'} size={22} strokeWidth={2} />
      </div>
      <div className="callout__body">
        <div className="eyebrow">{collection.planningName}</div>
        {answered ? (
          <>
            <h3>
              Disponibilités confirmées le{' '}
              <span className="tnum">{formatMoment(response.acknowledgedAt as string)}</span>
            </h3>
            <p className="muted">
              Pour {windowLabel}
              {response.acknowledgementKind === 'NO_UNAVAILABILITY' ? ' · aucune indisponibilité' : ''}
              {changedSince && (
                <>
                  {' '}
                  · calendrier modifié depuis le{' '}
                  <span className="tnum">{formatMoment(response.lastAvailabilityChangeAt as string)}</span>
                </>
              )}
            </p>
          </>
        ) : (
          <>
            <h3>Vos disponibilités sont attendues pour {windowLabel}</h3>
            <p className="muted">
              {collection.deadline
                ? `À renseigner avant le ${formatDeadline(collection.deadline)}`
                : 'À renseigner dès que possible'}
            </p>
          </>
        )}

        {!answered && (
          <div className="callout__actions">
            {mode === 'dashboard' && (
              <Link to={`/my-availability?collection=${collection.stableId}`} className="btn btn--primary">
                Renseigner mes disponibilités
              </Link>
            )}
            {mode === 'calendar' && (
              <button
                type="button"
                className="btn btn--primary"
                onClick={() => answer('CONFIRMED')}
                disabled={busy}
              >
                Confirmer que mes disponibilités sont à jour
              </button>
            )}
            {!hasAbsenceInWindow && (
              <button
                type="button"
                className="btn btn--secondary"
                onClick={() => answer('NO_UNAVAILABILITY')}
                disabled={busy}
              >
                Je n&apos;ai aucune indisponibilité sur cette période
              </button>
            )}
          </div>
        )}

        {answered && mode === 'dashboard' && (
          <div className="callout__actions">
            <Link
              to={`/my-availability?collection=${collection.stableId}`}
              className="btn btn--ghost btn--sm"
            >
              Ouvrir le calendrier
            </Link>
          </div>
        )}

        {error && (
          <p role="alert" className="alert alert--error">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>{error}</span>
          </p>
        )}
      </div>
    </section>
  )
}
