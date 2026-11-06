import { useState } from 'react'
import { HelpTip } from '../../../components/HelpTip'
import { Icon } from '../../../components/Icon'
import { WeekStructureModal } from '../../week-structure'
import { ActionMenu, type ActionMenuItem } from '../detail/ActionMenu'
import { FillModal } from './FillModal'
import { GenerationModal, type GenerationPeriod } from './GenerationModal'
import { COMPLETE_HELP, periodAction, UNFILLABLE_HELP } from './periodAction'
import type { FillLinePlan, PlanningJob } from './types'

export type PilotDialog = 'generate' | 'regenerate' | 'fill' | 'week-structure'

type Props = {
  planningStableId: string
  timezone: string
  /** "Modifier le nom" in the menu, for someone allowed to rename the planning. */
  onRename?: () => void
  /** The selected period (docs/planning-cycles.md §7.4): what the main action applies to. */
  period?: GenerationPeriod
  /** Settings saved or generation created: the page refreshes the pilot data. */
  onChanged: () => void
  /** A generation was accepted (a QUEUED job, docs/decisions.md D149): the page follows it. */
  onLaunched: (job: PlanningJob) => void
  /** False once the period is entirely published: replacing its calendars is not offered any more. */
  canRegenerate?: boolean
  /** May edit the general weekly structure of the planning (docs/decisions.md D227). */
  canManageStructure?: boolean
  /** A generation or a completion is queued or running: no second launch from here. */
  busy?: boolean
  /** A line of the period holds a calendar: the main action protects what is assigned (docs/decisions.md D222). */
  hasGeneratedLines?: boolean
  /** What each line of the selected period would receive; undefined while it is being read. */
  plans?: FillLinePlan[]
  /** Optional control of the open dialog, so the page can open one from elsewhere. */
  dialog?: PilotDialog | null
  onDialogChange?: (dialog: PilotDialog | null) => void
}

/**
 * The actions of the planning header for someone who generates it
 * (docs/decisions.md D228): ONE main action, always in the same place and the
 * same size whatever the period and its state — "Générer la période N",
 * "Compléter la période N" or what the period already is — and a "⋯" menu for
 * what concerns the planning itself. Nothing appears or disappears next to it
 * when another period is chosen.
 */
export function PilotHeaderActions({
  planningStableId,
  timezone,
  onRename,
  period,
  onChanged,
  onLaunched,
  canRegenerate = true,
  canManageStructure = false,
  busy = false,
  hasGeneratedLines = false,
  plans,
  dialog: controlledDialog,
  onDialogChange,
}: Props) {
  const [ownDialog, setOwnDialog] = useState<PilotDialog | null>(null)
  const dialog = controlledDialog !== undefined ? controlledDialog : ownDialog
  const setDialog = (next: PilotDialog | null) => {
    setOwnDialog(next)
    onDialogChange?.(next)
  }

  const action = periodAction({ number: period?.number ?? 1, hasGeneratedLines, plans, busy })

  const menu: ActionMenuItem[] = [
    ...(onRename ? [{ label: 'Modifier le nom', icon: 'pencil' as const, onSelect: onRename }] : []),
    ...(canManageStructure
      ? [{ label: 'Semaine type', icon: 'calendar' as const, onSelect: () => setDialog('week-structure') }]
      : []),
    ...(canRegenerate && hasGeneratedLines
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

  const launched = (job: PlanningJob) => {
    onChanged()
    onLaunched(job)
  }

  return (
    <div className="pd-head-actions">
      <div className="pd-main-action">
        {action.kind === 'generate' || action.kind === 'complete' ? (
          <button
            type="button"
            className="pd-btn pd-btn-primary pd-btn-lg pd-main-action__control"
            onClick={() => setDialog(action.kind === 'generate' ? 'generate' : 'fill')}
          >
            {action.label}
            {action.kind === 'generate' && <Icon name="arrow" size={18} strokeWidth={2.2} />}
          </button>
        ) : (
          <span
            role="status"
            className="pd-main-action__control pd-main-action__state"
            data-kind={action.kind}
          >
            {action.kind === 'busy' && <span className="cal-spinner" aria-hidden />}
            {action.kind === 'done' && <Icon name="check" size={18} strokeWidth={2.4} />}
            {(action.kind === 'manual' || action.kind === 'unfillable') && (
              <Icon name="alert" size={18} strokeWidth={2.2} />
            )}
            {action.label}
          </span>
        )}
        {/* The "?" keeps its place in every state: nothing around it ever moves. */}
        <span className="pd-main-action__help">
          {action.kind === 'complete' && <HelpTip label="Compléter la période">{COMPLETE_HELP}</HelpTip>}
          {action.kind === 'unfillable' && (
            <HelpTip label="Gardes sans membre éligible">{UNFILLABLE_HELP}</HelpTip>
          )}
          {action.kind === 'manual' && (
            <HelpTip label="Gardes à attribuer à la main">
              Une ligne publiée n’est jamais complétée automatiquement, sauf les nouvelles dates d’une période
              prolongée. Attribuez ces gardes une à une dans le calendrier, puis republiez.
            </HelpTip>
          )}
        </span>
      </div>
      {menu.length > 0 ? (
        <ActionMenu label="Plus d’actions" items={menu} large />
      ) : (
        <span className="pd-menu-placeholder" aria-hidden />
      )}

      {dialog === 'week-structure' && (
        <WeekStructureModal
          planningStableId={planningStableId}
          lineName="du planning"
          onClose={() => setDialog(null)}
          onSaved={onChanged}
        />
      )}
      {dialog === 'fill' && (
        <FillModal
          planningStableId={planningStableId}
          period={period}
          timezone={timezone}
          onClose={() => setDialog(null)}
          onLaunched={launched}
        />
      )}
      {dialog === 'regenerate' && (
        <GenerationModal
          planningStableId={planningStableId}
          period={period}
          replace
          onClose={() => setDialog(null)}
          onLaunched={launched}
        />
      )}
      {dialog === 'generate' && (
        <GenerationModal
          planningStableId={planningStableId}
          period={period}
          onClose={() => setDialog(null)}
          onLaunched={launched}
        />
      )}
    </div>
  )
}
