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
  /**
   * What "Compléter la période" would do with each line, by line stable id (docs/decisions.md D222):
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
        Chaque ligne est un rôle de garde. La ligne principale est remplie en priorité.
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
                <LineCoverageState
                  line={line}
                  onConfigure={canConfigure && onConfigureLine ? () => onConfigureLine(line) : undefined}
                />
                {fillPlans?.[line.stableId] && <LineFillState plan={fillPlans[line.stableId]} />}
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
                {((canConfigure && onConfigureLine) ||
                  (fillPlans?.[line.stableId] &&
                    onGenerateLine &&
                    lineCanReceive(fillPlans[line.stableId])) ||
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
                      ...(fillPlans?.[line.stableId] &&
                      onGenerateLine &&
                      !busy &&
                      lineCanReceive(fillPlans[line.stableId])
                        ? [
                            {
                              label: 'Générer cette ligne',
                              icon: 'arrow' as const,
                              onSelect: () => onGenerateLine(line),
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
 * When this line's duties are needed (docs/decisions.md D224), in words: a
 * reinforcement line with no condition yet says so and opens its editor —
 * never left to read as "aucune garde demandée" without a reason. An active
 * rule set never stands for configured triggers.
 */
function LineCoverageState({ line, onConfigure }: { line: PlanningLineSummary; onConfigure?: () => void }) {
  const coverage = line.coverage
  if (!coverage) return null
  if (coverage.mode === 'CONDITIONAL_ON_SOURCE_ASSIGNMENT') {
    const sourceName = coverage.source?.name ?? 'une autre ligne'
    if (coverage.triggerCount === 0) {
      return (
        <div className="pd-line-coverage pd-line-coverage--todo">
          <span>
            <Icon name="alert" size={15} strokeWidth={2.2} /> Conditions de renfort à configurer — renfort de{' '}
            {sourceName}
          </span>
          {onConfigure && (
            <button type="button" className="pd-btn pd-btn-secondary" onClick={onConfigure}>
              Configurer les conditions
            </button>
          )}
        </div>
      )
    }
    return (
      <div className="pd-line-coverage">
        <span>
          Renfort de {sourceName} · {coverage.triggerCount} chirurgien{coverage.triggerCount > 1 ? 's' : ''}{' '}
          déclencheur
          {coverage.triggerCount > 1 ? 's' : ''}
          {coverage.structureSource === 'INHERITED' && ` · blocs hérités de ${sourceName}`}
        </span>
        {onConfigure && (
          <button type="button" className="pd-link" onClick={onConfigure}>
            Modifier les conditions
          </button>
        )}
      </div>
    )
  }
  if (coverage.hasWeekStructure) {
    return (
      <div className="pd-line-coverage">
        <span>
          {coverage.weekStructure === 'GENERAL' ? 'Semaine type générale' : 'Semaine type personnalisée'}
        </span>
      </div>
    )
  }
  if (line.type === 'SECONDARY') {
    return (
      <div className="pd-line-coverage pd-line-coverage--todo">
        <span>
          <Icon name="alert" size={15} strokeWidth={2.2} /> Règles de couverture à configurer : ni semaine
          type, ni conditions de renfort
        </span>
        {onConfigure && (
          <button type="button" className="pd-btn pd-btn-secondary" onClick={onConfigure}>
            Configurer
          </button>
        )}
      </div>
    )
  }
  return null
}

function lineCanReceive(plan: FillLinePlan): boolean {
  return (plan.action === 'GENERATE' || plan.action === 'COMPLETE') && plan.selectable
}

/**
 * One line's counters (docs/decisions.md D222): how many duties it holds and
 * misses and, when it cannot receive anything, why not, in words. What it would
 * receive is "Compléter la période", in the header; this line alone is in its menu.
 */
function LineFillState({ plan }: { plan: FillLinePlan }) {
  const writable = lineCanReceive(plan)
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
      {(writable ? plan.extensionOnly : plan.action !== 'NOTHING') && (
        <span className="pd-line-fill__why">{fillPlanText(plan)}</span>
      )}
    </div>
  )
}
