import { useState } from 'react'
import { Link } from 'react-router-dom'
import { fetchAuditEvents, fetchTechnicalErrors } from '../../features/admin/api'
import { AdminPageHeader, LoadState, Pagination, SegmentedChoice } from '../../features/admin/components'
import {
  AUDIT_TYPE_LABELS,
  DENIED_REASONS,
  OUTCOME_LABELS,
  formatDateTime,
  personName,
} from '../../features/admin/format'
import type { AuditEvent, AuditEventType } from '../../features/admin/types'
import { useAdminData } from '../../features/admin/useAdminData'

type View = 'audit' | 'errors'

function actorLabel(event: AuditEvent): string {
  if (event.actorKind === 'CONSOLE') return 'Console serveur'
  if (event.actorKind === 'SYSTEM') return 'Système'
  if (event.actor && event.target && event.actor.stableId === event.target.stableId)
    return 'La personne elle-même'
  return personName(event.actor)
}

function contextLabel(event: AuditEvent): string {
  const parts: string[] = []
  const { reason, denied, revokedSessions, via, backfilled } = event.context
  if (typeof denied === 'string') parts.push(`Motif du refus : ${DENIED_REASONS[denied] ?? denied}`)
  if (typeof reason === 'string') parts.push(`« ${reason} »`)
  if (typeof revokedSessions === 'number') parts.push(`${revokedSessions} session(s) fermée(s)`)
  if (via === 'invitation') parts.push('via une invitation')
  if (backfilled) parts.push('reconstitué depuis la date d’inscription')
  return parts.join(' · ')
}

/**
 * "Activité": the append-only audit log of sensitive actions, and — separately, never mixed — the technical
 * error log. Neither carries medical data nor planning content.
 */
export function AdminActivityPage() {
  const [view, setView] = useState<View>('audit')
  const [type, setType] = useState<AuditEventType | ''>('')
  const [outcome, setOutcome] = useState('')
  const [page, setPage] = useState(1)
  const [errorPage, setErrorPage] = useState(1)

  const events = useAdminData(
    () => fetchAuditEvents({ type, outcome, page, perPage: 30 }),
    [type, outcome, page],
  )
  const errors = useAdminData(() => fetchTechnicalErrors(errorPage), [errorPage])

  return (
    <section className="page adm-page">
      <AdminPageHeader
        title="Activité"
        lead="Journal d’audit des actions sensibles (permanent, non modifiable) et journal des erreurs techniques (90 jours)."
      />

      <SegmentedChoice
        label="Journal"
        value={view}
        options={[
          { value: 'audit', label: 'Journal d’audit' },
          { value: 'errors', label: 'Erreurs techniques' },
        ]}
        onChange={setView}
      />

      {view === 'audit' ? (
        <>
          <div className="adm-toolbar">
            <label className="adm-select">
              <span className="field__label">Type d’événement</span>
              <select
                className="field__input"
                value={type}
                onChange={(event) => {
                  setType(event.target.value as AuditEventType | '')
                  setPage(1)
                }}
              >
                <option value="">Tous</option>
                {Object.entries(AUDIT_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            <label className="adm-select">
              <span className="field__label">Résultat</span>
              <select
                className="field__input"
                value={outcome}
                onChange={(event) => {
                  setOutcome(event.target.value)
                  setPage(1)
                }}
              >
                <option value="">Tous</option>
                {Object.entries(OUTCOME_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <LoadState loading={events.loading && !events.data} error={events.error} onRetry={events.reload} />
          {events.data && (
            <div className="card card--flush" aria-busy={events.loading}>
              {events.data.items.length === 0 ? (
                <p className="adm-empty">Aucun événement.</p>
              ) : (
                <div className="adm-table-wrap">
                  <table className="adm-table">
                    <thead>
                      <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Événement</th>
                        <th scope="col">Acteur</th>
                        <th scope="col">Cible</th>
                        <th scope="col">Résultat</th>
                        <th scope="col">Contexte</th>
                      </tr>
                    </thead>
                    <tbody>
                      {events.data.items.map((event) => (
                        <tr key={event.stableId}>
                          <td data-label="Date" className="adm-nowrap">
                            {formatDateTime(event.occurredAt)}
                          </td>
                          <th scope="row" data-label="Événement">
                            {AUDIT_TYPE_LABELS[event.type]}
                          </th>
                          <td data-label="Acteur">{actorLabel(event)}</td>
                          <td data-label="Cible">
                            {event.target ? (
                              <Link to={`/admin/users/${event.target.stableId}`} className="adm-link">
                                {personName(event.target)}
                              </Link>
                            ) : (
                              '—'
                            )}
                          </td>
                          <td data-label="Résultat">
                            <span
                              className={`tag ${event.outcome === 'SUCCESS' ? 'tag--green' : event.outcome === 'DENIED' ? 'tag--amber' : 'tag--red'}`}
                            >
                              {OUTCOME_LABELS[event.outcome]}
                            </span>
                          </td>
                          <td data-label="Contexte" className="adm-context">
                            {contextLabel(event) || '—'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              <Pagination
                page={events.data.page}
                pageCount={events.data.pageCount}
                total={events.data.total}
                onChange={setPage}
              />
            </div>
          )}
        </>
      ) : (
        <>
          <LoadState loading={errors.loading && !errors.data} error={errors.error} onRetry={errors.reload} />
          {errors.data && (
            <div className="card card--flush" aria-busy={errors.loading}>
              {errors.data.items.length === 0 ? (
                <p className="adm-empty">Aucune erreur serveur enregistrée sur les 90 derniers jours.</p>
              ) : (
                <div className="adm-table-wrap">
                  <table className="adm-table">
                    <thead>
                      <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Origine</th>
                        <th scope="col">Exception</th>
                        <th scope="col">Emplacement</th>
                      </tr>
                    </thead>
                    <tbody>
                      {errors.data.items.map((error, index) => (
                        <tr key={`${error.occurredAt}-${index}`}>
                          <td data-label="Date" className="adm-nowrap">
                            {formatDateTime(error.occurredAt)}
                          </td>
                          <td data-label="Origine">{error.source === 'HTTP' ? 'API' : 'Worker'}</td>
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
              )}
              <Pagination
                page={errors.data.page}
                pageCount={errors.data.pageCount}
                total={errors.data.total}
                onChange={setErrorPage}
              />
            </div>
          )}
          <p className="adm-footnote">
            Seuls la classe de l’exception et l’emplacement sont conservés, jamais le message (il peut
            contenir des données saisies). Le détail complet reste dans les journaux du serveur.
          </p>
        </>
      )}
    </section>
  )
}
