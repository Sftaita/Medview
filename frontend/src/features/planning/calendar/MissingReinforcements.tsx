import { useState } from 'react'
import { Icon } from '../../../components/Icon'
import { createMissingReinforcements } from '../coverage/api'
import type { PublicationUncoveredUnit } from '../result/types'
import { datesLabel } from './preflightIssues'

type Props = {
  units: PublicationUncoveredUnit[] | undefined
  periodStableId: string | null | undefined
  canEdit: boolean
  onCreated: () => void
}

function holders(unit: PublicationUncoveredUnit): string {
  return [
    ...new Set(
      unit.days.map((day) =>
        day.sourceHolder ? `${day.sourceHolder.firstName} ${day.sourceHolder.lastName}` : '',
      ),
    ),
  ]
    .filter(Boolean)
    .join(' et ')
}

/**
 * Reinforcements required since a reassignment of the source line, whose duty
 * does not exist yet (docs/decisions.md D233): shown as vacant in the calendar,
 * created empty only when a manager asks, even on a published line. Nothing is
 * assigned or sent.
 */
export function MissingReinforcements({ units, periodStableId, canEdit, onCreated }: Props) {
  const [busyLine, setBusyLine] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const missing = (units ?? []).filter((unit) => !unit.materialized)
  if (missing.length === 0) return null

  const byLine = new Map<string, PublicationUncoveredUnit[]>()
  for (const unit of missing) byLine.set(unit.lineStableId, [...(byLine.get(unit.lineStableId) ?? []), unit])

  async function create(lineStableId: string) {
    if (!periodStableId) return
    setBusyLine(lineStableId)
    setError(null)
    try {
      await createMissingReinforcements(lineStableId, periodStableId)
      onCreated()
    } catch {
      setError('Ces postes n’ont pas pu être créés. Réessayez.')
    } finally {
      setBusyLine(null)
    }
  }

  return (
    <div className="uncovered-posts">
      {[...byLine.entries()].map(([lineStableId, lineUnits]) => (
        <section key={lineStableId} className="alert alert--warning uncovered-posts__group">
          <Icon name="alert" size={18} strokeWidth={2} />
          <div className="preflight-issue__body">
            <strong>
              {lineUnits[0].lineName} —{' '}
              {lineUnits.length === 1
                ? 'un renfort devenu nécessaire n’a pas de titulaire'
                : `${lineUnits.length} renforts devenus nécessaires n’ont pas de titulaire`}
            </strong>
            <span className="preflight-issue__rule">
              Un changement de titulaire sur la ligne source a créé ce besoin. Ces gardes n’existent pas
              encore : créez-les, attribuez-les, puis {lineUnits[0].linePublished ? 'republiez' : 'publiez'}.
            </span>
            <ul
              className="uncovered-posts__list"
              aria-label={`Renforts à créer sur ${lineUnits[0].lineName}`}
            >
              {lineUnits.map((unit) => (
                <li key={unit.dates.join('|')}>
                  <div>
                    <strong>{datesLabel(unit.dates)}</strong>
                    <span className="preflight-issue__rule"> — {holders(unit)}</span>
                  </div>
                </li>
              ))}
            </ul>
            {canEdit && (
              <div>
                <button
                  type="button"
                  className="btn btn--sm btn--secondary"
                  disabled={busyLine !== null || !periodStableId}
                  aria-busy={busyLine === lineStableId}
                  onClick={() => void create(lineStableId)}
                >
                  {busyLine === lineStableId ? 'Création…' : 'Créer ces postes'}
                </button>
              </div>
            )}
          </div>
        </section>
      ))}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
    </div>
  )
}
