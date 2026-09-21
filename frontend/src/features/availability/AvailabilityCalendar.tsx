import type { PointerEvent as ReactPointerEvent } from 'react'
import { Icon } from '../../components/Icon'
import {
  dayOfMonth,
  formatDayFull,
  holidayName,
  isWeekend,
  type MonthInfo,
  WEEKDAY_SHORT,
} from './calendarAxis'
import { TYPE_LABEL } from './labels'
import { rangeAt, type DayRange } from './selection'
import type { UserAvailabilityType } from './types'
import './calendar.css'

const TYPE_CLASS: Record<UserAvailabilityType, string> = {
  UNAVAILABLE: 'day--unavailable',
  PREFER_DUTY: 'day--prefer',
}

const WEEKDAY_INITIALS = ['L', 'M', 'M', 'J', 'V', 'S', 'D']

type DragPreview = { from: number; to: number; moved: boolean } | null

type Props = {
  months: MonthInfo[]
  page: number
  visible: number
  /** Ranges to draw, drag preview included. */
  ranges: DayRange[]
  drag: DragPreview
  todayIndex: number
  onPrevious: () => void
  onNext: () => void
  onDayPointerDown: (day: number, event: ReactPointerEvent<HTMLElement>) => void
  onGridPointerMove: (event: ReactPointerEvent<HTMLElement>) => void
  onRailPointerMove: (event: ReactPointerEvent<HTMLElement>) => void
  onDayKeyboardToggle: (day: number) => void
}

function navLabel(months: MonthInfo[], page: number, visible: number): string {
  const first = months[page]
  const last = months[Math.min(months.length - 1, page + visible - 1)]
  if (!first) return ''
  if (visible === 1 || first === last) return first.name
  const firstName = first.name.split(' ')[0]
  return `${first.year === last.year ? firstName : first.name} — ${last.name}`
}

function rowsOf(month: MonthInfo): number {
  return Math.ceil((month.offset + month.days) / 7)
}

/**
 * Two months (desktop) or one (phone) on a rail of absolutely positioned
 * month panels; only the panels near the visible ones are mounted.
 */
export function AvailabilityCalendar({
  months,
  page,
  visible,
  ranges,
  drag,
  todayIndex,
  onPrevious,
  onNext,
  onDayPointerDown,
  onGridPointerMove,
  onRailPointerMove,
  onDayKeyboardToggle,
}: Props) {
  const mounted = months
    .map((month, index) => ({ month, index }))
    .filter(({ index }) => index >= page - 1 && index <= page + visible)
  const railRows = Math.max(...months.slice(page, page + visible).map(rowsOf), 5)

  const periodsByType: Record<UserAvailabilityType, DayRange[]> = {
    UNAVAILABLE: ranges.filter((r) => r.type === 'UNAVAILABLE' && r.end > r.start),
    PREFER_DUTY: ranges.filter((r) => r.type === 'PREFER_DUTY' && r.end > r.start),
  }
  const previewLow = drag && drag.moved ? Math.min(drag.from, drag.to) : null
  const previewHigh = drag && drag.moved ? Math.max(drag.from, drag.to) : null

  return (
    <div className="cal">
      <div className="cal__nav">
        <button
          type="button"
          className="icon-btn icon-btn--bordered"
          aria-label="Mois précédent"
          onClick={onPrevious}
          disabled={page === 0}
        >
          <Icon name="left" size={20} strokeWidth={2} />
        </button>
        <span className="cal__nav-label tnum" aria-live="polite">
          {navLabel(months, page, visible)}
        </span>
        <button
          type="button"
          className="icon-btn icon-btn--bordered"
          aria-label="Mois suivant"
          onClick={onNext}
          disabled={page >= months.length - visible}
        >
          <Icon name="right" size={20} strokeWidth={2} />
        </button>
      </div>

      <div
        className={`cal__rail${visible > 1 ? ' cal__rail--multi' : ''}`}
        data-rail=""
        style={{ ['--rows' as string]: railRows }}
        onPointerMove={onRailPointerMove}
      >
        {mounted.map(({ month, index }) => {
          const inView = index >= page && index < page + visible
          const rows = rowsOf(month)

          return (
            <div
              key={`${month.year}-${month.month}`}
              className="cal__month"
              data-month={`${month.year}-${month.month + 1}`}
              style={{ left: `${((index - page) * 100) / visible}%`, width: `${100 / visible}%` }}
              inert={!inView}
              aria-hidden={!inView}
            >
              <h3 className="cal__month-title">{month.name}</h3>
              <div className="cal__weekdays" aria-hidden="true">
                {WEEKDAY_SHORT.map((label, i) => (
                  <span key={label} data-long={label.slice(0, 3)} data-short={WEEKDAY_INITIALS[i]} />
                ))}
              </div>
              <div className="cal__grid" onPointerMove={onGridPointerMove}>
                {Array.from({ length: rows * 7 }, (_, cell) => {
                  const relative = cell - month.offset
                  if (relative < 0) {
                    return <span key={`blank-${cell}`} className="day day--blank" />
                  }
                  const day = month.start + relative
                  const outside = relative >= month.days
                  const range = rangeAt(ranges, day)
                  const holiday = holidayName(day)
                  const weekend = isWeekend(day)
                  const isStart = range !== null && day === range.start
                  const isEnd = range !== null && day === range.end
                  const single = range !== null && range.start === range.end
                  const continued = range !== null && !isStart && day === month.start
                  const list = range ? periodsByType[range.type] : []
                  const badge =
                    range && isStart && range.end > range.start && list.length > 1
                      ? `${range.type === 'PREFER_DUTY' ? 'G' : 'P'}${list.findIndex((r) => r.start === range.start) + 1}`
                      : null

                  const classes = [
                    'day',
                    range ? `day--selected ${TYPE_CLASS[range.type]}` : '',
                    isStart ? 'day--start' : '',
                    isEnd ? 'day--end' : '',
                    single ? 'day--single' : '',
                    continued ? 'day--continued' : '',
                    previewLow !== null && day >= previewLow && day <= (previewHigh ?? -1)
                      ? 'day--preview'
                      : '',
                    weekend || holiday ? 'day--off' : '',
                    outside ? 'day--outside' : '',
                    day === todayIndex ? 'day--today' : '',
                  ]
                    .filter(Boolean)
                    .join(' ')

                  const label = [
                    formatDayFull(day),
                    range ? TYPE_LABEL[range.type].toLowerCase() : null,
                    holiday ? `jour férié : ${holiday}` : weekend ? 'week-end' : null,
                  ]
                    .filter(Boolean)
                    .join(', ')

                  return (
                    <button
                      key={day}
                      type="button"
                      className={classes}
                      data-day={day}
                      aria-label={label}
                      aria-pressed={range !== null}
                      // The trailing days of the next month are drawn again in its own panel.
                      aria-hidden={outside || undefined}
                      tabIndex={outside ? -1 : undefined}
                      onPointerDown={(event) => onDayPointerDown(day, event)}
                      // A click without a pointer is a keyboard activation (detail === 0).
                      onClick={(event) => {
                        if (event.detail === 0) onDayKeyboardToggle(day)
                      }}
                    >
                      {range && <span className="day__band" />}
                      {range && (isStart || isEnd) && <span className="day__mark" />}
                      <span className="day__num">{dayOfMonth(day)}</span>
                      {badge && <span className="day__badge">{badge}</span>}
                      {holiday && <span className="day__foot">{holiday}</span>}
                    </button>
                  )
                })}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

export function CalendarLegend() {
  return (
    <ul className="list cal__legend">
      <li>
        <span className="cal__swatch cal__swatch--hover" />
        Survolée
      </li>
      <li>
        <span className="cal__swatch cal__swatch--unavailable" />
        Indisponibilité
      </li>
      <li>
        <span className="cal__swatch cal__swatch--prefer" />
        Préférence de garde
      </li>
      <li>
        <span className="cal__swatch cal__swatch--off" />
        Week-end · jour férié
      </li>
    </ul>
  )
}
