import { useEffect, useMemo, useState } from 'react'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { addDays, parseISO, startOfDay } from '../features/dashboard/dates'
import { PlanningCard } from '../features/periods/components/PlanningCard'
import { toCardData } from '../features/periods/model'
import '../features/periods/periodes.css'
import { createPlanning, fetchPlannings } from '../features/planning/api'
import '../features/planning/plannings.css'
import type { PlanningSummary } from '../features/planning/types'
import { ApiError } from '../lib/apiClient'

/**
 * Maquette docs/Design/react_mes_plannings (docs/decisions.md D169) — each
 * card is now the one of docs/Design/medvue-periodes (lot L6): one card per
 * planning, its current and next periods, each with its own status.
 */

type Phase = 'live' | 'upcoming' | 'done'

const GROUPS: { phase: Phase; title: string }[] = [
  { phase: 'live', title: 'En cours' },
  { phase: 'upcoming', title: 'À venir' },
  { phase: 'done', title: 'Terminés' },
]

/** First and last day of the planning, both included — the API's end date is exclusive. */
function rangeOf(planning: PlanningSummary): [Date, Date] {
  return [parseISO(planning.startsAt), addDays(parseISO(planning.endsAt), -1)]
}

/** "YYYY-MM-DD" of a local date — only when the server sends no `today` of the planning. */
function localDate(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function phaseOf(planning: PlanningSummary, today: Date): Phase {
  const [s, e] = rangeOf(planning)
  if (s > today) return 'upcoming'
  if (e >= today) return 'live'
  return 'done'
}

export function PlanningsPage() {
  const today = useMemo(() => startOfDay(new Date()), [])
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

  const groups = GROUPS.map((g) => ({
    ...g,
    items: plannings
      .filter((p) => phaseOf(p, today) === g.phase)
      // Finished ones from the most recent; the others from the soonest.
      .sort((a, b) => (g.phase === 'done' ? -1 : 1) * a.startsAt.localeCompare(b.startsAt)),
  })).filter((g) => g.items.length > 0)

  return (
    <div className="mp mp-main">
      <section className="mp-head">
        <div className="mp-head-text">
          <h1>Mes plannings</h1>
          <p>Chaque planning regroupe ses périodes de gardes successives, ses lignes et ses membres.</p>
        </div>
        {!showForm && (
          <button type="button" className="mp-btn" onClick={() => setShowForm(true)}>
            <Icon name="plus" size={18} strokeWidth={2.4} />
            Créer un planning
          </button>
        )}
      </section>

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

      {!loading && !error && plannings.length === 0 && (
        <section className="mp-empty">
          <span className="mp-empty-icon">
            <Icon name="layers" size={26} strokeWidth={1.9} />
          </span>
          <div className="mp-empty-title">Aucun planning</div>
          <div className="mp-empty-text">
            Créez un planning pour définir la période de garde, ajouter les lignes et inviter les membres.
          </div>
        </section>
      )}

      {!loading && !error && plannings.length > 0 && (
        <div className="mp-groups">
          {groups.map((g) => (
            <section key={g.phase} className="mp-group" aria-label={g.title}>
              {plannings.length > 1 && (
                <h2 className="mp-group-title">
                  {g.title}
                  <span className="mp-count">{g.items.length}</span>
                </h2>
              )}
              <ul className="mp-list">
                {g.items.map((p) => {
                  const card = toCardData(p)
                  return card ? (
                    <li key={p.stableId}>
                      <PlanningCard planning={card} today={p.today ?? localDate(today)} />
                    </li>
                  ) : null
                })}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
