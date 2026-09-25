import { useEffect, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { fetchPlanningStatistics } from './api'
import type { PlanningStatistics, StatisticsScope, Weekday } from './types'

type Props = {
  planningStableId: string
  /** Bump this to force a refetch (e.g. after a reassignment is saved). */
  refreshKey: number
}

type ScopeKey = 'currentPeriod' | 'cumulative'

const WEEKDAY_ORDER: Weekday[] = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']
const WEEKDAY_LABEL: Record<Weekday, string> = {
  MON: 'L',
  TUE: 'Ma',
  WED: 'Me',
  THU: 'J',
  FRI: 'V',
  SAT: 'S',
  SUN: 'D',
}

const DATE_FORMAT = new Intl.DateTimeFormat('fr-BE', { day: 'numeric', month: 'long', year: 'numeric' })

function formatDate(date: string): string {
  return DATE_FORMAT.format(new Date(`${date}T00:00:00Z`))
}

/**
 * Two statistics perimeters, never confused (docs/decisions.md D132): "this
 * period" is never presented as a complete measure of fairness by itself —
 * "cumulative" is the whole Planning's real current state. Both read the
 * live current calendar, purely descriptive (§45: no "balanced"/
 * "unbalanced" verdict here). Defaults to "this period" (§36): right after
 * a generation, the manager wants to inspect what the engine just produced.
 */
export function StatisticsPanel({ planningStableId, refreshKey }: Props) {
  const [statistics, setStatistics] = useState<PlanningStatistics | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [scopeKey, setScopeKey] = useState<ScopeKey>('currentPeriod')

  useEffect(() => {
    let cancelled = false
    fetchPlanningStatistics(planningStableId)
      .then((data) => {
        if (!cancelled) {
          setStatistics(data)
          setError(null)
        }
      })
      .catch(() => {
        if (!cancelled) setError('Impossible de charger les statistiques.')
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId, refreshKey])

  if (error) {
    return (
      <p role="alert" className="alert alert--error">
        <Icon name="alert" size={18} strokeWidth={2} />
        <span>{error}</span>
      </p>
    )
  }

  if (!statistics) {
    return (
      <p role="status" className="muted">
        Chargement des statistiques…
      </p>
    )
  }

  const scope = statistics[scopeKey]

  return (
    <section className="card stats-panel" aria-label="Statistiques de répartition">
      <div className="section-title">
        <h2>Statistiques</h2>
      </div>

      <div className="stats-panel__tabs" role="tablist" aria-label="Périmètre des statistiques">
        <button
          type="button"
          role="tab"
          aria-selected={scopeKey === 'currentPeriod'}
          className={`btn btn--sm ${scopeKey === 'currentPeriod' ? 'btn--primary' : 'btn--secondary'}`}
          onClick={() => setScopeKey('currentPeriod')}
        >
          Cette période
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={scopeKey === 'cumulative'}
          className={`btn btn--sm ${scopeKey === 'cumulative' ? 'btn--primary' : 'btn--secondary'}`}
          onClick={() => setScopeKey('cumulative')}
        >
          Cumul du planning
        </button>
      </div>

      <p className="muted stats-panel__bounds tnum">
        {formatDate(scope.startsAt)} → {formatDate(scope.endsAt)}
      </p>

      {scope.groups.length === 0 && <p className="muted">Aucune garde générée pour le moment.</p>}

      {scope.groups.map((group) => (
        <StatisticsTable
          key={group.groupStableId}
          label={group.groupLabel}
          scope={scope}
          groupStableId={group.groupStableId}
        />
      ))}
    </section>
  )
}

function StatisticsTable({
  label,
  scope,
  groupStableId,
}: {
  label: string
  scope: StatisticsScope
  groupStableId: string
}) {
  const group = scope.groups.find((g) => g.groupStableId === groupStableId)
  if (!group) return null

  // Family columns are whatever names this group's own generation actually
  // used (docs/decisions.md D137) — every member row shares the same keys,
  // never a hardcoded "Week-end"/"Semaine".
  const familyKeys = Object.keys(group.members[0]?.countsByFamily ?? {})

  return (
    <div className="stats-panel__group">
      {scope.groups.length > 1 && <h3 className="stats-panel__group-title">{label}</h3>}
      {group.members.length === 0 ? (
        <p className="muted">Aucune garde attribuée pour cette ligne.</p>
      ) : (
        <div className="pilot-table__wrap">
          <table className="pilot-table">
            <thead>
              <tr>
                <th>Nom</th>
                {WEEKDAY_ORDER.map((day) => (
                  <th key={day} className="pilot-table__num">
                    {WEEKDAY_LABEL[day]}
                  </th>
                ))}
                {familyKeys.map((family) => (
                  <th key={family || '__no_family'} className="pilot-table__num">
                    {family || 'Sans famille'}
                  </th>
                ))}
                <th className="pilot-table__num">Total</th>
              </tr>
            </thead>
            <tbody>
              {group.members.map((member) => (
                <tr key={member.teamMemberStableId}>
                  <td data-label="Nom">
                    {member.firstName} {member.lastName}
                  </td>
                  {WEEKDAY_ORDER.map((day) => (
                    <td key={day} className="pilot-table__num tnum" data-label={WEEKDAY_LABEL[day]}>
                      {member.countsByWeekday[day]}
                    </td>
                  ))}
                  {familyKeys.map((family) => (
                    <td
                      key={family || '__no_family'}
                      className="pilot-table__num tnum"
                      data-label={family || 'Sans famille'}
                    >
                      {member.countsByFamily[family]}
                    </td>
                  ))}
                  <td className="pilot-table__num tnum" data-label="Total">
                    <strong>{member.total}</strong>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
