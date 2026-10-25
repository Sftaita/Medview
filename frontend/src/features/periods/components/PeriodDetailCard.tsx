import type { ISODate, Period } from '../types'
import { formatDate, timeState } from '../dates'
import { STATUS_LABEL } from '../periods'
import { MonthTimeBar } from './TimeBar'
import { Icon } from './Icon'
import { plural } from '../../planning/pilot/format'

interface Props {
  period: Period
  today: ISODate
  latestNumber: number
  lineCount: number
  memberCount: number
  hasPrev: boolean
  hasNext: boolean
  /** A single period: no navigation between periods, no "new period" banner — the screen stays as simple as before. */
  single?: boolean
  onPrev: () => void
  onNext: () => void
  onGoLatest: () => void
  /** « Modifier les dates », for the creator (docs/decisions.md D207): the dialog says what may move. */
  onEditDates?: () => void
  /** docs/decisions.md D211: the exceptional correction of a published period's end (creator, a next period). */
  onRectifyEnd?: () => void
}

/** Grande carte de la période sélectionnée (style de la carte « Période du planning » actuelle). */
export function PeriodDetailCard({
  period,
  today,
  latestNumber,
  lineCount,
  memberCount,
  hasPrev,
  hasNext,
  single = false,
  onPrev,
  onNext,
  onGoLatest,
  onEditDates,
  onRectifyEnd,
}: Props) {
  const t = timeState(period, today)
  // History is a period over, published, with later ones (lot L7): never the current one, never a future one.
  const isHistory =
    t.kind === 'past' &&
    (period.status === 'publiee' || period.status === 'archivee') &&
    period.number < latestNumber

  return (
    <div className="mv-period-detail">
      <div className="mv-period-detail__head">
        <span className="mv-eyebrow mv-eyebrow--strong">
          Période {period.number} <span className="mv-sep">—</span>{' '}
          <span className={`mv-fg--${period.status}`}>{STATUS_LABEL[period.status]}</span>
        </span>
        <span className={`mv-time-pill mv-time-pill--${t.kind}`}>
          <i aria-hidden="true" />
          {t.label}
        </span>
      </div>

      {isHistory && (
        <div className="mv-banner mv-banner--history">
          <Icon name="eye" size={20} strokeWidth={1.9} />
          <span>
            <strong>Consultation de l’historique.</strong> La période {period.number} est{' '}
            {period.status === 'archivee' ? 'archivée' : 'publiée'} : elle reste intacte, quelles que soient
            les périodes préparées ensuite.
          </span>
          <button type="button" className="mv-btn mv-btn--ghost" onClick={onGoLatest}>
            Aller à la période {latestNumber} <Icon name="chevronRight" size={16} />
          </button>
        </div>
      )}
      {period.status === 'preparation' && !single && !period.generatedOn && (
        <div className="mv-banner mv-banner--new">
          <Icon name="info" size={20} strokeWidth={1.9} />
          <span>
            <strong>Nouvelle période.</strong> {plural(lineCount, 'ligne de garde', 'lignes de garde')} et{' '}
            {plural(memberCount, 'membre', 'membres')} du planning s’y appliquent déjà : rien n’est recréé.
            Aucune garde n’est encore attribuée.
          </span>
        </div>
      )}

      <div className="mv-dates">
        <div className="mv-dates__box">
          <span>Premier jour</span>
          <strong>{formatDate(period.start, 'card')}</strong>
        </div>
        <span className="mv-dates__arrow" aria-hidden="true">
          <Icon name="arrowRight" size={22} />
        </span>
        <div className="mv-dates__box">
          <span>Dernier jour inclus</span>
          <strong>{formatDate(period.end, 'card')}</strong>
        </div>
        <div className="mv-dates__box mv-dates__box--brand">
          <span>Durée</span>
          <strong>{t.days} jours</strong>
        </div>
      </div>

      <MonthTimeBar start={period.start} end={period.end} today={today} />

      <div className="mv-period-detail__foot">
        <span className="mv-muted">
          Du {formatDate(period.start, 'long')} au {formatDate(period.end, 'long')}
        </span>
        {onEditDates && (
          <button type="button" className="mv-btn mv-btn--ghost mv-btn--sm" onClick={onEditDates}>
            <Icon name="calendar" size={16} /> Modifier les dates
          </button>
        )}
        {onRectifyEnd && (
          <button type="button" className="mv-btn mv-btn--ghost mv-btn--sm" onClick={onRectifyEnd}>
            <Icon name="calendar" size={16} /> Corriger la fin…
          </button>
        )}
        {!single && (
          <div className="mv-row">
            <button
              type="button"
              className="mv-btn mv-btn--secondary mv-btn--sm"
              disabled={!hasPrev}
              onClick={onPrev}
            >
              <Icon name="chevronLeft" size={16} /> Période précédente
            </button>
            <button
              type="button"
              className="mv-btn mv-btn--secondary mv-btn--sm"
              disabled={!hasNext}
              onClick={onNext}
            >
              Période suivante <Icon name="chevronRight" size={16} />
            </button>
          </div>
        )}
      </div>
    </div>
  )
}
