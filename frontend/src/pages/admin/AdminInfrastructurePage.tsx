import type { ReactNode } from 'react'
import { Icon } from '../../components/Icon'
import { fetchSystemHealth } from '../../features/admin/api'
import { AdminPageHeader, LoadState, StatusBadge } from '../../features/admin/components'
import { formatBytes, formatDateTime, formatNumber, formatRelative } from '../../features/admin/format'
import type { Check } from '../../features/admin/types'
import { useAdminData } from '../../features/admin/useAdminData'

function CheckCard({ title, check, children }: { title: string; check: Check; children?: ReactNode }) {
  return (
    <article className={`card adm-check adm-check--${check.status}`}>
      <header className="adm-check__head">
        <h2 className="adm-card__title">{title}</h2>
        <StatusBadge status={check.status} />
      </header>
      <p className="adm-check__detail">{check.detail}</p>
      {children && <dl className="adm-facts adm-facts--compact">{children}</dl>}
    </article>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  )
}

/**
 * "Infrastructure": read-only supervision of MedVue's own resources, verified when the page loads. No action,
 * no command, no secret, nothing about the other applications of the shared server.
 */
export function AdminInfrastructurePage() {
  const health = useAdminData(fetchSystemHealth, [])
  const h = health.data

  return (
    <section className="page adm-page">
      <AdminPageHeader
        title="Infrastructure"
        lead="Vérifications réelles, en lecture seule, des ressources de MedVue uniquement."
        actions={
          <button
            type="button"
            className="btn btn--secondary btn--sm"
            onClick={health.reload}
            disabled={health.loading}
          >
            <Icon name="loader" size={16} strokeWidth={2} />
            {health.loading ? 'Vérification…' : 'Revérifier'}
          </button>
        }
      />

      <LoadState loading={health.loading && !h} error={health.error} onRetry={health.reload} />
      {h && (
        <>
          <div className="card adm-overall">
            <StatusBadge status={h.overall} />
            <span>
              État global — vérifié {formatRelative(h.checkedAt)} ({formatDateTime(h.checkedAt)})
            </span>
            <span className="adm-overall__version">
              Version <span className="adm-mono">{h.version.release ?? 'non renseignée'}</span> ·
              environnement <span className="adm-mono">{h.version.environment}</span>
            </span>
          </div>

          <div className="adm-checks">
            <CheckCard title="API" check={h.checks.api}>
              {h.checks.api.phpVersion && <Fact label="PHP">{h.checks.api.phpVersion}</Fact>}
            </CheckCard>

            <CheckCard title="Base de données" check={h.checks.database}>
              {h.checks.database.latencyMs != null && (
                <Fact label="Latence">{h.checks.database.latencyMs.toLocaleString('fr-BE')} ms</Fact>
              )}
              {h.checks.database.serverVersion && (
                <Fact label="PostgreSQL">{h.checks.database.serverVersion}</Fact>
              )}
              {h.checks.database.sizeBytes != null && (
                <Fact label="Taille de la base">{formatBytes(h.checks.database.sizeBytes)}</Fact>
              )}
            </CheckCard>

            <CheckCard title="Migrations" check={h.checks.migrations}>
              {h.checks.migrations.current && (
                <Fact label="Version appliquée">
                  <span className="adm-mono adm-break">
                    {h.checks.migrations.current.replace('DoctrineMigrations\\', '')}
                  </span>
                </Fact>
              )}
            </CheckCard>

            <CheckCard title="File des calculs de planning" check={h.checks.jobQueue}>
              {h.checks.jobQueue.waiting !== undefined && (
                <Fact label="En attente">{h.checks.jobQueue.waiting}</Fact>
              )}
              {h.checks.jobQueue.last7Days && (
                <>
                  <Fact label="Réussis (7 j)">{formatNumber(h.checks.jobQueue.last7Days.succeeded)}</Fact>
                  <Fact label="Échoués (7 j)">{formatNumber(h.checks.jobQueue.last7Days.failed)}</Fact>
                  <Fact label="Durée moyenne">
                    {h.checks.jobQueue.last7Days.averageSeconds !== null
                      ? `${h.checks.jobQueue.last7Days.averageSeconds.toLocaleString('fr-BE')} s`
                      : '—'}
                  </Fact>
                  <Fact label="Durée maximale">
                    {h.checks.jobQueue.last7Days.maxSeconds !== null
                      ? `${h.checks.jobQueue.last7Days.maxSeconds.toLocaleString('fr-BE')} s`
                      : '—'}
                  </Fact>
                </>
              )}
            </CheckCard>

            <CheckCard title="Emails de publication" check={h.checks.emails}>
              {h.checks.emails.sentLast7Days !== undefined && (
                <Fact label="Envoyés (7 j)">{formatNumber(h.checks.emails.sentLast7Days)}</Fact>
              )}
              {h.checks.emails.failed !== undefined && <Fact label="En échec">{h.checks.emails.failed}</Fact>}
            </CheckCard>

            <CheckCard title="Dernière sauvegarde" check={h.checks.backup}>
              <Fact label="Terminée">{formatDateTime(h.checks.backup.finishedAt)}</Fact>
              {h.checks.backup.postgresDumpBytes != null && (
                <Fact label="Taille du dump">{formatBytes(h.checks.backup.postgresDumpBytes)}</Fact>
              )}
            </CheckCard>

            <CheckCard title="Dernier test de restauration" check={h.checks.restoreTest}>
              <Fact label="Terminé">{formatDateTime(h.checks.restoreTest.finishedAt)}</Fact>
            </CheckCard>

            <CheckCard title="Erreurs serveur" check={h.errors}>
              {h.errors.total24h !== undefined && <Fact label="24 heures">{h.errors.total24h}</Fact>}
              {h.errors.total7d !== undefined && <Fact label="7 jours">{h.errors.total7d}</Fact>}
            </CheckCard>
          </div>

          {h.errors.latest && h.errors.latest.length > 0 && (
            <div className="card card--flush">
              <h2 className="adm-card__title adm-card__title--inset">Dernières erreurs critiques</h2>
              <div className="adm-table-wrap">
                <table className="adm-table">
                  <thead>
                    <tr>
                      <th scope="col">Date</th>
                      <th scope="col">Exception</th>
                      <th scope="col">Emplacement</th>
                    </tr>
                  </thead>
                  <tbody>
                    {h.errors.latest.map((error, index) => (
                      <tr key={`${error.occurredAt}-${index}`}>
                        <td data-label="Date" className="adm-nowrap">
                          {formatDateTime(error.occurredAt)}
                        </td>
                        <th scope="row" data-label="Exception" className="adm-mono">
                          {error.exceptionClass}
                        </th>
                        <td data-label="Emplacement" className="adm-mono">
                          {[error.httpMethod, error.route].filter(Boolean).join(' ') || '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          <p className="adm-footnote">
            Sauvegardes : état lu dans les fichiers de statut écrits par les scripts de sauvegarde du serveur
            (montés en lecture seule). Aucune sauvegarde ni restauration ne peut être lancée d’ici. Si la base
            de données est injoignable, cette page ne s’affiche pas : la sonde publique{' '}
            <span className="adm-mono">/api/health</span> reste la référence externe.
          </p>
        </>
      )}
    </section>
  )
}
