import { useState } from 'react'
import { Icon } from '../../../components/Icon'
import { WeekStructureModal } from '../../week-structure'
import { ActionMenu, type ActionMenuItem } from '../detail/ActionMenu'
import { FillModal } from './FillModal'
import { GenerationModal, type GenerationPeriod } from './GenerationModal'
import { PlanningSettingsModal } from './PlanningSettingsModal'
import { RuleSetModal } from './RuleSetModal'
import type { CollectionStatus, PlanningJob } from './types'

export type PilotDialog = 'settings' | 'generate' | 'regenerate' | 'fill' | 'week-structure' | 'rule-set'

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
  /** "Prolonger la période" in the menu (a planning with a single period, docs/decisions.md D122). */
  onExtend?: () => void
  /** The selected period (docs/planning-cycles.md §7.4): what the generation and the deadline apply to. */
  period?: GenerationPeriod
  /** The main button's label — "Générer la période 2" once there are several. */
  generateLabel?: string
  /** Settings saved or generation created: the page refreshes the pilot data. */
  onChanged: () => void
  /** A generation was accepted (a QUEUED job, docs/decisions.md D149): the page follows it. */
  onLaunched: (job: PlanningJob) => void
  /** False once the planning is published: no general (re)generation is offered any more — the
   * server refuses it anyway (PERIOD_LOCKED); hiding the button is never the protection. */
  canLaunchGeneration?: boolean
  /** A generation or a completion is queued or running: no second launch from here. */
  busy?: boolean
  /**
   * docs/decisions.md D222: a line of the period is already generated. The main action then protects what is
   * assigned ("Générer les postes non pourvus"); replacing calendars is the distinct "Régénérer entièrement…".
   */
  hasGeneratedLines?: boolean
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
  onExtend,
  period,
  generateLabel = 'Générer le planning',
  onChanged,
  onLaunched,
  canLaunchGeneration = true,
  busy = false,
  hasGeneratedLines = false,
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
    ...(onExtend ? [{ label: 'Prolonger la période', icon: 'calendar' as const, onSelect: onExtend }] : []),
    { label: 'Paramètres', icon: 'settings', onSelect: () => setDialog('settings') },
    ...(primaryLineStableId
      ? [
          { label: 'Semaine type', icon: 'calendar' as const, onSelect: () => setDialog('week-structure') },
          { label: 'Règles de génération', icon: 'check' as const, onSelect: () => setDialog('rule-set') },
        ]
      : []),
    ...(canLaunchGeneration && hasGeneratedLines
      ? [
          {
            label: 'Régénérer entièrement…',
            icon: 'alert' as const,
            danger: true,
            onSelect: () => setDialog('regenerate'),
          },
        ]
      : []),
  ]

  return (
    <div className="pd-head-actions">
      {canLaunchGeneration && !hasGeneratedLines && (
        <button
          type="button"
          className="pd-btn pd-btn-primary pd-btn-lg pd-grow"
          onClick={() => setDialog('generate')}
          disabled={busy}
          title={busy ? 'Un calcul est déjà en cours sur ce planning' : undefined}
        >
          {generateLabel}
          <Icon name="arrow" size={18} strokeWidth={2.2} />
        </button>
      )}
      {/* docs/decisions.md D222: complete what is missing, line by line, never touching what is assigned —
          the main action as soon as a line holds a calendar. */}
      <button
        type="button"
        className={`pd-btn ${hasGeneratedLines ? 'pd-btn-primary pd-grow' : 'pd-btn-secondary'} pd-btn-lg`}
        onClick={() => setDialog('fill')}
        disabled={busy}
        title={busy ? 'Un calcul est déjà en cours sur ce planning' : undefined}
      >
        Générer les postes non pourvus
      </button>
      <ActionMenu label="Plus d’actions" items={menu} large />

      {dialog === 'settings' && (
        <PlanningSettingsModal
          planningStableId={planningStableId}
          periodStableId={period?.stableId}
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
      {dialog === 'fill' && (
        <FillModal
          planningStableId={planningStableId}
          period={period}
          timezone={timezone}
          onClose={() => setDialog(null)}
          onLaunched={(job) => {
            onChanged()
            onLaunched(job)
          }}
        />
      )}
      {dialog === 'regenerate' && (
        <GenerationModal
          planningStableId={planningStableId}
          period={period}
          replace
          onClose={() => setDialog(null)}
          onLaunched={(job) => {
            onChanged()
            onLaunched(job)
          }}
        />
      )}
      {dialog === 'generate' && (
        <GenerationModal
          planningStableId={planningStableId}
          period={period}
          onClose={() => setDialog(null)}
          onLaunched={(job) => {
            onChanged()
            onLaunched(job)
          }}
        />
      )}
    </div>
  )
}
