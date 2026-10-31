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

function days(dates: string[]): string {
  if (dates.length === 1) return `le ${day(dates[0])}`
  return `du ${day(dates[0])} au ${day(dates[dates.length - 1])}`
}

function planningLabel(impact: CalendarImpact): string {
  return impact.period.count > 1
    ? `${impact.planningName} – Période ${impact.period.number}`
    : impact.planningName
}

/**
 * docs/decisions.md D216 — said right after a save, never a second
 * confirmation: the new unavailable days fall in a planning already
 * generated. The unavailability is recorded; the planning is not adapted.
 * When a duty the person currently holds is on those days, the warning is
 * stronger. Each planning concerned is named.
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
              {impact.from === impact.to ? 'cette date appartient' : 'ces dates appartiennent'} au planning{' '}
              <strong>{planningLabel(impact)}</strong>, déjà généré
              {impact.generatedAt ? ` le ${DATE.format(new Date(impact.generatedAt))}` : ''}
              {impact.published ? ' et publié' : ''}.
            </p>
            <p>
              Votre indisponibilité a bien été enregistrée, mais elle ne modifiera pas automatiquement ce
              planning.
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
