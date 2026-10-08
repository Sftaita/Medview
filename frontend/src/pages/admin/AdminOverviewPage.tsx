import { useState } from 'react'
import { Link } from 'react-router-dom'
import { fetchOverview, fetchSystemHealth, fetchTimeseries } from '../../features/admin/api'
import { BarChart } from '../../features/admin/charts'
import {
  AdminPageHeader,
  Kpi,
  LoadState,
  SegmentedChoice,
  StatusBadge,
} from '../../features/admin/components'
import { formatDateTime, formatDay, formatRelative } from '../../features/admin/format'
import { RANGE_OPTIONS, seriesBars } from '../../features/admin/series'
import type { Range } from '../../features/admin/types'
import { useAdminData } from '../../features/admin/useAdminData'

/** "Vue générale": the platform at a glance — accounts, adoption, recent use, and the technical summary. */
export function AdminOverviewPage() {
  const [range, setRange] = useState<Range>('30d')
  const overview = useAdminData(fetchOverview, [])
  const series = useAdminData(() => fetchTimeseries(range), [range])
  const health = useAdminData(fetchSystemHealth, [])

  const o = overview.data

  return (
    <section className="page adm-page">
      <AdminPageHeader
        title="Vue générale"
        lead="Croissance, usage et état technique de la plateforme. Aucune donnée médicale ni contenu de planning."
      />

      <LoadState loading={overview.loading && !o} error={overview.error} onRetry={overview.reload} />
      {o && (
        <>
          <section aria-labelledby="adm-accounts" className="adm-section">
            <h2 id="adm-accounts" className="adm-section__title">
              Comptes et adoption
            </h2>
            <dl className="adm-kpis">
              <Kpi label="Utilisateurs inscrits" value={o.users.total} hint="Depuis le lancement" />
              <Kpi label="Comptes actifs" value={o.users.activeAccounts} hint="Non désactivés" />
              <Kpi label="Comptes désactivés" value={o.users.disabledAccounts} tone="muted" />
              <Kpi
                label="Nouvelles inscriptions"
                value={o.users.registeredLast30Days}
                hint="30 derniers jours"
              />
              <Kpi label="Plannings créés" value={o.plannings.total} hint="Depuis le lancement" />
              <Kpi
                label="Plannings créés (30 j)"
                value={o.plannings.createdLast30Days}
                hint="30 derniers jours"
              />
              <Kpi
                label="Utilisateurs actifs (7 j)"
                value={o.activity.activeUsersLast7Days}
                hint="Ont utilisé MedVue"
              />
              <Kpi
                label="Utilisateurs actifs (30 j)"
                value={o.activity.activeUsersLast30Days}
                hint="Ont utilisé MedVue"
              />
            </dl>
            <p className="adm-footnote">
              Un <strong>compte actif</strong> est un compte non désactivé. Un{' '}
              <strong>utilisateur actif</strong> a ouvert ou prolongé une session MedVue sur la période (jours
              comptés à Bruxelles)
              {o.activity.dataSince ? (
                <> — mesure disponible depuis le {formatDay(o.activity.dataSince)}</>
              ) : null}
              .
            </p>
          </section>
        </>
      )}

      <section aria-labelledby="adm-trends" className="adm-section">
        <div className="adm-section__head">
          <h2 id="adm-trends" className="adm-section__title">
            Évolution
          </h2>
          <SegmentedChoice label="Période" value={range} options={RANGE_OPTIONS} onChange={setRange} />
        </div>
        <LoadState loading={series.loading && !series.data} error={series.error} onRetry={series.reload} />
        {series.data && (
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
                title="Utilisateurs actifs"
                unit="utilisateurs distincts"
                data={seriesBars(series.data, 'activeUsers')}
                total={series.data.totals.activeUsers}
              />
            </div>
          </div>
        )}
      </section>

      <section aria-labelledby="adm-tech" className="adm-section">
        <div className="adm-section__head">
          <h2 id="adm-tech" className="adm-section__title">
            État technique
          </h2>
          <Link to="/admin/infrastructure" className="btn btn--ghost btn--sm">
            Détails
          </Link>
        </div>
        <LoadState loading={health.loading && !health.data} error={health.error} onRetry={health.reload} />
        {health.data && (
          <div className="card card--flush">
            <ul className="adm-checklist">
              <li>
                <span>API</span>
                <StatusBadge status={health.data.checks.api.status} />
              </li>
              <li>
                <span>Base de données</span>
                <StatusBadge status={health.data.checks.database.status} />
              </li>
              <li>
                <span>
                  Dernière sauvegarde
                  <span className="adm-checklist__meta">
                    {health.data.checks.backup.finishedAt
                      ? formatDateTime(health.data.checks.backup.finishedAt)
                      : health.data.checks.backup.detail}
                  </span>
                </span>
                <StatusBadge status={health.data.checks.backup.status} />
              </li>
              <li>
                <span>
                  Erreurs serveur (24 h)
                  <span className="adm-checklist__meta">{health.data.errors.detail}</span>
                </span>
                <StatusBadge status={health.data.errors.status} />
              </li>
              <li>
                <span>Version déployée</span>
                <span className="adm-mono">{health.data.version.release ?? 'Non renseignée'}</span>
              </li>
            </ul>
            <p className="adm-footnote adm-footnote--inset">
              Vérifié {formatRelative(health.data.checkedAt)}. « Non vérifiable » signifie qu’aucune
              information fiable n’est disponible — jamais « opérationnel » par défaut.
            </p>
          </div>
        )}
      </section>
    </section>
  )
}
