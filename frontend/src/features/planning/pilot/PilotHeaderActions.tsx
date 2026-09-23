import { useState } from 'react'
import { Icon } from '../../../components/Icon'
import { formatLongDate } from './format'
import { GenerationModal } from './GenerationModal'
import { PlanningSettingsModal } from './PlanningSettingsModal'
import type { CollectionStatus } from './types'

type Props = {
  planningStableId: string
  timezone: string
  /** The pilot data, once loaded (the deadline lives there). */
  status: CollectionStatus | null
  /** Settings saved or generation created: the page refreshes the pilot data. */
  onChanged: () => void
  /** A generation was created: the page refreshes what displays assignments. */
  onGenerated: () => void
}

/**
 * The two actions of the planning header for an OWNER/ADMIN — "Paramètres" and
 * the primary "Générer le planning" — and the informative deadline line.
 * Neither is ever disabled by the deadline or by members who have not answered.
 */
export function PilotHeaderActions({ planningStableId, timezone, status, onChanged, onGenerated }: Props) {
  const [dialog, setDialog] = useState<'settings' | 'generate' | null>(null)

  return (
    <div className="pilot-actions">
      {status?.availabilityDeadline && (
        <p className="pilot-actions__deadline">
          Fin souhaitée d’encodage : <strong>{formatLongDate(status.availabilityDeadline)}</strong>
          {status.deadlineOverdueDays !== null && <span className="tag tag--amber">Dépassée</span>}
        </p>
      )}
      <div className="pilot-actions__buttons">
        <button type="button" className="btn btn--secondary" onClick={() => setDialog('settings')}>
          <Icon name="pencil" size={18} strokeWidth={2} />
          Paramètres
        </button>
        <button type="button" className="btn btn--primary" onClick={() => setDialog('generate')}>
          <Icon name="arrow" size={18} strokeWidth={2} />
          Générer le planning
        </button>
      </div>

      {dialog === 'settings' && (
        <PlanningSettingsModal
          planningStableId={planningStableId}
          availabilityDeadline={status?.availabilityDeadline ?? null}
          onClose={() => setDialog(null)}
          onSaved={onChanged}
        />
      )}
      {dialog === 'generate' && (
        <GenerationModal
          planningStableId={planningStableId}
          timezone={timezone}
          onClose={() => setDialog(null)}
          onGenerated={() => {
            onChanged()
            onGenerated()
          }}
        />
      )}
    </div>
  )
}
