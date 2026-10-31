import type { ISODate, Period } from '../types'
import { timeState } from '../dates'
import { Icon } from './Icon'

interface Props {
  period: Period
  today: ISODate
  latestNumber: number
  onGoLatest: () => void
  /** « Modifier les dates », for the creator (docs/decisions.md D207): the dialog says what may move. */
  onEditDates?: () => void
  /** docs/decisions.md D211: the exceptional correction of a published period's end (creator, a next period). */
  onRectifyEnd?: () => void
}

/**
 * What can be done on the selected period, right under the period cards —
 * which stay the one place for its dates, status, progress and countdown.
 * The notice of a period consulted as history stays, with its way back to
 * the latest period. Nothing at all when there is nothing to do.
 */
export function PeriodActions({ period, today, latestNumber, onGoLatest, onEditDates, onRectifyEnd }: Props) {
  // History is a period over, published, with later ones (lot L7): never the current one, never a future one.
  const isHistory =
    timeState(period, today).kind === 'past' &&
    (period.status === 'publiee' || period.status === 'archivee') &&
    period.number < latestNumber

  if (!isHistory && !onEditDates && !onRectifyEnd) return null

  return (
    <div className="mv-period-actions" role="group" aria-label={`Actions de la période ${period.number}`}>
      {isHistory && (
        <p className="mv-period-actions__history">
          <Icon name="eye" size={18} strokeWidth={1.9} />
          <span>
            <strong>Consultation de l’historique.</strong> La période {period.number} est{' '}
            {period.status === 'archivee' ? 'archivée' : 'publiée'} : elle reste intacte, quelles que soient
            les périodes préparées ensuite.
          </span>
          <button type="button" className="mv-btn mv-btn--ghost mv-btn--sm" onClick={onGoLatest}>
            Aller à la période {latestNumber} <Icon name="chevronRight" size={16} />
          </button>
        </p>
      )}
      {(onEditDates || onRectifyEnd) && (
        <div className="mv-period-actions__buttons">
          {onEditDates && (
            <button type="button" className="mv-btn mv-btn--secondary mv-btn--sm" onClick={onEditDates}>
              <Icon name="calendar" size={16} /> Modifier les dates
            </button>
          )}
          {onRectifyEnd && (
            <button type="button" className="mv-btn mv-btn--secondary mv-btn--sm" onClick={onRectifyEnd}>
              <Icon name="calendar" size={16} /> Corriger la fin…
            </button>
          )}
        </div>
      )}
    </div>
  )
}
