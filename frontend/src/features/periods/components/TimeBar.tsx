import type { TimeKind } from '../dates'

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
