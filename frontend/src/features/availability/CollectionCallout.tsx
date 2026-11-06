import { Link } from 'react-router-dom'
import { Icon } from '../../components/Icon'
import type { AvailabilityCollection } from './collectionTypes'
import { formatDeadline, formatWindow, lineNames, windowPhrase } from './collectionFormat'

type Props = {
  collection: AvailabilityCollection
  /** 'dashboard' links to the calendar opened on the right window; 'calendar' is shown on the calendar itself. */
  mode: 'dashboard' | 'calendar'
  /** Today, "YYYY-MM-DD" (local) — injectable for tests. */
  today?: string
}

function localToday(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** Why the dates are asked for, in the words of the server's reading (D234). */
function context(collection: AvailabilityCollection): string {
  const pending = collection.pending
  const lines = pending && pending.lines.length > 0 ? lineNames(pending.lines) : null
  if (pending?.reason === 'NEW_DATES') {
    return lines
      ? `Nouvelles dates de ${lines}, sans titulaire pour l’instant.`
      : 'Nouvelles dates, sans titulaire pour l’instant.'
  }
  return lines
    ? `Les gardes de ${lines} ne sont pas encore réparties : vos indisponibilités sur ces dates seront prises en compte.`
    : 'Les gardes ne sont pas encore réparties : vos indisponibilités sur ces dates seront prises en compte.'
}

/**
 * One availability collection that still waits for the user, from the point
 * of view of a participant (docs/decisions.md D214, D234): which dates are
 * still useful — from today on, only the lines not yet given their holders —
 * and until when an update is wished. The server returns only collections that
 * still ask for something, with those dates (`pending`): nothing is decided
 * here. Nothing to confirm — without any unavailability the person is
 * available, and the calendar stays editable after the (indicative) deadline.
 * A deadline already past is never shown as the next step.
 */
export function CollectionCallout({ collection, mode, today = localToday() }: Props) {
  const windowLabel = formatWindow(
    collection.pending?.from ?? collection.startsAt,
    collection.pending?.lastDay ?? collection.lastDay,
  )
  const deadline = collection.deadline
  const deadlinePassed = deadline !== null && deadline < today

  return (
    <section
      className="callout"
      aria-label={`Indisponibilités ${windowLabel}`}
      data-collection={collection.stableId}
    >
      <div className="callout__icon">
        <Icon name="calendarX" size={22} strokeWidth={2} />
      </div>
      <div className="callout__body">
        <div className="eyebrow">{collection.planningName}</div>
        <h3>Des absences {windowPhrase(windowLabel)}&nbsp;?</h3>
        <p className="muted">{context(collection)}</p>
        {deadline && !deadlinePassed && (
          <p className="muted">Date limite indicative : le {formatDeadline(deadline)}.</p>
        )}
        {deadline && deadlinePassed && (
          <p className="muted">
            La date limite indicative (le {formatDeadline(deadline)}) est passée : votre calendrier reste
            modifiable.
          </p>
        )}
        <p className="muted">
          Sans indisponibilité renseignée, vous êtes considéré comme disponible : aucune confirmation n’est
          nécessaire.
        </p>

        {mode === 'dashboard' && (
          <div className="callout__actions">
            <Link to={`/my-availability?collection=${collection.stableId}`} className="btn btn--primary">
              Ouvrir mon calendrier
            </Link>
          </div>
        )}
      </div>
    </section>
  )
}
