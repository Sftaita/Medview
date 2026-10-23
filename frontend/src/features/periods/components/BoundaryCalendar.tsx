import type { DayOption } from '../api'
import { formatDate } from '../dates'
import { dateReasonMessage } from '../errors'
import type { ISODate } from '../types'
import { monthGrid, shiftMonth } from '../useDateOptions'
import { Icon } from './Icon'

const MONTHS = [
  'janvier',
  'février',
  'mars',
  'avril',
  'mai',
  'juin',
  'juillet',
  'août',
  'septembre',
  'octobre',
  'novembre',
  'décembre',
]
const WEEKDAYS = [
  ['L', 'lundi'],
  ['M', 'mardi'],
  ['M', 'mercredi'],
  ['J', 'jeudi'],
  ['V', 'vendredi'],
  ['S', 'samedi'],
  ['D', 'dimanche'],
]

interface Props {
  /** "2027-01": the month shown. */
  month: string
  onMonthChange: (month: string) => void
  /** What the days are: « Dernier jour inclus », « Premier jour ». */
  label: string
  selected: ISODate | ''
  /** The date the period has now, marked as such. */
  current?: ISODate | null
  /** A day shown as fixed (the first day, when only the last one is chosen). */
  fixed?: ISODate | null
  optionOf: (date: ISODate) => DayOption | undefined
  onSelect: (date: ISODate) => void
  disabled?: boolean
}

/**
 * A month of days to choose a boundary of a period (docs/decisions.md D207).
 * Every day is a button — a refused one too: choosing it says why it is
 * refused (the server's reason, the block it would cut), never silently
 * nothing. Possible / impossible / not loaded yet are told by the label, a
 * symbol and the colour, never the colour alone.
 */
export function BoundaryCalendar({
  month,
  onMonthChange,
  label,
  selected,
  current,
  fixed,
  optionOf,
  onSelect,
  disabled = false,
}: Props) {
  const [year, monthIndex] = month.split('-').map(Number)
  const title = `${MONTHS[monthIndex - 1]} ${year}`

  return (
    <div className="mv-bcal" role="group" aria-label={`${label} — ${title}`}>
      <div className="mv-bcal__nav">
        <button
          type="button"
          className="mv-icon-btn mv-icon-btn--soft"
          aria-label="Mois précédent"
          onClick={() => onMonthChange(shiftMonth(month, -1))}
          disabled={disabled}
        >
          <Icon name="chevronLeft" size={18} />
        </button>
        <strong className="mv-bcal__title" aria-live="polite">
          {title}
        </strong>
        <button
          type="button"
          className="mv-icon-btn mv-icon-btn--soft"
          aria-label="Mois suivant"
          onClick={() => onMonthChange(shiftMonth(month, 1))}
          disabled={disabled}
        >
          <Icon name="chevronRight" size={18} />
        </button>
      </div>
      <div className="mv-bcal__grid">
        {WEEKDAYS.map(([short, long]) => (
          <abbr key={long} title={long} className="mv-bcal__weekday">
            {short}
          </abbr>
        ))}
        {monthGrid(month).map((day) => {
          const option = optionOf(day)
          const state = option === undefined ? 'unknown' : option.allowed ? 'allowed' : 'refused'
          const isSelected = day === selected
          const reasons = option && !option.allowed ? option.reasons.map(dateReasonMessage).join(' ') : ''
          const name = [
            formatDate(day, 'long'),
            state === 'refused' ? `impossible : ${reasons}` : state === 'allowed' ? 'possible' : '',
            day === current ? 'date actuelle' : '',
            day === fixed ? 'premier jour' : '',
          ]
            .filter(Boolean)
            .join(', ')
          return (
            <button
              key={day}
              type="button"
              className={[
                'mv-bcal__day',
                `is-${state}`,
                day.slice(0, 7) !== month ? 'is-outside' : '',
                isSelected ? 'is-selected' : '',
                day === current ? 'is-current' : '',
                day === fixed ? 'is-fixed' : '',
              ]
                .filter(Boolean)
                .join(' ')}
              aria-pressed={isSelected}
              aria-label={name}
              title={reasons || undefined}
              onClick={() => onSelect(day)}
              disabled={disabled}
            >
              <span>{Number(day.slice(8))}</span>
              {state === 'refused' && (
                <i className="mv-bcal__mark" aria-hidden="true">
                  ×
                </i>
              )}
            </button>
          )
        })}
      </div>
      <ul className="mv-bcal__legend" aria-hidden="true">
        <li>
          <i className="mv-bcal__swatch is-allowed" /> Possible
        </li>
        <li>
          <i className="mv-bcal__swatch is-refused">×</i> Impossible (touchez le jour pour savoir pourquoi)
        </li>
        <li>
          <i className="mv-bcal__swatch is-selected" /> Choisi
        </li>
      </ul>
    </div>
  )
}
