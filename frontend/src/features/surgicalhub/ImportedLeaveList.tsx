import { useState } from 'react'
import { formatDayLong, formatDayShort } from '../availability/calendarAxis'
import { periodToRange } from '../availability/periodMapping'
import type { UserAvailabilityPeriod } from '../availability/types'

type Props = {
  periods: UserAvailabilityPeriod[]
  todayIndex: number
  /** Removes one imported period no association keeps in sync any more. */
  onRemove: (stableId: string) => Promise<void>
}

/**
 * The upcoming SurgicalHub leave, read-only (docs/surgicalhub-integration.md §10):
 * each period can be consulted here and is changed in SurgicalHub. Once no
 * association keeps one in sync any more (revoked), its owner may remove it
 * here — otherwise it would stay read-only for ever (§9).
 */
export function ImportedLeaveList({ periods, todayIndex, onRemove }: Props) {
  const [removing, setRemoving] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const upcoming = periods
    .map((period) => ({ period, range: periodToRange(period) }))
    .filter(({ range }) => range.end >= todayIndex)
    .sort((a, b) => a.range.start - b.range.start)
  if (upcoming.length === 0) {
    return null
  }

  async function remove(stableId: string) {
    setRemoving(stableId)
    setError(null)
    try {
      await onRemove(stableId)
    } catch {
      setError('Ce congé n’a pas pu être retiré. Réessayez.')
    } finally {
      setRemoving(null)
    }
  }

  return (
    <section aria-label="Congés SurgicalHub" className="summary">
      <div className="eyebrow muted">Congés SurgicalHub</div>
      <ul className="list">
        {upcoming.map(({ period, range }) => (
          <li key={period.stableId}>
            {range.start === range.end
              ? formatDayLong(range.start)
              : `${formatDayShort(range.start)} → ${formatDayLong(range.end)}`}
            {period.deletable && (
              <>
                {' '}
                <span className="muted">· plus synchronisé</span>{' '}
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  disabled={removing !== null}
                  onClick={() => void remove(period.stableId)}
                >
                  Retirer
                </button>
              </>
            )}
          </li>
        ))}
      </ul>
      {upcoming.some(({ period }) => !period.deletable) && (
        <p className="muted">À modifier dans SurgicalHub — repris automatiquement ici.</p>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          {error}
        </p>
      )}
    </section>
  )
}
