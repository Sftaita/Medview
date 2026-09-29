import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Icon } from '../components/Icon'
import { clearJoinedTeamsFlash, readJoinedTeamsFlash } from '../features/auth/joinedTeamsFlash'
import { useAuth } from '../features/auth/useAuth'
import { dateOfDayIndex, dayIndexOfDate } from '../features/availability/calendarAxis'
import { CollectionCallout } from '../features/availability/CollectionCallout'
import { upcomingRanges } from '../features/availability/periodMapping'
import { useMyAvailability } from '../features/availability/useMyAvailability'
import '../features/dashboard/dashboard.css'
import {
  addDays,
  cap,
  daysBetween,
  formatDay,
  formatLongDate,
  formatUnavailability,
  inclusiveDays,
  monthSegments,
  monthShort,
  parseISO,
  relativeDays,
  startOfDay,
} from '../features/dashboard/dates'
import { fetchMyDuties } from '../features/duties/api'
import { DutyRow } from '../features/duties/DutyRow'
import { splitDuties } from '../features/duties/dutyDates'
import type { MyDuty } from '../features/duties/types'
import { fetchPlannings } from '../features/planning/api'
import type { PlanningSummary } from '../features/planning/types'

/** An upcoming unavailability, as local dates (both ends included). */
type Upcoming = { id: string; a: Date; b: Date }

const plural = (n: number, s: string, p = s + 's') => `${n} ${n > 1 ? p : s}`

/** Home page of a signed-in user (docs/Design/react_dashboard): their plannings and their upcoming unavailabilities. */
export function DashboardPage() {
  const { user } = useAuth()
  const { ranges, loadError, collections } = useMyAvailability()
  const [joinedTeams] = useState(readJoinedTeamsFlash)
  const [plannings, setPlannings] = useState<PlanningSummary[] | null>(null)
  const [duties, setDuties] = useState<MyDuty[] | null>(null)
  const today = useMemo(() => startOfDay(new Date()), [])

  // Read from the shared store, never fetched here: whatever was just changed in the calendar is already in it.
  // A failed load simply leaves the list empty: the dashboard is a summary, never a gate.
  const upcoming = useMemo<Upcoming[]>(
    () =>
      upcomingRanges(ranges ?? [], dayIndexOfDate(today))
        .filter((range) => range.type === 'UNAVAILABLE')
        .map((range) => ({
          id: String(range.start),
          a: dateOfDayIndex(range.start),
          b: dateOfDayIndex(range.end),
        }))
        .sort((x, y) => x.a.getTime() - y.a.getTime()),
    [ranges, today],
  )
  // What is still to do comes first, then what has been confirmed.
  const openCollections = useMemo(
    () =>
      [...(collections ?? [])].sort(
        (a, b) =>
          Number(a.myResponse?.status === 'ACKNOWLEDGED') - Number(b.myResponse?.status === 'ACKNOWLEDGED') ||
          a.startsAt.localeCompare(b.startsAt),
      ),
    [collections],
  )

  // Shown once: cleared as soon as it has been rendered.
  useEffect(() => clearJoinedTeamsFlash(), [])

  useEffect(() => {
    let cancelled = false
    // Same rule as the plannings: a failed load shows nothing rather than an error.
    fetchMyDuties()
      .then((result) => {
        if (!cancelled) setDuties(result)
      })
      .catch(() => {
        if (!cancelled) setDuties([])
      })
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    let cancelled = false
    fetchPlannings()
      .then((result) => {
        if (!cancelled) setPlannings(result)
      })
      .catch(() => {
        if (!cancelled) setPlannings([])
      })
    return () => {
      cancelled = true
    }
  }, [])

  return (
    <div className="db db-main">
      <section className="db-hello">
        <h1>Bonjour, {user?.firstName ?? ''}&nbsp;!</h1>
        <p>{formatLongDate(today)}</p>
      </section>

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

      {openCollections.length > 0 && (
        <div className="callouts" aria-label="Disponibilités attendues">
          {openCollections.map((collection) => (
            <CollectionCallout key={collection.stableId} collection={collection} mode="dashboard" />
          ))}
        </div>
      )}

      {duties !== null && <NextDutyCard duties={duties} today={today} />}

      <div className="db-grid">
        <PlanningsCard plannings={plannings} upcoming={upcoming} today={today} />
        <UnavailabilityCard upcoming={ranges === null && !loadError ? null : upcoming} today={today} />
      </div>
    </div>
  )
}

function CardHeader({
  title,
  sub,
  link,
}: {
  title: string
  sub?: string
  link?: { to: string; long: string; short?: string }
}) {
  return (
    <div className="db-card-head">
      <div className="db-card-head-text">
        <h2>{title}</h2>
        {sub && <span className="db-muted db-num">{sub}</span>}
      </div>
      {link && (
        <Link to={link.to} className="db-head-link">
          <span className={link.short ? 'db-long' : undefined}>{link.long}</span>
          {link.short && <span className="db-short">{link.short}</span>}
          <Icon name="right" size={16} strokeWidth={2.2} />
        </Link>
      )}
    </div>
  )
}

function PlanningsCard({
  plannings,
  upcoming,
  today,
}: {
  plannings: PlanningSummary[] | null
  upcoming: Upcoming[]
  today: Date
}) {
  return (
    <section className="db-card" aria-label="Mes plannings">
      <CardHeader
        title="Mes plannings"
        link={{ to: '/plannings', long: 'Tous les plannings', short: 'Tout voir' }}
      />
      {plannings !== null && plannings.length === 0 && (
        <div className="db-empty">
          <div className="db-empty-title">Aucun planning pour l'instant</div>
          <div className="db-muted">Vous serez notifié dès qu'une équipe vous ajoute à un planning.</div>
        </div>
      )}
      {plannings?.map((planning) => (
        <PlanningRow key={planning.stableId} planning={planning} upcoming={upcoming} today={today} />
      ))}
    </section>
  )
}

function PlanningRow({
  planning,
  upcoming,
  today,
}: {
  planning: PlanningSummary
  upcoming: Upcoming[]
  today: Date
}) {
  const navigate = useNavigate()
  const s = parseISO(planning.startsAt)
  // The API's end date is exclusive.
  const e = addDays(parseISO(planning.endsAt), -1)
  const len = inclusiveDays(s, e)
  const until = daysBetween(today, s)
  const status =
    until > 0
      ? { label: `Commence dans ${plural(until, 'jour')}`, tone: 'info' }
      : today <= e
        ? { label: `En cours · jour ${daysBetween(s, today) + 1} sur ${len}`, tone: 'live' }
        : { label: 'Terminé', tone: 'done' }
  const months = monthSegments(s, e)
  const inside = upcoming.filter((u) => u.b >= s && u.a <= e)
  const role = planning.myLineName ? ` · ${planning.myLineName}` : ''

  return (
    <Link to={`/plannings/${planning.stableId}`} className="db-planning">
      <div className="db-planning-top">
        <div className="db-planning-info">
          <div className="db-planning-title">
            <span className="db-planning-name">{planning.name}</span>
            <span className={`db-pill db-pill-${status.tone}`}>
              <span className="db-dot" />
              {status.label}
            </span>
          </div>
          <span className="db-planning-range db-num">
            <span className="db-long">
              {cap(formatDay(s, { year: true }))} → {formatDay(e, { year: true })}
            </span>
            <span className="db-short">
              {formatDay(s, { year: true, weekday: false })} → {formatDay(e, { year: true, weekday: false })}
            </span>
          </span>
          <span className="db-muted db-num">
            {len} jours{role}
            {planning.memberCount !== undefined && (
              <span className="db-long"> · {plural(planning.memberCount, 'membre')}</span>
            )}
          </span>
        </div>
        <span className="db-chevron">
          <Icon name="right" size={20} strokeWidth={2.2} />
        </span>
      </div>

      <div className="db-timeline" aria-hidden="true">
        <div className="db-timeline-bar">
          {months.map((m, i) => (
            <span
              key={m.key}
              className="db-timeline-seg"
              style={{
                flex: m.days,
                borderTopLeftRadius: i === 0 ? 999 : 0,
                borderBottomLeftRadius: i === 0 ? 999 : 0,
                borderTopRightRadius: i === months.length - 1 ? 999 : 0,
                borderBottomRightRadius: i === months.length - 1 ? 999 : 0,
              }}
            />
          ))}
          {inside.map((u) => {
            const a = Math.max(0, daysBetween(s, u.a))
            const b = Math.min(len - 1, daysBetween(s, u.b))
            return (
              <span
                key={u.id}
                className="db-timeline-mark"
                title={`Indisponible : ${formatUnavailability(u.a, u.b)}`}
                style={{ left: `${(a / len) * 100}%`, width: `max(4px, ${((b - a + 1) / len) * 100}%)` }}
              />
            )
          })}
        </div>
        <div className="db-timeline-labels">
          {months.map((m) => (
            <span key={m.key} style={{ flex: m.days }}>
              {m.label}
            </span>
          ))}
        </div>
      </div>

      {inside.length > 0 && (
        <div className="db-legend">
          <span className="db-legend-swatch" />
          {inside.length === 1
            ? '1 de vos indisponibilités tombe'
            : `${inside.length} de vos indisponibilités tombent`}{' '}
          dans cette période
        </div>
      )}

      <div className="db-note">
        <Icon name="moon" size={18} strokeWidth={2} className="db-note-icon" />
        {planning.published ? (
          <span>
            Planning publié. {/* Not a nested <a>: the whole row already is one. */}
            <span
              className="db-note-link"
              onClick={(event) => {
                event.preventDefault()
                event.stopPropagation()
                navigate('/my-duties')
              }}
            >
              Voir mes gardes
            </span>
          </span>
        ) : (
          <span>Planning en préparation. Vos gardes apparaîtront ici dès sa publication.</span>
        )}
      </div>
    </Link>
  )
}

/** The next duty of the signed-in user, when they have one (docs/decisions.md D168) — nothing otherwise. */
function NextDutyCard({ duties, today }: { duties: MyDuty[]; today: Date }) {
  const { upcoming } = splitDuties(duties, today)
  if (upcoming.length === 0) return null
  const others = upcoming.length - 1
  return (
    <section className="db-card" aria-label="Prochaine garde">
      <CardHeader
        title="Prochaine garde"
        sub={others > 0 ? `Puis ${plural(others, 'autre garde', 'autres gardes')} à venir` : undefined}
        link={{ to: '/my-duties', long: 'Mes gardes', short: 'Tout voir' }}
      />
      <ul className="db-list">
        <li>
          <DutyRow duty={upcoming[0]} today={today} />
        </li>
      </ul>
    </section>
  )
}

function UnavailabilityCard({ upcoming, today }: { upcoming: Upcoming[] | null; today: Date }) {
  const list = upcoming ?? []
  const total = list.reduce((n, u) => n + inclusiveDays(u.a, u.b), 0)
  const has = list.length > 0
  return (
    <section className="db-card" aria-label="Mes indisponibilités">
      <CardHeader
        title="Mes indisponibilités"
        sub={has ? `${plural(list.length, 'période')} · ${total} jours au total` : undefined}
        link={has ? { to: '/my-availability', long: 'Calendrier' } : undefined}
      />

      {has && (
        <ul className="db-list">
          {list.map((u, i) => {
            const n = inclusiveDays(u.a, u.b)
            return (
              <li key={u.id}>
                <Link to="/my-availability" className="db-row">
                  <span className="db-date-tile" aria-hidden="true">
                    <span className="db-date-tile-day">{u.a.getDate()}</span>
                    <span className="db-date-tile-mon">{monthShort(u.a)}</span>
                  </span>
                  <span className="db-row-text">
                    <span className="db-row-title db-num">{formatUnavailability(u.a, u.b)}</span>
                    <span className="db-muted db-num">{n === 1 ? 'Journée entière' : `${n} jours`}</span>
                  </span>
                  {i === 0 && (
                    <span className="db-pill db-pill-warn">{relativeDays(daysBetween(today, u.a))}</span>
                  )}
                  <span className="db-chevron">
                    <Icon name="right" size={18} strokeWidth={2.2} />
                  </span>
                </Link>
              </li>
            )
          })}
        </ul>
      )}
      {upcoming !== null && !has && (
        <div className="db-empty db-empty-center">
          <span className="db-empty-icon">
            <Icon name="calendarCheck" size={24} strokeWidth={1.9} />
          </span>
          <div className="db-empty-title">Aucune indisponibilité à venir</div>
          <div className="db-muted">
            Vous êtes considéré disponible tous les jours. Déclarez vos absences avant la génération des
            plannings.
          </div>
        </div>
      )}

      <div className="db-card-foot">
        <Link to="/my-availability" className="db-btn">
          <Icon name="plus" size={18} strokeWidth={2.2} />
          Déclarer une indisponibilité
        </Link>
        <div className="db-hint">
          <Icon name="users" size={16} strokeWidth={2} />
          <span>Partagées avec toutes vos équipes : une seule déclaration suffit.</span>
        </div>
      </div>
    </section>
  )
}
