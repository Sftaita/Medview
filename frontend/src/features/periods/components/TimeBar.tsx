import type { ISODate } from '../types'
import { monthSegments, timeState, type TimeKind } from '../dates'

interface SimpleProps {
  kind: TimeKind
  elapsedPct: number
  thickness?: number
}

/** Barre de temps écoulé + repère « aujourd’hui ». Représente le temps, jamais la préparation. */
export function TimeBar({ kind, elapsedPct, thickness = 8 }: SimpleProps) {
  return (
    <span className={`mv-timebar mv-timebar--${kind}`} style={{ height: thickness }} aria-hidden="true">
      <span className="mv-timebar__fill" style={{ width: `${elapsedPct}%` }} />
      {kind === 'current' && <span className="mv-timebar__today" style={{ left: `${elapsedPct}%` }} />}
    </span>
  )
}

interface MonthProps {
  start: ISODate
  end: ISODate
  today: ISODate
}

/** Barre découpée par mois, pour la grande carte de période. */
export function MonthTimeBar({ start, end, today }: MonthProps) {
  const t = timeState({ start, end }, today)
  const months = monthSegments(start, end, today)
  return (
    <div className="mv-months" aria-label={t.label}>
      <div className="mv-months__row">
        {months.map((m) => (
          <div key={m.key} className="mv-months__seg" style={{ flex: `${m.days} 1 0px` }}>
            <span className={`mv-months__bar mv-timebar--${t.kind}`}>
              <span className="mv-timebar__fill" style={{ width: `${m.fillPct}%` }} />
            </span>
            <span className="mv-months__label">{m.label}</span>
          </div>
        ))}
      </div>
      {t.kind === 'current' && (
        <span className="mv-months__today" style={{ left: `${t.elapsedPct}%` }} aria-hidden="true" />
      )}
    </div>
  )
}
