import { useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { updatePlanningSettings } from './api'

type Props = {
  planningStableId: string
  /** The current deadline ("YYYY-MM-DD"), if any. */
  availabilityDeadline: string | null
  onClose: () => void
  /** The settings were saved: the page refreshes what depends on them. */
  onSaved: () => void
}

/**
 * "Paramètres du planning" — today, only the theoretical end of the
 * availability encoding. Said plainly in the dialog itself: the date is a
 * signal for people and blocks nothing (docs/decisions.md D127).
 */
export function PlanningSettingsModal({ planningStableId, availabilityDeadline, onClose, onSaved }: Props) {
  const [deadline, setDeadline] = useState(availabilityDeadline ?? '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function save(value: string) {
    setSaving(true)
    setError(null)
    try {
      await updatePlanningSettings(planningStableId, value === '' ? null : value)
      onSaved()
      onClose()
    } catch (err) {
      setError(errorMessage(err))
      setSaving(false)
    }
  }

  return (
    <Overlay
      title="Paramètres du planning"
      onClose={onClose}
      dismissible={!saving}
      footer={
        <>
          <button type="button" className="btn btn--secondary" onClick={onClose} disabled={saving}>
            Annuler
          </button>
          <button
            type="button"
            className="btn btn--primary"
            onClick={() => save(deadline)}
            disabled={saving || deadline === (availabilityDeadline ?? '')}
          >
            {saving ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </>
      }
    >
      <div className="field">
        <label htmlFor="planning-availability-deadline" className="field__label">
          Fin souhaitée d’encodage des indisponibilités
        </label>
        <input
          id="planning-availability-deadline"
          type="date"
          className="field__input"
          data-autofocus
          value={deadline}
          onChange={(event) => setDeadline(event.target.value)}
          disabled={saving}
        />
      </div>
      <p className="muted settings-note">
        Cette date est <strong>indicative</strong> : elle sert à situer les membres et à repérer les retards,
        mais elle ne bloque jamais rien. Après cette date, chacun peut encore ajouter ou modifier ses
        indisponibilités et les confirmer, vous pouvez la déplacer à tout moment, et la génération du planning
        reste possible.
      </p>
      {availabilityDeadline && (
        <button
          type="button"
          className="btn btn--ghost btn--sm"
          onClick={() => {
            setDeadline('')
            void save('')
          }}
          disabled={saving}
        >
          Effacer la date
        </button>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
    </Overlay>
  )
}

function errorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 422) {
    return 'Cette date est déjà passée : choisissez une date à venir, ou effacez-la.'
  }
  if (err instanceof ApiError && err.status === 409) {
    return 'Aucune collecte n’est ouverte pour ce planning : il n’y a pas de date à fixer.'
  }
  return 'Impossible d’enregistrer ce paramètre.'
}
