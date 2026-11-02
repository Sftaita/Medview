import { useState } from 'react'
import { Field } from '../../../components/Field'
import { Icon } from '../../../components/Icon'
import { fillPlanText } from '../pilot/fillText'
import type { FillLinePlan } from '../pilot/types'
import '../pilot/fill.css'
import type { PlanningLineSummary } from '../types'
import { ActionMenu } from './ActionMenu'

export type Face = { key: string; initials: string; me: boolean }

type Props = {
  lines: PlanningLineSummary[]
  /** Members' initials per team stableId, when known (pilot data, managers only). */
  facesByTeam: Record<string, Face[]>
  canManage: boolean
  /** May open "Paramètres de la ligne" (weekly structure, coverage mode) — docs/decisions.md D167. */
  canConfigure?: boolean
  saving: boolean
  onManageMembers: (line: PlanningLineSummary) => void
  onDeleteLine: (lineStableId: string) => void
  onConfigureLine?: (line: PlanningLineSummary) => void
  /** "Règles de génération" of this line (docs/decisions.md D222) — every line, not only the primary one. */
  onLineRules?: (line: PlanningLineSummary) => void
  /**
   * What "Générer les postes non pourvus" would do with each line, by line stable id (docs/decisions.md D222):
   * its counters, and "Générer cette ligne" when it has something to receive. Managers only.
   */
  fillPlans?: Record<string, FillLinePlan>
  onGenerateLine?: (line: PlanningLineSummary) => void
  /** A computation is running on the planning: no second one from here. */
  busy?: boolean
  /** Resolves true once the line is created. */
  onAddLine: (name: string) => Promise<boolean>
}

const plural = (n: number, word: string) => `${n} ${word}${n > 1 ? 's' : ''}`

/** "Lignes de garde": each line, its members, "Membres", and (creator) add / delete a secondary line. */
export function LinesTab({
  lines,
  facesByTeam,
  canManage,
  canConfigure = false,
  saving,
  onManageMembers,
  onDeleteLine,
  onConfigureLine,
  onLineRules,
  fillPlans,
  onGenerateLine,
  busy = false,
  onAddLine,
}: Props) {
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')

  async function submit() {
    if (!name) return
    if (await onAddLine(name)) {
      setName('')
      setAdding(false)
    }
  }

  return (
    <div className="pd-stack">
      <p className="pd-help">
        Chaque ligne est un rôle de garde à couvrir chaque jour. La ligne principale est remplie en priorité.
      </p>
      <ul className="pd-card pd-list" aria-label="Lignes de garde">
        {lines.map((line) => {
          const faces = facesByTeam[line.team.stableId] ?? []
          const count = line.memberCount ?? faces.length
          return (
            <li key={line.stableId} className="pd-line">
              <div className="pd-line-main">
                <div className="pd-line-name">
                  <strong>{line.name}</strong>
                  <span className="pd-kind" data-kind={line.type}>
                    {line.type === 'PRIMARY' ? 'Principale' : 'Secondaire'}
                  </span>
                </div>
                <div className="pd-line-meta">
                  {faces.length > 0 && (
                    <span className="pd-faces" aria-hidden>
                      {faces.slice(0, 4).map((face) => (
                        <span key={face.key} className={face.me ? 'pd-face pd-avatar-me' : 'pd-face'}>
                          {face.initials}
                        </span>
                      ))}
                      {faces.length > 4 && <span className="pd-face pd-face-more">+{faces.length - 4}</span>}
                    </span>
                  )}
                  <span className="pd-muted">
                    {line.team.name} · {plural(count, 'membre')}
                  </span>
                </div>
                {fillPlans?.[line.stableId] && (
                  <LineFillState
                    plan={fillPlans[line.stableId]}
                    busy={busy}
                    onGenerate={onGenerateLine ? () => onGenerateLine(line) : undefined}
                  />
                )}
              </div>
              <div className="pd-line-actions">
                <button
                  type="button"
                  className="pd-btn pd-btn-secondary"
                  onClick={() => onManageMembers(line)}
                >
                  <Icon name="users" size={17} strokeWidth={2} />
                  Membres
                </button>
                {((canConfigure && (onConfigureLine || onLineRules)) ||
                  (canManage && line.type === 'SECONDARY')) && (
                  <ActionMenu
                    label={`Actions pour ${line.name}`}
                    items={[
                      ...(canConfigure && onConfigureLine
                        ? [
                            {
                              label: 'Paramètres de la ligne',
                              icon: 'settings' as const,
                              onSelect: () => onConfigureLine(line),
                            },
                          ]
                        : []),
                      ...(canConfigure && onLineRules
                        ? [
                            {
                              label: 'Règles de génération',
                              icon: 'check' as const,
                              onSelect: () => onLineRules(line),
                            },
                          ]
                        : []),
                      ...(canManage && line.type === 'SECONDARY'
                        ? [
                            {
                              label: 'Supprimer la ligne',
                              icon: 'trash' as const,
                              danger: true,
                              onSelect: () => onDeleteLine(line.stableId),
                            },
                          ]
                        : []),
                    ]}
                  />
                )}
              </div>
            </li>
          )
        })}
        {canManage &&
          (adding ? (
            <li>
              <form
                className="pd-inline-form"
                onSubmit={(event) => {
                  event.preventDefault()
                  void submit()
                }}
              >
                <Field
                  label="Nom de la ligne"
                  type="text"
                  value={name}
                  autoFocus
                  onChange={(event) => setName(event.target.value)}
                />
                <button type="submit" className="pd-btn pd-btn-primary" disabled={saving || !name}>
                  Ajouter
                </button>
                <button
                  type="button"
                  className="pd-btn pd-btn-ghost"
                  onClick={() => setAdding(false)}
                  disabled={saving}
                >
                  Annuler
                </button>
              </form>
            </li>
          ) : (
            <li>
              <button type="button" className="pd-add-line" onClick={() => setAdding(true)}>
                <Icon name="plus" size={18} strokeWidth={2.2} />
                Ajouter une ligne
              </button>
            </li>
          ))}
      </ul>
    </div>
  )
}

/**
 * One line's counters and "Générer cette ligne" (docs/decisions.md D222): how
 * many duties it holds and misses, and the action when it has something to
 * receive — otherwise, why not, in words.
 */
function LineFillState({
  plan,
  busy,
  onGenerate,
}: {
  plan: FillLinePlan
  busy: boolean
  onGenerate?: () => void
}) {
  const writable = (plan.action === 'GENERATE' || plan.action === 'COMPLETE') && plan.selectable
  const uncovered = plan.uncoveredRequiredDutyCount ?? 0
  return (
    <div className="pd-line-fill">
      {plan.generated ? (
        <span
          className={
            uncovered > 0 ? 'pd-line-fill__counts pd-line-fill__counts--gap' : 'pd-line-fill__counts'
          }
        >
          {plan.coveredRequiredDutyCount}/{plan.requiredDutyCount ?? 0} gardes attribuées
          {uncovered > 0 && ` · ${uncovered} non pourvue${uncovered > 1 ? 's' : ''}`}
        </span>
      ) : (
        <span className="pd-line-fill__counts">
          Jamais générée
          {plan.requiredDutyCount !== null &&
            ` · ${plan.requiredDutyCount} garde${plan.requiredDutyCount > 1 ? 's' : ''} à pourvoir`}
        </span>
      )}
      {writable && onGenerate ? (
        <button
          type="button"
          className="pd-btn pd-btn-secondary"
          onClick={onGenerate}
          disabled={busy}
          title={busy ? 'Un calcul est déjà en cours sur ce planning' : fillPlanText(plan)}
        >
          Générer cette ligne
        </button>
      ) : (
        plan.action !== 'NOTHING' && <span className="pd-line-fill__why">{fillPlanText(plan)}</span>
      )}
    </div>
  )
}
