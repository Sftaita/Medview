import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '../components/Icon'
import { clearJoinedTeamsFlash, readJoinedTeamsFlash } from '../features/auth/joinedTeamsFlash'
import { useAuth } from '../features/auth/useAuth'
import { fetchMyCalendar } from '../features/availability/api'
import { dayIndexOfDate, formatDayLong, formatDayShort } from '../features/availability/calendarAxis'
import { periodsToRanges, upcomingRanges } from '../features/availability/periodMapping'
import type { DayRange } from '../features/availability/selection'
import { fetchPlannings } from '../features/planning/api'
import type { PlanningSummary } from '../features/planning/types'

const TODAY_FORMAT = new Intl.DateTimeFormat('fr-BE', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
})

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

function rangeLabel(range: DayRange): string {
  const days = range.end - range.start + 1
  return range.start === range.end
    ? formatDayLong(range.start)
    : `${formatDayShort(range.start)} → ${formatDayLong(range.end)} · ${days} jours`
}

export function DashboardPage() {
  const { user } = useAuth()
  const [joinedTeams] = useState(readJoinedTeamsFlash)
  const [plannings, setPlannings] = useState<PlanningSummary[] | null>(null)
  const [upcoming, setUpcoming] = useState<DayRange[] | null>(null)

  // Shown once: cleared as soon as it has been rendered.
  useEffect(() => clearJoinedTeamsFlash(), [])

  // Each block loads on its own and simply stays empty when its call fails: the dashboard is a summary, never a gate.
  useEffect(() => {
    let cancelled = false
    fetchPlannings()
      .then((result) => {
        if (!cancelled) setPlannings(result)
      })
      .catch(() => {
        if (!cancelled) setPlannings([])
      })
    fetchMyCalendar()
      .then((periods) => {
        if (!cancelled) setUpcoming(upcomingRanges(periodsToRanges(periods), dayIndexOfDate(new Date())))
      })
      .catch(() => {
        if (!cancelled) setUpcoming([])
      })
    return () => {
      cancelled = true
    }
  }, [])

  return (
    <section className="page">
      <header>
        <h1>Bonjour, {user?.firstName ?? ''}&nbsp;!</h1>
        <p className="page__lead">{capitalize(TODAY_FORMAT.format(new Date()))}</p>
      </header>

      {joinedTeams.length > 0 && (
        <div role="status" className="alert alert--success">
          <Icon name="check" size={22} strokeWidth={2} />
          <div className="alert__body">
            <p>
              <strong>Votre compte a bien été créé.</strong> Vous avez été ajouté automatiquement à{' '}
              {joinedTeams.length > 1 ? 'ces équipes' : 'cette équipe'} :
            </p>
            <ul>
              {joinedTeams.map((team) => (
                <li key={team.teamStableId}>
                  <Link to={`/plannings/${team.planningStableId}`}>{team.teamName}</Link> ({team.planningName}
                  )
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}

      <div className="dashboard">
        <div className="card">
          <div className="dashboard__title">
            <h2>Mes indisponibilités</h2>
            <span className="muted">
              <Icon name="calendarX" size={22} />
            </span>
          </div>
          {upcoming !== null && upcoming.length === 0 && (
            <p className="muted dashboard__empty">Aucune indisponibilité ou préférence à venir.</p>
          )}
          {upcoming !== null && upcoming.length > 0 && (
            <ul className="list dashboard__ranges">
              {upcoming.slice(0, 4).map((range) => (
                <li
                  key={`${range.type}-${range.start}`}
                  className={`dashboard__range dashboard__range--${range.type === 'UNAVAILABLE' ? 'unavailable' : 'prefer'}`}
                >
                  <span className="dashboard__range-dot" />
                  <span className="tnum">{rangeLabel(range)}</span>
                  <span className="dashboard__range-type">
                    {range.type === 'UNAVAILABLE' ? 'Indisponible' : 'Préférence de garde'}
                  </span>
                </li>
              ))}
            </ul>
          )}
          <p className="muted dashboard__note">
            Partagées par toutes vos équipes : vous les déclarez une seule fois.
          </p>
          <Link to="/my-availability" className="btn btn--secondary btn--full">
            Ouvrir le calendrier
          </Link>
        </div>

        <div className="card card--flush">
          <div className="card__header">
            <h2>Mes plannings</h2>
            <Link to="/plannings" className="btn btn--ghost btn--sm">
              Tous les plannings
            </Link>
          </div>
          {plannings !== null && plannings.length === 0 && (
            <p className="muted dashboard__empty dashboard__empty--padded">Aucun planning pour le moment.</p>
          )}
          {plannings !== null && plannings.length > 0 && (
            <ul className="list">
              {plannings.slice(0, 5).map((planning) => (
                <li key={planning.stableId} className="list-row">
                  <Link to={`/plannings/${planning.stableId}`} className="list-row__main dashboard__link">
                    <span className="list-row__title">{planning.name}</span>
                    <span className="list-row__meta tnum">
                      {planning.startsAt} → {planning.endsAt}
                    </span>
                  </Link>
                  <Icon name="right" size={20} />
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </section>
  )
}
