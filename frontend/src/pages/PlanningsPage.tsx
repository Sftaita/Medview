import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { createPlanning, fetchPlannings } from '../features/planning/api'
import type { PlanningSummary } from '../features/planning/types'
import { ApiError } from '../lib/apiClient'

export function PlanningsPage() {
  const [plannings, setPlannings] = useState<PlanningSummary[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [showForm, setShowForm] = useState(false)
  const [name, setName] = useState('')
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [timezone, setTimezone] = useState('Europe/Brussels')
  const [primaryTeamName, setPrimaryTeamName] = useState('')
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  function load() {
    setLoading(true)
    setError(null)
    fetchPlannings()
      .then(setPlannings)
      .catch(() => setError('Impossible de charger vos plannings.'))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    // eslint-disable-next-line react/set-state-in-effect
    load()
  }, [])

  async function handleCreate() {
    if (!name || !startsAt || !endsAt || !timezone || !primaryTeamName) {
      return
    }

    setSaving(true)
    setFormError(null)
    try {
      await createPlanning({ name, startsAt, endsAt, timezone, primaryTeam: { name: primaryTeamName } })
      setName('')
      setStartsAt('')
      setEndsAt('')
      setPrimaryTeamName('')
      setShowForm(false)
      load()
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setFormError('Une équipe a déjà un planning sur cette période.')
      } else if (err instanceof ApiError && err.status === 422) {
        setFormError('Les informations saisies sont invalides.')
      } else {
        setFormError('Une erreur est survenue. Merci de réessayer.')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <section>
      <h1>Mes plannings</h1>

      {loading && <p>Chargement…</p>}
      {error && (
        <p role="alert" className="availability-error">
          {error}
        </p>
      )}

      {!loading && !error && (
        <>
          {plannings.length === 0 && <p>Aucun planning pour le moment.</p>}
          <ul className="planning-list">
            {plannings.map((planning) => (
              <li key={planning.stableId}>
                <Link to={`/plannings/${planning.stableId}`}>{planning.name}</Link>
                <span>
                  {' '}
                  ({planning.startsAt} → {planning.endsAt})
                </span>
              </li>
            ))}
          </ul>
        </>
      )}

      {!showForm && (
        <button type="button" onClick={() => setShowForm(true)}>
          Créer un planning
        </button>
      )}

      {showForm && (
        <div className="planning-create-form">
          <h2>Créer un planning</h2>
          <label>
            Nom
            <input type="text" value={name} onChange={(event) => setName(event.target.value)} />
          </label>
          <label>
            Début
            <input type="date" value={startsAt} onChange={(event) => setStartsAt(event.target.value)} />
          </label>
          <label>
            Fin
            <input type="date" value={endsAt} onChange={(event) => setEndsAt(event.target.value)} />
          </label>
          <label>
            Fuseau horaire
            <input type="text" value={timezone} onChange={(event) => setTimezone(event.target.value)} />
          </label>
          <label>
            Nom de l'équipe principale
            <input
              type="text"
              value={primaryTeamName}
              onChange={(event) => setPrimaryTeamName(event.target.value)}
              placeholder="ex. Première ligne"
            />
          </label>

          {formError && (
            <p role="alert" className="availability-error">
              {formError}
            </p>
          )}

          <div className="availability-form-actions">
            <button
              type="button"
              onClick={handleCreate}
              disabled={saving || !name || !startsAt || !endsAt || !timezone || !primaryTeamName}
            >
              Créer
            </button>
            <button type="button" onClick={() => setShowForm(false)} disabled={saving}>
              Annuler
            </button>
          </div>
        </div>
      )}
    </section>
  )
}
