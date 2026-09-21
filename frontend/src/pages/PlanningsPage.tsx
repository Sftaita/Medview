import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
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
  // Ticked by default: most creators are on the rota themselves. Independent of their right to manage the planning.
  const [includeMe, setIncludeMe] = useState(true)
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
      await createPlanning({
        name,
        startsAt,
        endsAt,
        timezone,
        primaryTeam: { name: primaryTeamName },
        includeMe,
      })
      setName('')
      setStartsAt('')
      setEndsAt('')
      setPrimaryTeamName('')
      setIncludeMe(true)
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

  const canSubmit = Boolean(name && startsAt && endsAt && timezone && primaryTeamName)

  return (
    <section className="page">
      <header className="page__header">
        <div>
          <h1>Mes plannings</h1>
          <p className="page__lead">
            Un planning regroupe une période de garde et ses lignes ; chaque ligne appartient à une équipe.
          </p>
        </div>
        {!showForm && (
          <button type="button" className="btn btn--primary" onClick={() => setShowForm(true)}>
            <Icon name="plus" size={18} strokeWidth={2} />
            Créer un planning
          </button>
        )}
      </header>

      {loading && (
        <p role="status" className="muted">
          Chargement…
        </p>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}

      {showForm && (
        <form
          className="card form planning-create"
          onSubmit={(event) => {
            event.preventDefault()
            void handleCreate()
          }}
        >
          <h2>Créer un planning</h2>
          <Field label="Nom" type="text" value={name} onChange={(event) => setName(event.target.value)} />
          <div className="form-grid">
            <Field
              label="Début"
              type="date"
              value={startsAt}
              onChange={(event) => setStartsAt(event.target.value)}
            />
            <Field
              label="Fin"
              type="date"
              value={endsAt}
              onChange={(event) => setEndsAt(event.target.value)}
              hint="Date exclue : pour finir le 31 décembre, saisissez le 1er janvier."
            />
          </div>
          <Field
            label="Fuseau horaire"
            type="text"
            value={timezone}
            onChange={(event) => setTimezone(event.target.value)}
          />
          <Field
            label="Nom de l'équipe principale"
            type="text"
            value={primaryTeamName}
            onChange={(event) => setPrimaryTeamName(event.target.value)}
            placeholder="ex. Première ligne"
            hint="Vous pourrez ajouter d'autres lignes de garde ensuite."
          />

          <label className="checkbox">
            <input
              type="checkbox"
              checked={includeMe}
              onChange={(event) => setIncludeMe(event.target.checked)}
            />
            <span>
              <strong>M&apos;inclure dans le planning</strong>
              <small>
                Vous serez l&apos;un des candidats à la répartition des gardes, soumis aux mêmes règles que
                les autres. Cela ne change rien à vos droits de gestion du planning.
              </small>
            </span>
          </label>

          {formError && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{formError}</span>
            </p>
          )}

          <div className="form-actions">
            <button type="submit" className="btn btn--primary" disabled={saving || !canSubmit}>
              Créer
            </button>
            <button
              type="button"
              className="btn btn--secondary"
              onClick={() => setShowForm(false)}
              disabled={saving}
            >
              Annuler
            </button>
          </div>
        </form>
      )}

      {!loading && !error && (
        <>
          {plannings.length === 0 && <p className="muted">Aucun planning pour le moment.</p>}
          <ul className="list plannings-list">
            {plannings.map((planning) => (
              <li key={planning.stableId}>
                <Link to={`/plannings/${planning.stableId}`} className="card plannings-list__item">
                  <span className="plannings-list__main">
                    <span className="plannings-list__name">{planning.name}</span>
                    <span className="muted tnum">
                      {planning.startsAt} → {planning.endsAt}
                    </span>
                  </span>
                  <Icon name="right" size={20} />
                </Link>
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  )
}
