import { useState } from 'react'
import { fetchAdoption, fetchTimeseries, type AdoptionRange } from '../../features/admin/api'
import { BarChart, LineChart } from '../../features/admin/charts'
import { AdminPageHeader, Kpi, LoadState, SegmentedChoice } from '../../features/admin/components'
import { SENIORITY_LABELS, formatDay, formatNumber, formatPercent } from '../../features/admin/format'
import type { Granularity, Range, Retention } from '../../features/admin/types'
import { useAdminData } from '../../features/admin/useAdminData'
import { RANGE_OPTIONS, seriesBars } from '../../features/admin/series'

const GRANULARITY_OPTIONS: { value: Granularity; label: string }[] = [
  { value: 'day', label: 'Jour' },
  { value: 'week', label: 'Semaine' },
  { value: 'month', label: 'Mois' },
]

/** Day granularity is refused over 12 months (too many bars to read); the backend accepts it anyway. */
function allowedGranularities(range: Range) {
  return range === '12m'
    ? GRANULARITY_OPTIONS.filter((option) => option.value !== 'day')
    : GRANULARITY_OPTIONS
}

function RetentionCard({ title, retention }: { title: string; retention: Retention }) {
  return (
    <div className="adm-kpi adm-kpi--wide">
      <dt className="adm-kpi__label">{title}</dt>
      <dd className="adm-kpi__value tnum">{formatPercent(retention.rate)}</dd>
      <dd className="adm-kpi__hint">
        {retention.cohortSize === 0
          ? 'Pas encore de cohorte complète.'
          : `${formatNumber(retention.returned)} sur ${formatNumber(retention.cohortSize)} personnes inscrites du ${formatDay(retention.cohortFrom)} au ${formatDay(retention.cohortTo)}`}
      </dd>
    </div>
  )
}

/** "Statistiques": adoption and growth of the platform — never the medical activity of the teams. */
export function AdminStatisticsPage() {
  const [range, setRange] = useState<Range>('90d')
  const [granularity, setGranularity] = useState<Granularity>('week')
  const adoptionRange: AdoptionRange = range === '7d' ? '30d' : range
  const series = useAdminData(() => fetchTimeseries(range, granularity), [range, granularity])
  const adoption = useAdminData(() => fetchAdoption(adoptionRange), [adoptionRange])

  function changeRange(next: Range) {
    setRange(next)
    if (next === '12m' && granularity === 'day') setGranularity('month')
    if (next === '7d') setGranularity('day')
  }

  const a = adoption.data
  const seniorityTotal = a ? a.seniority.reduce((sum, bucket) => sum + bucket.count, 0) : 0

  return (
    <section className="page adm-page">
      <AdminPageHeader
        title="Statistiques"
        lead="Adoption et croissance de MedVue. Les jours sont comptés dans le fuseau Europe/Bruxelles."
      />

      <div className="adm-toolbar">
        <SegmentedChoice label="Période" value={range} options={RANGE_OPTIONS} onChange={changeRange} />
        <SegmentedChoice
          label="Regroupement"
          value={granularity}
          options={allowedGranularities(range)}
          onChange={setGranularity}
        />
      </div>

      <LoadState loading={series.loading && !series.data} error={series.error} onRetry={series.reload} />
      {series.data && (
        <>
          <dl className="adm-kpis adm-kpis--3">
            <Kpi
              label="Nouveaux utilisateurs"
              value={series.data.totals.registrations}
              hint="Sur la période"
            />
            <Kpi label="Plannings créés" value={series.data.totals.plannings} hint="Sur la période" />
            <Kpi
              label="Utilisateurs actifs"
              value={series.data.totals.activeUsers}
              hint="Distincts sur la période"
            />
          </dl>
          <div className="adm-charts" aria-busy={series.loading}>
            <div className="card">
              <BarChart
                title="Inscriptions"
                unit="inscriptions"
                data={seriesBars(series.data, 'registrations')}
              />
            </div>
            <div className="card">
              <BarChart
                title="Plannings créés"
                unit="plannings"
                data={seriesBars(series.data, 'plannings')}
              />
            </div>
            <div className="card">
              <BarChart
                title="Utilisation globale"
                unit="jours-utilisateurs"
                data={seriesBars(series.data, 'activeUserDays')}
              />
            </div>
          </div>
        </>
      )}

      <LoadState loading={adoption.loading && !a} error={adoption.error} onRetry={adoption.reload} />
      {a && (
        <>
          <section aria-labelledby="adm-engagement" className="adm-section">
            <h2 id="adm-engagement" className="adm-section__title">
              Utilisateurs actifs quotidiens et mensuels
            </h2>
            <dl className="adm-kpis">
              <Kpi label="Actifs aujourd’hui (DAU)" value={a.current.dau} />
              <Kpi label="Actifs sur 30 jours (MAU)" value={a.current.mau} />
              <Kpi
                label="Moyenne quotidienne (30 j)"
                value={a.current.averageDauLast30Days.toLocaleString('fr-BE')}
              />
              <Kpi label="DAU / MAU" value={formatPercent(a.current.stickiness)} hint="Fidélité d’usage" />
            </dl>
            <div className="card">
              <LineChart
                title="DAU et MAU"
                series={[
                  { name: 'Actifs du jour (DAU)', className: 'adm-series-1' },
                  { name: 'Actifs sur 30 jours (MAU)', className: 'adm-series-2', dashed: true },
                ]}
                points={a.dauMau.map((point) => ({
                  key: point.day,
                  label: formatDay(point.day, {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric',
                  }),
                  shortLabel: formatDay(point.day, { day: 'numeric', month: 'short' }),
                  values: [point.dau, point.mau],
                }))}
              />
            </div>
          </section>

          <div className="adm-grid">
            <section aria-labelledby="adm-retention" className="card">
              <h2 id="adm-retention" className="adm-card__title">
                Taux de retour
              </h2>
              <dl className="adm-kpis adm-kpis--2">
                <RetentionCard title="À 7 jours" retention={a.retention.day7} />
                <RetentionCard title="À 30 jours" retention={a.retention.day30} />
              </dl>
              <p className="adm-footnote">
                Part des personnes inscrites (sur 90 jours de cohorte dont la fenêtre est complète) qui ont
                réutilisé MedVue un autre jour que celui de leur inscription, dans les 7 ou 30 jours suivants.
              </p>
            </section>

            <section aria-labelledby="adm-seniority" className="card">
              <h2 id="adm-seniority" className="adm-card__title">
                Ancienneté des comptes actifs
              </h2>
              <ul className="adm-bars">
                {a.seniority.map((bucket) => (
                  <li key={bucket.bucket}>
                    <span className="adm-bars__label">{SENIORITY_LABELS[bucket.bucket]}</span>
                    <span className="adm-bars__track" aria-hidden="true">
                      <span
                        className="adm-bars__fill"
                        style={{ width: seniorityTotal ? `${(bucket.count / seniorityTotal) * 100}%` : 0 }}
                      />
                    </span>
                    <span className="adm-bars__value tnum">{formatNumber(bucket.count)}</span>
                  </li>
                ))}
              </ul>
            </section>
          </div>

          <p className="adm-footnote">
            Un utilisateur est « actif » un jour donné s’il a ouvert ou prolongé une session MedVue ce
            jour-là.
            {a.dataSince
              ? ` Mesure disponible depuis le ${formatDay(a.dataSince)}.`
              : ' Aucune activité mesurée pour le moment.'}
          </p>
        </>
      )}
    </section>
  )
}
