import { Icon } from '../../components/Icon'
import { formatDayLong, formatDayShort, WEEKDAY_SHORT, weekdayOf } from './calendarAxis'
import { TYPE_LABEL } from './labels'
import { countDays, type DayRange } from './selection'
import type { UserAvailabilityType } from './types'

const TYPES: UserAvailabilityType[] = ['UNAVAILABLE', 'PREFER_DUTY']

type Props = {
  ranges: DayRange[]
  activeType: UserAvailabilityType
  onRemove: (start: number, end: number) => void
}

function plural(count: number, one: string, many: string): string {
  return count > 1 ? many : one
}

function rangeLabel(range: DayRange): string {
  return range.start === range.end
    ? formatDayLong(range.start)
    : `${formatDayShort(range.start)} → ${formatDayLong(range.end)}`
}

function rangeSub(range: DayRange): string {
  const first = WEEKDAY_SHORT[weekdayOf(range.start)]
  if (range.start === range.end) {
    return first
  }
  return `${first} → ${WEEKDAY_SHORT[weekdayOf(range.end)]} · ${range.end - range.start + 1} jours`
}

/** Recap of the selection: count, then the runs grouped by nature (chips on a phone, rows on desktop). */
export function SelectionSummary({ ranges, activeType, onRemove }: Props) {
  const periods = ranges.filter((range) => range.end > range.start)
  const days = countDays(ranges)

  let title = 'Aucune date sélectionnée'
  let sub = 'Sélectionnez dans le calendrier'
  if (ranges.length > 0) {
    if (periods.length === 0) {
      title = plural(days, 'date sélectionnée', 'dates sélectionnées')
      sub = 'Dates isolées'
    } else if (periods.length === ranges.length) {
      title = plural(ranges.length, 'période sélectionnée', 'périodes sélectionnées')
      sub = `${days} jours au total`
    } else {
      title = 'sélections'
      sub = `${periods.length} période(s) · ${ranges.length - periods.length} date(s) isolée(s) · ${days} jours`
    }
  }
  const count = ranges.length === 0 ? 0 : periods.length === 0 ? days : ranges.length

  return (
    <section aria-label="Récapitulatif" className="summary">
      <div className="eyebrow muted">Récapitulatif</div>
      <div className="summary__count">
        <span
          className={`summary__number${
            ranges.length > 0
              ? activeType === 'PREFER_DUTY'
                ? ' summary__number--prefer'
                : ' summary__number--active'
              : ''
          }`}
        >
          {count}
        </span>
        <h2>{title}</h2>
      </div>
      <p className="summary__sub">{sub}</p>

      <div className="summary__groups">
        {TYPES.map((type) => {
          const own = ranges.filter((range) => range.type === type)
          if (own.length === 0) {
            return null
          }
          const ownPeriods = own.filter((range) => range.end > range.start)
          const ownDays = countDays(own)
          const heading =
            ownPeriods.length === own.length
              ? `${own.length} ${plural(own.length, 'période', 'périodes')} · ${ownDays} jours`
              : `${ownDays} ${plural(ownDays, 'date', 'dates')}`

          return (
            <div
              key={type}
              className={`summary__group ${type === 'UNAVAILABLE' ? 'summary__group--unavailable' : 'summary__group--prefer'}`}
            >
              <h3 className="summary__group-title">
                <span className="summary__dot" />
                {TYPE_LABEL[type]} — {heading}
              </h3>
              <ul className="list summary__items">
                {own.map((range) => {
                  const index = ownPeriods.findIndex((period) => period.start === range.start)
                  const badge =
                    range.end > range.start ? `${type === 'PREFER_DUTY' ? 'G' : 'P'}${index + 1}` : '·'
                  return (
                    <li
                      key={`${type}-${range.start}`}
                      className={`summary__item${range.end > range.start ? ' summary__item--period' : ''}`}
                    >
                      <span className="summary__item-badge" aria-hidden="true">
                        {badge}
                      </span>
                      <span className="summary__item-text">
                        {rangeLabel(range)}
                        <span className="summary__item-sub">{rangeSub(range)}</span>
                      </span>
                      <button
                        type="button"
                        className="summary__item-remove"
                        aria-label={`Retirer ${rangeLabel(range)}`}
                        onClick={() => onRemove(range.start, range.end)}
                      >
                        <Icon name="x" size={15} strokeWidth={2.4} />
                      </button>
                    </li>
                  )
                })}
              </ul>
            </div>
          )
        })}
        {ranges.length === 0 && (
          <p className="summary__empty">
            Aucune date sélectionnée.
            <br />
            Touchez ou glissez dans le calendrier.
          </p>
        )}
      </div>
    </section>
  )
}
