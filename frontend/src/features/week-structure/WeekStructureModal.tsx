import { useEffect, useState } from 'react'
import { Icon } from '../../components/Icon'
import { Overlay } from '../../components/Overlay'
import { fetchWeekStructure, saveWeekStructure } from './api'
import { WeekStructureEditor } from './WeekStructureEditor'
import { fromPayload, toPayload } from './weeklyStructure'
import type { WeekStructure } from './weeklyStructure'

type Props = {
  lineStableId: string
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
export function WeekStructureModal({ lineStableId, lineName, onClose, onSaved }: Props) {
  const [structure, setStructure] = useState<WeekStructure | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [dirty, setDirty] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    fetchWeekStructure(lineStableId)
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
  }, [lineStableId])

  async function save() {
    if (!structure) return
    setSaving(true)
    setError(null)
    try {
      await saveWeekStructure(lineStableId, toPayload(structure))
      onSaved?.()
      onClose()
    } catch {
      setError('Impossible d’enregistrer la structure hebdomadaire.')
      setSaving(false)
    }
  }

  return (
    <Overlay
      title={`Semaine type — ${lineName}`}
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
      <p className="muted settings-note">
        Une modification ne s’applique qu’aux prochaines générations — jamais aux plannings déjà générés ou
        publiés.
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
