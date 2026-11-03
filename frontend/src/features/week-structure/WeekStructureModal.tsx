import { useEffect, useState } from 'react'
import { Icon } from '../../components/Icon'
import { Overlay } from '../../components/Overlay'
import { HelpTip } from '../../components/HelpTip'
import {
  fetchWeekStructure,
  fetchWeekTemplate,
  saveWeekStructure,
  saveWeekTemplate,
  type WeekTemplateLine,
} from './api'
import { WeekStructureEditor } from './WeekStructureEditor'
import { fromPayload, toPayload } from './weeklyStructure'
import type { WeekStructure } from './weeklyStructure'

type Props = {
  /** The weekly structure of one line… */
  lineStableId?: string
  /** …or the general one of a planning (docs/decisions.md D227). */
  planningStableId?: string
  lineName: string
  onClose: () => void
  /** The structure was saved: the caller may want to refresh anything derived from it. */
  onSaved?: () => void
}

/**
 * "Semaine type" — read/replace a PlanningLine's weekly structure
 * (docs/decisions.md D136, docs/week-structure.md). Loads the current
 * structure on open, edits it through `WeekStructureEditor` unchanged, and
 * only ever writes on an explicit "Enregistrer" — never an optimistic
 * write, same convention as `PlanningSettingsModal`.
 */
export function WeekStructureModal({ lineStableId, planningStableId, lineName, onClose, onSaved }: Props) {
  const general = planningStableId !== undefined
  const [lines, setLines] = useState<WeekTemplateLine[]>([])
  const [structure, setStructure] = useState<WeekStructure | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [dirty, setDirty] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    const loaded = planningStableId
      ? fetchWeekTemplate(planningStableId).then((payload) => {
          if (!cancelled) setLines(payload.lines)
          return payload
        })
      : fetchWeekStructure(lineStableId ?? '')
    loaded
      .then((payload) => {
        if (!cancelled) setStructure(fromPayload(payload))
      })
      .catch(() => {
        if (!cancelled) setError('Impossible de charger la structure hebdomadaire.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [lineStableId, planningStableId])

  async function save() {
    if (!structure) return
    setSaving(true)
    setError(null)
    try {
      if (planningStableId) {
        await saveWeekTemplate(planningStableId, toPayload(structure))
      } else {
        await saveWeekStructure(lineStableId ?? '', toPayload(structure))
      }
      onSaved?.()
      onClose()
    } catch {
      setError(
        'Impossible d’enregistrer cette semaine type : elle ne doit couper aucun bloc à la limite entre deux périodes.',
      )
      setSaving(false)
    }
  }

  return (
    <Overlay
      title={general ? 'Semaine type du planning' : `Semaine type — ${lineName}`}
      onClose={onClose}
      dismissible={!saving}
      stableLayout
      footer={
        <>
          <button type="button" className="btn btn--secondary" onClick={onClose} disabled={saving}>
            Annuler
          </button>
          <button
            type="button"
            className="btn btn--primary"
            onClick={() => void save()}
            disabled={saving || loading || !dirty}
          >
            {saving ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </>
      }
    >
      {general && (
        <p className="week-template__intro">
          <span>Quels jours et quels blocs de garde existent ?</span>
          <HelpTip label="Semaine type">
            La semaine type définit la structure habituelle des gardes de ce planning. Elle peut être utilisée
            par toutes les lignes ou personnalisée pour certaines lignes si nécessaire.
          </HelpTip>
        </p>
      )}
      {loading && <p className="muted">Chargement…</p>}
      {!loading && structure && (
        <WeekStructureEditor
          value={structure}
          onChange={(next) => {
            setStructure(next)
            setDirty(true)
          }}
        />
      )}
      {general && lines.length > 0 && (
        <ul className="week-template__lines" aria-label="Ce que chaque ligne utilise">
          {lines.map((line) => (
            <li key={line.stableId}>
              <strong>{line.name}</strong>
              <span className="muted">
                {line.kind === 'GENERAL'
                  ? 'Semaine type générale'
                  : line.kind === 'INHERITED'
                    ? 'Blocs hérités de sa ligne source : non concernée'
                    : 'Semaine type personnalisée : non concernée'}
              </span>
            </li>
          ))}
        </ul>
      )}
      <p className="muted settings-note">
        Une modification ne s’applique qu’aux gardes créées ensuite — jamais aux gardes déjà générées ou
        publiées, qui restent telles quelles.
      </p>
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
    </Overlay>
  )
}
