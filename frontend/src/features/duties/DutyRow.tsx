import { Link } from 'react-router-dom'
import { Icon } from '../../components/Icon'
import { daysBetween, monthShort, relativeDays } from '../dashboard/dates'
import { dutySpan, dutyWhat, formatDutyDates, reinforcementLabel } from './dutyDates'
import type { MyDuty } from './types'

/**
 * One duty unit of the signed-in user, in the dashboard's row style: date tile, days, planning and line.
 * `today` adds the "Dans 3 jours" pill (upcoming units only).
 */
export function DutyRow({ duty, today }: { duty: MyDuty; today?: Date }) {
  const { first } = dutySpan(duty)
  const reinforcement = reinforcementLabel(duty)
  return (
    <Link to={`/plannings/${duty.planningStableId}`} className="db-row">
      <span className="db-date-tile" aria-hidden="true">
        <span className="db-date-tile-day">{first.getDate()}</span>
        <span className="db-date-tile-mon">{monthShort(first)}</span>
      </span>
      <span className="db-row-text">
        <span className="db-row-title db-num">{formatDutyDates(duty)}</span>
        <span className="db-muted">
          {duty.planningName} · {duty.lineName} · {dutyWhat(duty)}
        </span>
        {reinforcement && (
          <span className={`db-pill db-pill-${reinforcement.tone} duty-reinforcement`}>
            {reinforcement.label}
          </span>
        )}
      </span>
      {today && (
        <span className="db-pill db-pill-live">
          {first < today ? 'En cours' : relativeDays(daysBetween(today, first))}
        </span>
      )}
      <span className="db-chevron">
        <Icon name="right" size={18} strokeWidth={2.2} />
      </span>
    </Link>
  )
}
