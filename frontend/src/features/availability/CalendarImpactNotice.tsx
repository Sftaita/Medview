import { Icon } from '../../components/Icon'
import type { CalendarImpact } from './types'

type Props = {
  impacts: CalendarImpact[]
  onDismiss: () => void
}

const DATE = new Intl.DateTimeFormat('fr-BE', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'Europe/Brussels',
})
const DAY = new Intl.DateTimeFormat('fr-BE', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

/** "12 mars 2027" for a calendar date. */
function day(date: string): string {
  return DAY.format(new Date(`${date}T00:00:00Z`))
}

function nextDay(date: string): string {
  const next = new Date(`${date}T00:00:00Z`)
  next.setUTCDate(next.getUTCDate() + 1)
  return next.toISOString().slice(0, 10)
}

/** "le 3 mars 2027", "du 3 mars 2027 au 5 mars 2027", or several of them: consecutive days read as one range. */
function days(dates: string[]): string {
  const sorted = [...dates].sort()
  const ranges: [string, string][] = []
  for (const date of sorted) {
    const last = ranges[ranges.length - 1]
    if (last && nextDay(last[1]) === date) last[1] = date
    else ranges.push([date, date])
  }
  const parts = ranges.map(([from, to]) =>
    from === to ? `le ${day(from)}` : `du ${day(from)} au ${day(to)}`,
  )
  return parts.length === 1 ? parts[0] : `${parts.slice(0, -1).join(', ')} et ${parts[parts.length - 1]}`
}

function planningLabel(impact: CalendarImpact): string {
  return impact.period.count > 1
    ? `${impact.planningName} – Période ${impact.period.number}`
    : impact.planningName
}

/**
 * docs/decisions.md D216 / D218 — said right after a save, never a second
 * confirmation: the change touches days of a planning already generated —
 * days made unavailable, or days given back (an absence shrunk, moved or
 * deleted). The personal calendar changed; the generated planning did not.
 * When a duty the person currently holds is on a day made unavailable, the
 * warning is stronger. Each planning concerned is named.
 */
export function CalendarImpactNotice({ impacts, onDismiss }: Props) {
  if (impacts.length === 0) {
    return null
  }
  const withDuty = impacts.some((impact) => impact.conflicts.length > 0)

  return (
    <section
      role="status"
      aria-live="polite"
      aria-label="Plannings déjà générés"
      className={`alert ${withDuty ? 'alert--warning' : 'alert--info'} impact-notice`}
    >
      <Icon name="alert" size={18} strokeWidth={2} />
      <div className="impact-notice__body">
        {impacts.map((impact) => (
          <div key={impact.period.stableId} className="impact-notice__item">
            <p>
              <strong>Attention :</strong>{' '}
              {impact.addedDays.length + impact.releasedDays.length === 1
                ? 'cette date appartient'
                : 'ces dates appartiennent'}{' '}
              au planning <strong>{planningLabel(impact)}</strong>, déjà généré
              {impact.generatedAt ? ` le ${DATE.format(new Date(impact.generatedAt))}` : ''}
              {impact.published ? ' et publié' : ''}.
            </p>
            {impact.addedDays.length > 0 && <p>Indisponibilité ajoutée {days(impact.addedDays)}.</p>}
            {impact.releasedDays.length > 0 && (
              <p>Indisponibilité retirée {days(impact.releasedDays)} : vous y êtes de nouveau disponible.</p>
            )}
            <p>
              Votre calendrier personnel a bien été modifié, mais le planning généré reste inchangé : aucune
              garde n’est réattribuée automatiquement.
            </p>
            {impact.conflicts.map((conflict) => (
              <p key={`${conflict.lineName}-${conflict.dates.join()}`} className="impact-notice__duty">
                <strong>
                  Vous êtes déjà de garde {days(conflict.dates)} ({conflict.lineName}).
                </strong>{' '}
                Cette garde reste attribuée. Contactez le responsable du planning si une modification est
                nécessaire.
              </p>
            ))}
          </div>
        ))}
      </div>
      <button type="button" className="btn btn--ghost btn--sm" onClick={onDismiss}>
        Compris
      </button>
    </section>
  )
}
