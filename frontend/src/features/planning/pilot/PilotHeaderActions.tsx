import { useState } from 'react'
import { Icon } from '../../../components/Icon'
import { WeekStructureModal } from '../../week-structure'
import { ActionMenu, type ActionMenuItem } from '../detail/ActionMenu'
import { GenerationModal } from './GenerationModal'
import { PlanningSettingsModal } from './PlanningSettingsModal'
import { RuleSetModal } from './RuleSetModal'
import type { CollectionStatus } from './types'

export type PilotDialog = 'settings' | 'generate' | 'week-structure' | 'rule-set'

type Props = {
  planningStableId: string
  timezone: string
  /** The pilot data, once loaded (the deadline lives there). */
  status: CollectionStatus | null
  /** The primary line's stableId (docs/decisions.md D136) — its weekly structure
   * is what "Semaine type" edits. Absent only while the planning itself is still
   * loading, in which case the entry is not shown. */
  primaryLineStableId?: string
  primaryLineName?: string
  /** "Modifier le nom" in the menu, for someone allowed to rename the planning. */
  onRename?: () => void
  /** Settings saved or generation created: the page refreshes the pilot data. */
  onChanged: () => void
  /** A generation was created: the page refreshes what displays assignments. */
  onGenerated: () => void
  /** Optional control of the open dialog, so the page can open one from elsewhere (the empty "Planning" tab). */
  dialog?: PilotDialog | null
  onDialogChange?: (dialog: PilotDialog | null) => void
}

/**
 * The actions of the planning header for an OWNER/ADMIN: the primary "Générer le
 * planning" and a "⋯" menu — "Modifier le nom", "Paramètres", "Semaine type"
 * (docs/decisions.md D136), "Règles de génération". None is ever disabled by the
 * deadline or by members who have not answered; the deadline itself is shown in
 * the availability follow-up.
 */
export function PilotHeaderActions({
  planningStableId,
  timezone,
  status,
  primaryLineStableId,
  primaryLineName,
  onRename,
  onChanged,
  onGenerated,
  dialog: controlledDialog,
  onDialogChange,
}: Props) {
  const [ownDialog, setOwnDialog] = useState<PilotDialog | null>(null)
  const dialog = controlledDialog !== undefined ? controlledDialog : ownDialog
  const setDialog = (next: PilotDialog | null) => {
    setOwnDialog(next)
    onDialogChange?.(next)
  }

  const menu: ActionMenuItem[] = [
    ...(onRename ? [{ label: 'Modifier le nom', icon: 'pencil' as const, onSelect: onRename }] : []),
    { label: 'Paramètres', icon: 'settings', onSelect: () => setDialog('settings') },
    ...(primaryLineStableId
      ? [
          { label: 'Semaine type', icon: 'calendar' as const, onSelect: () => setDialog('week-structure') },
          { label: 'Règles de génération', icon: 'check' as const, onSelect: () => setDialog('rule-set') },
        ]
      : []),
  ]

  return (
    <div className="pd-head-actions">
      <button
        type="button"
        className="pd-btn pd-btn-primary pd-btn-lg pd-grow"
        onClick={() => setDialog('generate')}
      >
        Générer le planning
        <Icon name="arrow" size={18} strokeWidth={2.2} />
      </button>
      <ActionMenu label="Plus d’actions" items={menu} large />

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
