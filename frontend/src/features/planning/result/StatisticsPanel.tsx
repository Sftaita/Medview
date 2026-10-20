import { useEffect, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { fetchPlanningStatistics } from './api'
import type { PlanningStatistics, StatisticsScope, Weekday } from './types'

type Props = {
  planningStableId: string
  /**
   * The selected period (docs/decisions.md D201): always sent, required by the
   * server on a planning with several periods. An answer for another period is
   * dropped.
   */
  periodStableId?: string
  /** Bump this to force a refetch (e.g. after a reassignment is saved). */
  refreshKey: number
}

type ScopeKey = 'currentPeriod' | 'cumulative' | 'provisional'

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

const PUBLICATION_LABEL: Record<string, string> = {
  PUBLISHED: 'publiée',
  UNPUBLISHED: 'non publiée',
  // No generation in the period: not generated yet, or nothing demanded (docs/decisions.md D194) — never "to do".
  NOT_GENERATED: 'non générée',
}

const DATE_FORMAT = new Intl.DateTimeFormat('fr-BE', { day: 'numeric', month: 'long', year: 'numeric' })

const LOAD_FORMAT = new Intl.NumberFormat('fr-BE', { maximumFractionDigits: 2 })

function formatLoad(load: number | undefined): string {
  return LOAD_FORMAT.format(load ?? 0)
}

function formatDate(date: string): string {
  return DATE_FORMAT.format(new Date(`${date}T00:00:00Z`))
}

/** The API's end dates are exclusive: the last day shown is the day before. */
function dayBefore(date: string): string {
  const day = new Date(`${date}T00:00:00Z`)
  day.setUTCDate(day.getUTCDate() - 1)
  return day.toISOString().slice(0, 10)
}

function periodList(numbers: number[]): string {
  if (numbers.length === 0) return 'aucune période'
  return numbers.length === 1 ? `période ${numbers[0]} incluse` : `périodes ${numbers.join(', ')} incluses`
}

/**
 * Three statistics perimeters, never confused (docs/decisions.md D132, D201):
 * "Période" — the selected period only, never a complete measure of fairness
 * by itself; "Cumul publié" — the published line periods of periods 1 to the
 * selected one; "En préparation" — the generated line periods not published
 * yet, never added to the cumulative, shown only to a manager when there are
 * some. All read the live current calendar, purely descriptive (§45: no
 * "balanced"/"unbalanced" verdict here). Defaults to the period (§36): right
 * after a generation, the manager wants to inspect what the engine produced.
 */
export function StatisticsPanel({ planningStableId, periodStableId, refreshKey }: Props) {
  const [loaded, setLoaded] = useState<{
    key: string
    statistics: PlanningStatistics | null
    error: string | null
  }>({ key: '', statistics: null, error: null })
  const [scopeKey, setScopeKey] = useState<ScopeKey>('currentPeriod')
  const key = `${planningStableId}|${periodStableId ?? ''}|${refreshKey}`

  useEffect(() => {
    let cancelled = false
    fetchPlanningStatistics(planningStableId, periodStableId)
      .then((data) => {
        // A late answer for a period no longer selected is never shown.
        if (cancelled || (periodStableId && data.period && data.period.stableId !== periodStableId)) return
        setLoaded({ key, statistics: data, error: null })
      })
      .catch(() => {
        if (!cancelled) setLoaded({ key, statistics: null, error: 'Impossible de charger les statistiques.' })
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId, periodStableId, key])

  const statistics = loaded.key === key ? loaded.statistics : null
  const error = loaded.key === key ? loaded.error : null

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

  // "En préparation": a manager only (null for a member), and only when something is provisional.
  const provisional =
    statistics.provisional && statistics.provisional.groups.length > 0 ? statistics.provisional : null
  const shownKey: ScopeKey = scopeKey === 'provisional' && !provisional ? 'currentPeriod' : scopeKey
  const scope =
    shownKey === 'provisional' && provisional
      ? provisional
      : statistics[shownKey === 'cumulative' ? 'cumulative' : 'currentPeriod']
  const number = statistics.period?.number
  const tabs: { key: ScopeKey; label: string }[] = [
    { key: 'currentPeriod', label: number ? `Période ${number}` : 'Cette période' },
    { key: 'cumulative', label: 'Cumul publié' },
    ...(provisional ? [{ key: 'provisional' as const, label: 'En préparation' }] : []),
  ]

  return (
    <section className="card stats-panel" aria-label="Statistiques de répartition">
      <div className="section-title">
        <h2>Statistiques</h2>
      </div>

      <div className="stats-panel__tabs" role="tablist" aria-label="Périmètre des statistiques">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            type="button"
            role="tab"
            aria-selected={shownKey === tab.key}
            className={`btn btn--sm ${shownKey === tab.key ? 'btn--primary' : 'btn--secondary'}`}
            onClick={() => setScopeKey(tab.key)}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <p className="muted stats-panel__bounds tnum">
        {formatDate(scope.startsAt)} → {formatDate(dayBefore(scope.endsAt))}
        {shownKey !== 'currentPeriod' && scope.periodNumbers && ` · ${periodList(scope.periodNumbers)}`} ·
        calculées sur les affectations actuelles, corrections comprises
      </p>

      {shownKey === 'cumulative' && (
        <p className="muted stats-panel__note">
          Le cumul publié additionne les affectations opérationnelles actuelles des lignes publiées,
          corrections faites après publication comprises : il peut différer des PDF diffusés.
        </p>
      )}
      {shownKey === 'provisional' && (
        <p className="muted stats-panel__note">
          Lignes générées mais pas encore publiées : chiffres provisoires, jamais ajoutés au cumul publié.
        </p>
      )}
      {shownKey === 'currentPeriod' && statistics.period && statistics.period.lines.length > 0 && (
        <ul className="stats-panel__lines" aria-label="Lignes de la période">
          {statistics.period.lines.map((line) => (
            <li key={line.lineStableId} data-publication={line.publication}>
              {line.lineName} : {PUBLICATION_LABEL[line.publication] ?? line.publication}
            </li>
          ))}
        </ul>
      )}

      {scope.groups.length === 0 && (
        <p className="muted">
          {shownKey === 'cumulative'
            ? 'Aucune garde publiée pour le moment.'
            : 'Aucune garde générée pour le moment.'}
        </p>
      )}

      {scope.groups.map((group) => (
        <StatisticsTable
          key={group.groupStableId}
          label={
            group.provisional && shownKey !== 'provisional'
              ? `${group.groupLabel} — provisoire (non publiée)`
              : group.groupLabel
          }
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
  // Duty types really held by someone in this group (docs/decisions.md D147) — a member without
  // that type reads 0, never a guessed list of types.
  const dutyTypeKeys = [
    ...new Set(group.members.flatMap((member) => Object.keys(member.countsByDutyType ?? {}))),
  ].sort()

  return (
    <div className="stats-panel__group">
      {(scope.groups.length > 1 || group.provisional) && (
        <h3 className="stats-panel__group-title">{label}</h3>
      )}
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
                {dutyTypeKeys.length > 1 &&
                  dutyTypeKeys.map((type) => (
                    <th key={`type-${type}`} className="pilot-table__num">
                      {type}
                    </th>
                  ))}
                <th className="pilot-table__num">Total</th>
                <th className="pilot-table__num">Charge pondérée</th>
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
                  {dutyTypeKeys.length > 1 &&
                    dutyTypeKeys.map((type) => (
                      <td key={`type-${type}`} className="pilot-table__num tnum" data-label={type}>
                        {member.countsByDutyType?.[type] ?? 0}
                      </td>
                    ))}
                  <td className="pilot-table__num tnum" data-label="Total">
                    <strong>{member.total}</strong>
                  </td>
                  <td className="pilot-table__num tnum" data-label="Charge pondérée">
                    {formatLoad(member.weightedLoad)}
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
