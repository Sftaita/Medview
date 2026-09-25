import { Icon } from '../../../components/Icon'
import { daysInclusive, formatLongDate, lastDayOf, monthSegments, periodState, todayISO } from './period'

type Props = {
  startsAt: string
  /** Exclusive, as the API returns it. */
  endsAt: string
  timezone: string
  /** "Prolonger la période", for someone allowed to extend the planning. */
  onExtend?: () => void
}

/** "Période du planning": first and last day in large type, the length, a month timeline and today's position. */
export function PeriodCard({ startsAt, endsAt, timezone, onExtend }: Props) {
  const lastDay = lastDayOf(endsAt)
  const total = daysInclusive(startsAt, lastDay)
  const segments = monthSegments(startsAt, lastDay)
  const state = periodState(startsAt, lastDay, todayISO(timezone))

  return (
    <section className="pd-card pd-period" aria-label="Période du planning">
      <div className="pd-period-top">
        <span className="pd-eyebrow">Période du planning</span>
        <span className="pd-chip" data-kind={state.kind}>
          <span className="pd-dot" />
          {state.label}
        </span>
      </div>
      <div className="pd-period-row">
        <div className="pd-period-dates">
          <div className="pd-period-tile">
            <span className="pd-period-label">Du</span>
            <span className="pd-period-value">{formatLongDate(startsAt)}</span>
          </div>
          <span className="pd-period-arrow" aria-hidden>
            <Icon name="arrow" size={20} strokeWidth={2.2} />
          </span>
          <div className="pd-period-tile">
            <span className="pd-period-label">Au (inclus)</span>
            <span className="pd-period-value">{formatLongDate(lastDay)}</span>
          </div>
        </div>
        <div className="pd-period-tile pd-period-duration">
          <span className="pd-period-label">Durée</span>
          <span className="pd-period-value">{total} jours</span>
        </div>
      </div>
      <div className="pd-timeline" aria-hidden>
        <div className="pd-timeline-bar">
          {segments.map((segment) => (
            <span key={segment.key} style={{ flex: segment.days }} />
          ))}
          {state.kind === 'running' && (
            <span className="pd-timeline-today" style={{ left: `${state.progress * 100}%` }} />
          )}
        </div>
        <div className="pd-timeline-labels">
          {segments.map((segment) => (
            <span key={segment.key} style={{ flex: segment.days }}>
              {segment.label}
            </span>
          ))}
        </div>
      </div>
      <div className="pd-period-foot">
        <span>Fuseau horaire : {timezone}</span>
        {onExtend && (
          <button type="button" className="pd-link" onClick={onExtend}>
            <Icon name="calendarPlus" size={17} strokeWidth={2} />
            Prolonger la période
          </button>
        )}
      </div>
    </section>
  )
}
