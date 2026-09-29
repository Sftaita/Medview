import { useEffect, useMemo, useState, type CSSProperties } from 'react'
import { Link } from 'react-router-dom'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import {
  addDays,
  cap,
  daysBetween,
  formatDay,
  inclusiveDays,
  monthSegments,
  parseISO,
  startOfDay,
} from '../features/dashboard/dates'
import { createPlanning, fetchPlannings } from '../features/planning/api'
import '../features/planning/plannings.css'
import type { PlanningSummary } from '../features/planning/types'
import { ApiError } from '../lib/apiClient'

/** Maquette docs/Design/react_mes_plannings (docs/decisions.md D169). */

type Phase = 'live' | 'upcoming' | 'done'
type Step = 'draft' | 'collect' | 'published'

const GROUPS: { phase: Phase; title: string }[] = [
  { phase: 'live', title: 'En cours' },
  { phase: 'upcoming', title: 'À venir' },
  { phase: 'done', title: 'Terminés' },
]

const STEP_LABEL: Record<Step, string> = {
  draft: 'À générer',
  collect: 'Collecte des indispos ouverte',
  published: 'Publié',
}

const plural = (n: number, s: string, p = s + 's') => `${n} ${n > 1 ? p : s}`

/** First and last day of the planning, both included — the API's end date is exclusive. */
function rangeOf(planning: PlanningSummary): [Date, Date] {
  return [parseISO(planning.startsAt), addDays(parseISO(planning.endsAt), -1)]
}

function phaseOf(planning: PlanningSummary, today: Date): Phase {
  const [s, e] = rangeOf(planning)
  if (s > today) return 'upcoming'
  if (e >= today) return 'live'
  return 'done'
}

function stepOf(planning: PlanningSummary): Step {
  if (planning.published) return 'published'
  return planning.collecting ? 'collect' : 'draft'
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
          <p>Une période de garde et ses lignes. Chaque ligne appartient à une équipe.</p>
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
                {g.items.map((p) => (
                  <li key={p.stableId}>
                    <PlanningCard planning={p} today={today} />
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}

function PlanningCard({ planning, today }: { planning: PlanningSummary; today: Date }) {
  const [s, e] = rangeOf(planning)
  const len = inclusiveDays(s, e)
  const until = daysBetween(today, s)
  const left = daysBetween(today, e)
  const phase = phaseOf(planning, today)
  const step = stepOf(planning)
  const elapsed = phase === 'done' ? len : phase === 'live' ? daysBetween(s, today) + 1 : 0

  const status =
    phase === 'upcoming'
      ? until === 1
        ? 'Commence demain'
        : `Commence dans ${until} jours`
      : phase === 'live'
        ? `En cours · jour ${elapsed} sur ${len}`
        : 'Terminé'
  const [bigNum, bigLabel] =
    phase === 'upcoming'
      ? [`J-${until}`, 'avant le début']
      : phase === 'live'
        ? [String(left + 1), left === 0 ? 'dernier jour' : 'jours restants']
        : [String(len), 'jours']

  const months = monthSegments(s, e)
  // Day offset of each month from the start of the planning.
  const offsets = months.map((_, i) => months.slice(0, i).reduce((n, m) => n + m.days, 0))

  return (
    <Link to={`/plannings/${planning.stableId}`} className={`mp-card mp-card-${phase}`}>
      <div className="mp-card-top">
        <div className="mp-card-info">
          <div className="mp-card-title">
            <span className="mp-card-name">{planning.name}</span>
            <span className={`mp-pill mp-pill-${phase}`}>
              <span className="mp-dot" />
              {status}
            </span>
          </div>
          <div className="mp-card-range">
            <Icon name="calendar" size={17} className="mp-muted-icon" />
            <span className="mp-long">
              {cap(formatDay(s, { year: true }))} → {formatDay(e, { year: true })}
            </span>
            <span className="mp-short">
              {formatDay(s, { year: true, weekday: false })} → {formatDay(e, { year: true, weekday: false })}
            </span>
          </div>
        </div>
        <div className="mp-card-aside">
          <div className="mp-stat">
            <span className="mp-stat-num">{bigNum}</span>
            <span className="mp-stat-label">{bigLabel}</span>
          </div>
          <span className="mp-chevron">
            <Icon name="right" size={20} strokeWidth={2.2} />
          </span>
        </div>
      </div>

      <div className="mp-timeline" aria-hidden="true">
        <div className="mp-timeline-bar">
          {months.map((m, i) => {
            const pct = Math.max(0, Math.min(1, (elapsed - offsets[i]) / m.days)) * 100
            const first = i === 0
            const last = i === months.length - 1
            const style = {
              flex: m.days,
              '--fill': `${pct}%`,
              borderRadius: `${first ? 999 : 2}px ${last ? 999 : 2}px ${last ? 999 : 2}px ${first ? 999 : 2}px`,
            } as CSSProperties
            return <span key={m.key} className="mp-seg" style={style} />
          })}
          {phase === 'live' && (
            <span
              className="mp-today"
              title="Aujourd'hui"
              style={{ left: `calc(${(elapsed / len) * 100}% - 1.5px)` }}
            />
          )}
        </div>
        <div className="mp-timeline-labels">
          {months.map((m) => (
            <span key={m.key} style={{ flex: m.days }}>
              {m.label}
            </span>
          ))}
        </div>
      </div>

      <div className="mp-card-foot">
        <span className="mp-meta">
          <Icon name="clock" size={16} />
          {len} jours
        </span>
        {planning.lineCount !== undefined && (
          <span className="mp-meta">
            <Icon name="rows" size={16} />
            {plural(planning.lineCount, 'ligne')}
          </span>
        )}
        {planning.memberCount !== undefined && (
          <span className="mp-meta">
            <Icon name="users" size={16} />
            {plural(planning.memberCount, 'membre')}
          </span>
        )}
        <span className={`mp-step mp-step-${step}`}>
          <span className="mp-step-dot" />
          {STEP_LABEL[step]}
        </span>
      </div>
    </Link>
  )
}
