import { useState } from 'react'
import { Icon } from '../../../components/Icon'
import { WeekStructureModal } from '../../week-structure'
import { formatLongDate } from './format'
import { GenerationModal } from './GenerationModal'
import { PlanningSettingsModal } from './PlanningSettingsModal'
import { RuleSetModal } from './RuleSetModal'
import type { CollectionStatus } from './types'

type Props = {
  planningStableId: string
  timezone: string
  /** The pilot data, once loaded (the deadline lives there). */
  status: CollectionStatus | null
  /** The primary line's stableId (docs/decisions.md D136) — its weekly structure
   * is what "Semaine type" edits. Absent only while the planning itself is still
   * loading, in which case the button is not shown. */
  primaryLineStableId?: string
  primaryLineName?: string
  /** Settings saved or generation created: the page refreshes the pilot data. */
  onChanged: () => void
  /** A generation was created: the page refreshes what displays assignments. */
  onGenerated: () => void
}

/**
 * The actions of the planning header for an OWNER/ADMIN — "Paramètres", "Semaine
 * type" (docs/decisions.md D136) and the primary "Générer le planning" — and the
 * informative deadline line. None is ever disabled by the deadline or by members
 * who have not answered.
 */
export function PilotHeaderActions({
  planningStableId,
  timezone,
  status,
  primaryLineStableId,
  primaryLineName,
  onChanged,
  onGenerated,
}: Props) {
  const [dialog, setDialog] = useState<'settings' | 'generate' | 'week-structure' | 'rule-set' | null>(null)

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
        {primaryLineStableId && (
          <button type="button" className="btn btn--secondary" onClick={() => setDialog('week-structure')}>
            <Icon name="calendar" size={18} strokeWidth={2} />
            Semaine type
          </button>
        )}
        {primaryLineStableId && (
          <button type="button" className="btn btn--secondary" onClick={() => setDialog('rule-set')}>
            <Icon name="pencil" size={18} strokeWidth={2} />
            Règles de génération
          </button>
        )}
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
      {dialog === 'week-structure' && primaryLineStableId && (
        <WeekStructureModal
          lineStableId={primaryLineStableId}
          lineName={primaryLineName ?? 'Ligne principale'}
          onClose={() => setDialog(null)}
          onSaved={onChanged}
        />
      )}
      {dialog === 'rule-set' && primaryLineStableId && (
        <RuleSetModal
          lineStableId={primaryLineStableId}
          lineName={primaryLineName ?? 'Ligne principale'}
          timezone={timezone}
          onClose={() => setDialog(null)}
          onActivated={onChanged}
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
