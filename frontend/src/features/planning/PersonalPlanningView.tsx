import { useEffect, useMemo, useState } from 'react'
import { Icon } from '../../components/Icon'
import { fetchAssignments, fetchTeamMembers } from './api'
import type { PlanningAssignment, PlanningAssignments, PlanningDetail, PlanningTeamMember } from './types'

type Props = {
  planning: PlanningDetail
}

const WHOLE_TEAM = ''

const MONTH_LABEL = new Intl.DateTimeFormat('fr-BE', { month: 'long', year: 'numeric', timeZone: 'UTC' })
const DAY_LABEL = new Intl.DateTimeFormat('fr-BE', {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  timeZone: 'UTC',
})

/** The first day of a month as "YYYY-MM-DD"; `offset` months away. Pure calendar arithmetic, no timezone. */
function monthStart(reference: string, offset: number): string {
  const [year, month] = reference.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1 + offset, 1)).toISOString().slice(0, 10)
}

function monthLabel(firstDay: string): string {
  return MONTH_LABEL.format(new Date(`${firstDay}T00:00:00Z`))
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

function fullName(person: { firstName: string; lastName: string }): string {
  return `${person.firstName} ${person.lastName}`
}

/** "YYYY-MM-DD" one day earlier — the planning's `endsAt` is exclusive, so this is its last day. */
function dayBefore(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day - 1)).toISOString().slice(0, 10)
}

const firstOfMonth = (date: string) => `${date.slice(0, 7)}-01`

/** The month to open on: the current one, kept inside the months the planning covers. */
function initialMonth(planning: PlanningDetail): string {
  const first = firstOfMonth(planning.startsAt)
  const last = firstOfMonth(dayBefore(planning.endsAt))
  const current = firstOfMonth(new Date().toISOString())
  return current < first ? first : current > last ? last : current
}

/** Wall-clock time of a duty, in the duty's own timezone (never the browser's). */
function timeOf(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('fr-BE', { hour: '2-digit', minute: '2-digit', timeZone }).format(
    new Date(iso),
  )
}

/**
 * "Planning par personne": pick one member and see only their duties, month
 * by month, with a summary of what they have been given — or go back to the
 * whole team (docs/planning.md §14). Reads the assignments the engine
 * produced; nothing is computed or estimated here.
 */
export function PersonalPlanningView({ planning }: Props) {
  const [people, setPeople] = useState<PlanningTeamMember[]>([])
  const [selected, setSelected] = useState(WHOLE_TEAM)
  const [month, setMonth] = useState(() => initialMonth(planning))
  const [result, setResult] = useState<PlanningAssignments | null>(null)
  const [error, setError] = useState<string | null>(null)

  // Everyone who has been in one of the planning's teams, once each (the same person may have left and rejoined).
  useEffect(() => {
    let cancelled = false
    Promise.all(planning.lines.map((line) => fetchTeamMembers(planning.stableId, line.team.stableId)))
      .then((lists) => {
        if (cancelled) return
        const byUser = new Map<string, PlanningTeamMember>()
        for (const member of lists.flat()) {
          if (!byUser.has(member.userStableId)) byUser.set(member.userStableId, member)
        }
        setPeople([...byUser.values()].sort((a, b) => fullName(a).localeCompare(fullName(b))))
      })
      .catch(() => {
        if (!cancelled) setError('Impossible de charger les membres.')
      })
    return () => {
      cancelled = true
    }
  }, [planning.stableId, planning.lines])

  useEffect(() => {
    let cancelled = false
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setResult(null)
    fetchAssignments(planning.stableId, {
      userStableId: selected || undefined,
      from: month,
      to: monthStart(month, 1),
    })
      .then((data) => {
        if (!cancelled) {
          setResult(data)
          setError(null)
        }
      })
      .catch(() => {
        if (!cancelled) setError('Impossible de charger le planning.')
      })
    return () => {
      cancelled = true
    }
  }, [planning.stableId, selected, month])

  const firstMonth = firstOfMonth(planning.startsAt)
  const lastMonth = firstOfMonth(dayBefore(planning.endsAt))
  const selectedPerson = people.find((person) => person.userStableId === selected) ?? null

  const byDay = useMemo(() => {
    const groups = new Map<string, PlanningAssignment[]>()
    for (const assignment of result?.assignments ?? []) {
      groups.set(assignment.date, [...(groups.get(assignment.date) ?? []), assignment])
    }
    return [...groups.entries()]
  }, [result])

  return (
    <section className="card person-view" aria-label="Planning par personne">
      <div className="section-title">
        <h2>Planning par personne</h2>
      </div>

      <div className="person-view__controls">
        <div className="field">
          <label htmlFor="person-select" className="field__label">
            Afficher
          </label>
          <select
            id="person-select"
            className="field__input"
            value={selected}
            onChange={(event) => setSelected(event.target.value)}
          >
            <option value={WHOLE_TEAM}>Toute l&apos;équipe</option>
            {people.map((person) => (
              <option key={person.userStableId} value={person.userStableId}>
                {fullName(person)}
              </option>
            ))}
          </select>
        </div>
        {selected !== WHOLE_TEAM && (
          <button type="button" className="btn btn--ghost btn--sm" onClick={() => setSelected(WHOLE_TEAM)}>
            Voir toute l&apos;équipe
          </button>
        )}
      </div>

      <div className="person-view__nav">
        <button
          type="button"
          className="icon-btn icon-btn--bordered"
          aria-label="Mois précédent"
          disabled={month <= firstMonth}
          onClick={() => setMonth((current) => monthStart(current, -1))}
        >
          <Icon name="left" size={20} strokeWidth={2} />
        </button>
        <span className="person-view__month" aria-live="polite">
          {capitalize(monthLabel(month))}
        </span>
        <button
          type="button"
          className="icon-btn icon-btn--bordered"
          aria-label="Mois suivant"
          disabled={month >= lastMonth}
          onClick={() => setMonth((current) => monthStart(current, 1))}
        >
          <Icon name="right" size={20} strokeWidth={2} />
        </button>
      </div>

      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
      {result === null && !error && (
        <p role="status" className="muted">
          Chargement…
        </p>
      )}

      {result !== null && result.summary && (
        <dl
          className="person-summary"
          aria-label={`Résumé de ${selectedPerson ? fullName(selectedPerson) : 'la personne'}`}
        >
          <div>
            <dt>Gardes</dt>
            <dd className="tnum">{result.summary.totalDuties}</dd>
          </div>
          <div>
            <dt>Charge pondérée</dt>
            <dd className="tnum">{result.summary.weightedWorkload}</dd>
          </div>
          <div>
            <dt>Week-ends (sam. + dim.)</dt>
            <dd className="tnum">{result.summary.weekendDays}</dd>
          </div>
          <div>
            <dt>Vendredis</dt>
            <dd className="tnum">{result.summary.fridays}</dd>
          </div>
          <div>
            <dt>Samedis</dt>
            <dd className="tnum">{result.summary.saturdays}</dd>
          </div>
          <div>
            <dt>Dimanches</dt>
            <dd className="tnum">{result.summary.sundays}</dd>
          </div>
          {result.summary.byDutyType.map((row) => (
            <div key={row.dutyTypeStableId}>
              <dt>{row.name}</dt>
              <dd className="tnum">{row.count}</dd>
            </div>
          ))}
        </dl>
      )}
      {result !== null && result.summary && (
        <p className="muted person-view__note">Sur l&apos;ensemble du planning, tous mois confondus.</p>
      )}

      {result !== null && result.generations.length === 0 && (
        <p className="muted">
          Aucune génération terminée pour ce planning : il n&apos;y a pas encore de garde à afficher.
        </p>
      )}
      {result !== null && result.generations.length > 0 && byDay.length === 0 && (
        <p className="muted">
          {selectedPerson ? `${fullName(selectedPerson)} n'a aucune garde` : 'Aucune garde'} en{' '}
          {monthLabel(month)}.
        </p>
      )}
      {byDay.length > 0 && (
        <ul className="list duties" aria-label="Gardes du mois">
          {byDay.map(([date, assignments]) => (
            <li key={date} className="duty-day">
              <div className="duty-day__date tnum">
                {capitalize(DAY_LABEL.format(new Date(`${date}T00:00:00Z`)))}
              </div>
              <ul className="list duty-day__items">
                {assignments.map((assignment) => (
                  <li key={assignment.stableId} className="duty">
                    <span className="duty__type tag">{assignment.dutyType.name}</span>
                    <span className="duty__time tnum">
                      {timeOf(assignment.startsAt, assignment.timezone)}–
                      {timeOf(assignment.endsAt, assignment.timezone)}
                    </span>
                    {selected === WHOLE_TEAM && (
                      <span className="duty__who">{fullName(assignment.user)}</span>
                    )}
                    {assignment.lineName && planning.lines.length > 1 && (
                      <span className="muted duty__line">{assignment.lineName}</span>
                    )}
                  </li>
                ))}
              </ul>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
