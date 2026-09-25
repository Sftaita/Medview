import { useState } from 'react'
import { Field } from '../../../components/Field'
import { Icon } from '../../../components/Icon'
import type { PlanningLineSummary } from '../types'
import { ActionMenu } from './ActionMenu'

export type Face = { key: string; initials: string; me: boolean }

type Props = {
  lines: PlanningLineSummary[]
  /** Members' initials per team stableId, when known (pilot data, managers only). */
  facesByTeam: Record<string, Face[]>
  canManage: boolean
  saving: boolean
  onManageMembers: (line: PlanningLineSummary) => void
  onDeleteLine: (lineStableId: string) => void
  /** Resolves true once the line is created. */
  onAddLine: (name: string) => Promise<boolean>
}

const plural = (n: number, word: string) => `${n} ${word}${n > 1 ? 's' : ''}`

/** "Lignes de garde": each line, its members, "Membres", and (creator) add / delete a secondary line. */
export function LinesTab({
  lines,
  facesByTeam,
  canManage,
  saving,
  onManageMembers,
  onDeleteLine,
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
                {canManage && line.type === 'SECONDARY' && (
                  <ActionMenu
                    label={`Actions pour ${line.name}`}
                    items={[
                      {
                        label: 'Supprimer la ligne',
                        icon: 'trash',
                        danger: true,
                        onSelect: () => onDeleteLine(line.stableId),
                      },
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
