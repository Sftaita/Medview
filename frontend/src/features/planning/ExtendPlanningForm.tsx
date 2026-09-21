import { useState } from 'react'
import { Field } from '../../components/Field'
import { Icon } from '../../components/Icon'
import { ApiError } from '../../lib/apiClient'
import { extendPlanning } from '../availability/collectionApi'
import { formatWindow } from '../availability/collectionFormat'
import type { ExtendPlanningResult } from '../availability/collectionTypes'
import type { PlanningDetail } from './types'

type Props = {
  planning: PlanningDetail
  /** Called once the extension went through, with what it opened. */
  onExtended: (result: ExtendPlanningResult) => void
}

/** "YYYY-MM-DD" the day before, for the "last day" wording of an exclusive end. */
function dayBefore(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day - 1)).toISOString().slice(0, 10)
}

function errorMessage(err: unknown): string {
  const code = err instanceof ApiError ? (err.body as { error?: string } | null)?.error : undefined
  if (code === 'no_new_range') {
    return "Ces dates n'ajoutent aucune nouvelle journée au planning : rien à collecter."
  }
  if (code === 'range_shrink_not_supported') {
    return 'Un planning ne peut qu’être prolongé : la nouvelle période doit contenir l’ancienne.'
  }
  if (code === 'planning_period_locked') {
    return 'Une ligne de ce planning est déjà validée ou publiée : elle ne peut pas encore être prolongée.'
  }
  if (err instanceof ApiError && err.status === 422) {
    return 'Les dates saisies sont invalides (une échéance ne peut pas être dans le passé).'
  }
  return 'Impossible de prolonger ce planning. Merci de réessayer.'
}

/**
 * Extends a planning and opens the availability collection for the added
 * dates only (docs/availability-collection.md §5). The end date is exclusive,
 * like everywhere else in the planning: the hint says so in plain words.
 */
export function ExtendPlanningForm({ planning, onExtended }: Props) {
  const [open, setOpen] = useState(false)
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [deadline, setDeadline] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const canSubmit = Boolean(
    (startsAt && startsAt < planning.startsAt) || (endsAt && endsAt > planning.endsAt),
  )

  async function submit() {
    if (busy || !canSubmit) {
      return
    }
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const result = await extendPlanning(planning.stableId, {
        startsAt: startsAt || undefined,
        endsAt: endsAt || undefined,
        deadline: deadline || undefined,
      })
      const opened = result.collections
        .map((collection) => formatWindow(collection.startsAt, dayBefore(collection.endsAt)))
        .join(' et ')
      setNotice(`Planning prolongé. Disponibilités attendues pour ${opened}.`)
      setOpen(false)
      setStartsAt('')
      setEndsAt('')
      setDeadline('')
      onExtended(result)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="card extend" aria-label="Prolonger le planning">
      <div className="section-title">
        <h2>Prolonger le planning</h2>
        {!open && (
          <button type="button" className="btn btn--secondary btn--sm" onClick={() => setOpen(true)}>
            <Icon name="plus" size={16} strokeWidth={2} />
            Prolonger
          </button>
        )}
      </div>
      {notice && (
        <p role="status" className="alert alert--success">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>{notice}</span>
        </p>
      )}
      {!open && !notice && (
        <p className="muted">
          Ajoutez de nouvelles dates : seules celles-ci seront demandées aux membres, jamais celles déjà
          confirmées.
        </p>
      )}
      {open && (
        <form
          className="form"
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <div className="form-grid">
            <Field
              label="Nouveau début (facultatif)"
              type="date"
              value={startsAt}
              max={planning.startsAt}
              onChange={(event) => setStartsAt(event.target.value)}
              hint={`Actuellement : ${planning.startsAt}`}
            />
            <Field
              label="Nouvelle fin"
              type="date"
              value={endsAt}
              min={planning.endsAt}
              onChange={(event) => setEndsAt(event.target.value)}
              hint={`Actuellement : ${planning.endsAt} — la date de fin est exclue (le planning s'arrête la veille).`}
            />
          </div>
          <Field
            label="Échéance de réponse (facultatif)"
            type="date"
            value={deadline}
            onChange={(event) => setDeadline(event.target.value)}
            hint="Date limite pour que chacun confirme ses disponibilités sur les nouvelles dates."
          />
          {error && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{error}</span>
            </p>
          )}
          <div className="form-actions">
            <button type="submit" className="btn btn--primary" disabled={busy || !canSubmit}>
              Prolonger et ouvrir la collecte
            </button>
            <button
              type="button"
              className="btn btn--secondary"
              onClick={() => setOpen(false)}
              disabled={busy}
            >
              Annuler
            </button>
          </div>
        </form>
      )}
    </section>
  )
}
