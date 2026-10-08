import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Icon } from '../../components/Icon'
import { fetchAuditEvents, fetchUser, runAccountAction, type AccountAction } from '../../features/admin/api'
import { AdminPageHeader, ConfirmDialog, LoadState } from '../../features/admin/components'
import {
  AUDIT_TYPE_LABELS,
  OUTCOME_LABELS,
  ROLE_LABELS,
  formatDate,
  formatDateTime,
  formatDay,
  formatRelative,
  initials,
  personName,
} from '../../features/admin/format'
import { useAdminData } from '../../features/admin/useAdminData'
import { useAuth } from '../../features/auth/useAuth'

const ACTIONS: Record<AccountAction, { title: string; confirm: string; danger: boolean; body: string }> = {
  deactivate: {
    title: 'Désactiver ce compte',
    confirm: 'Désactiver',
    danger: true,
    body: 'La personne est déconnectée immédiatement de tous ses appareils et ne peut plus se connecter. Ses plannings, gardes et historique sont conservés tels quels.',
  },
  reactivate: {
    title: 'Réactiver ce compte',
    confirm: 'Réactiver',
    danger: false,
    body: 'La personne pourra de nouveau se connecter avec son mot de passe. Aucune session n’est restaurée.',
  },
  'revoke-sessions': {
    title: 'Révoquer toutes les sessions',
    confirm: 'Révoquer les sessions',
    danger: true,
    body: 'Toutes les sessions ouvertes (navigateurs, téléphones) sont fermées immédiatement. Le compte reste actif : la personne devra simplement se reconnecter.',
  },
}

/** One account: facts, sessions, planning context (read-only), audit history, and the three audited actions. */
export function AdminUserDetailPage() {
  const { stableId = '' } = useParams()
  const { user: me } = useAuth()
  const detail = useAdminData(() => fetchUser(stableId), [stableId])
  const history = useAdminData(() => fetchAuditEvents({ user: stableId, perPage: 10 }), [stableId])
  const [action, setAction] = useState<AccountAction | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const u = detail.data
  const isSelf = u !== null && me?.stableId === u.stableId

  async function confirm({ reason }: { reason: string }) {
    if (!action) return
    const updated = await runAccountAction(stableId, action, reason)
    detail.setData(updated)
    history.reload()
    setNotice(
      action === 'deactivate'
        ? 'Compte désactivé et sessions fermées.'
        : action === 'reactivate'
          ? 'Compte réactivé.'
          : 'Toutes les sessions ont été révoquées.',
    )
    setAction(null)
  }

  return (
    <section className="page adm-page">
      <Link to="/admin/users" className="btn btn--ghost btn--sm adm-backlink">
        <Icon name="left" size={16} strokeWidth={2} />
        Utilisateurs
      </Link>

      <LoadState loading={detail.loading && !u} error={detail.error} onRetry={detail.reload} />
      {u && (
        <>
          <AdminPageHeader
            title={`${u.firstName} ${u.lastName}`}
            lead={
              <span className="adm-identity">
                <span className="avatar avatar--sm" aria-hidden="true">
                  {initials(u.firstName, u.lastName)}
                </span>
                {u.email}
                {u.active ? (
                  <span className="tag tag--green">Actif</span>
                ) : (
                  <span className="tag tag--red">Désactivé</span>
                )}
                {u.platformAdmin && <span className="tag tag--blue">Administrateur de la plateforme</span>}
              </span>
            }
          />

          {notice && (
            <p role="status" className="alert alert--success">
              <Icon name="check" size={18} strokeWidth={2} />
              <span>{notice}</span>
            </p>
          )}

          <div className="adm-grid">
            <div className="card">
              <h2 className="adm-card__title">Compte</h2>
              <dl className="adm-facts">
                <div>
                  <dt>Inscription</dt>
                  <dd>{formatDateTime(u.createdAt)}</dd>
                </div>
                <div>
                  <dt>Téléphone</dt>
                  <dd className="tnum">{u.phone ?? '—'}</dd>
                </div>
                <div>
                  <dt>Email vérifié</dt>
                  <dd>{u.emailVerified ? 'Oui' : 'Non (vérification non encore proposée par MedVue)'}</dd>
                </div>
                <div>
                  <dt>Dernière activité</dt>
                  <dd>
                    {u.activity.lastActivityAt
                      ? `${formatRelative(u.activity.lastActivityAt)} · ${formatDateTime(u.activity.lastActivityAt)}`
                      : 'Aucune activité connue'}
                  </dd>
                </div>
                <div>
                  <dt>Jours d’activité (30 j)</dt>
                  <dd className="tnum">{u.activity.activeDaysLast30}</dd>
                </div>
                <div>
                  <dt>Sessions actives</dt>
                  <dd className="tnum">{u.activeSessionCount}</dd>
                </div>
              </dl>
            </div>

            <div className="card">
              <h2 className="adm-card__title">Actions</h2>
              {isSelf ? (
                <p className="muted">Ces actions ne sont pas disponibles sur votre propre compte.</p>
              ) : (
                <div className="adm-actions">
                  {u.active ? (
                    <button
                      type="button"
                      className="btn btn--danger"
                      onClick={() => setAction('deactivate')}
                      disabled={u.platformAdmin}
                    >
                      Désactiver le compte
                    </button>
                  ) : (
                    <button
                      type="button"
                      className="btn btn--primary"
                      onClick={() => setAction('reactivate')}
                    >
                      Réactiver le compte
                    </button>
                  )}
                  <button
                    type="button"
                    className="btn btn--secondary"
                    onClick={() => setAction('revoke-sessions')}
                  >
                    Révoquer toutes les sessions
                  </button>
                  {u.platformAdmin && u.active && (
                    <p className="field__hint">
                      Un administrateur de la plateforme ne peut pas être désactivé : retirez d’abord son rôle
                      dans Paramètres.
                    </p>
                  )}
                </div>
              )}
              <p className="adm-footnote">
                Chaque action est confirmée et enregistrée dans le journal d’audit.
              </p>
            </div>
          </div>

          <div className="card card--flush">
            <h2 className="adm-card__title adm-card__title--inset">Sessions récentes</h2>
            {u.sessions.length === 0 ? (
              <p className="adm-empty">Aucune session enregistrée.</p>
            ) : (
              <div className="adm-table-wrap">
                <table className="adm-table">
                  <thead>
                    <tr>
                      <th scope="col">Appareil</th>
                      <th scope="col">Ouverte</th>
                      <th scope="col">Dernier usage</th>
                      <th scope="col">État</th>
                    </tr>
                  </thead>
                  <tbody>
                    {u.sessions.map((session) => (
                      <tr key={session.startedAt + session.lastUsedAt}>
                        <th scope="row" data-label="Appareil">
                          {session.device}
                        </th>
                        <td data-label="Ouverte">{formatDateTime(session.startedAt)}</td>
                        <td data-label="Dernier usage">{formatDateTime(session.lastUsedAt)}</td>
                        <td data-label="État">
                          {session.active ? (
                            <span className="tag tag--green">Active</span>
                          ) : (
                            <span className="tag">Terminée</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          <div className="card card--flush">
            <h2 className="adm-card__title adm-card__title--inset">Participation à des plannings</h2>
            <p className="adm-footnote adm-footnote--inset">
              Contexte du compte uniquement : la gestion des plannings reste aux créateurs et administrateurs
              d’équipe.
            </p>
            {u.plannings.length === 0 ? (
              <p className="adm-empty">Aucun planning.</p>
            ) : (
              <ul className="list">
                {u.plannings.map((planning) => (
                  <li key={planning.stableId} className="list-row">
                    <div className="list-row__main">
                      <div className="list-row__title">{planning.name}</div>
                      <div className="list-row__meta">
                        {[
                          planning.creator ? 'Créateur' : null,
                          ...planning.roles.map((role) => ROLE_LABELS[role] ?? role),
                        ]
                          .filter(Boolean)
                          .join(' · ')}{' '}
                        · créé le {formatDate(planning.createdAt)}
                      </div>
                    </div>
                    {!planning.ongoing && <span className="tag">Terminée</span>}
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="card card--flush">
            <h2 className="adm-card__title adm-card__title--inset">Historique d’audit</h2>
            {history.data && history.data.items.length > 0 ? (
              <ul className="list">
                {history.data.items.map((event) => (
                  <li key={event.stableId} className="list-row">
                    <div className="list-row__main">
                      <div className="list-row__title">{AUDIT_TYPE_LABELS[event.type]}</div>
                      <div className="list-row__meta">
                        {formatDateTime(event.occurredAt)}
                        {event.actor && event.actor.stableId !== u.stableId
                          ? ` · par ${personName(event.actor)}`
                          : ''}
                        {event.actorKind === 'CONSOLE' ? ' · console serveur' : ''}
                        {typeof event.context.reason === 'string' ? ` · « ${event.context.reason} »` : ''}
                        {event.context.backfilled ? ' · reconstitué' : ''}
                      </div>
                    </div>
                    {event.outcome !== 'SUCCESS' && (
                      <span className="tag tag--amber">{OUTCOME_LABELS[event.outcome]}</span>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="adm-empty">{history.loading ? 'Chargement…' : 'Aucun événement.'}</p>
            )}
            {u.activity.firstActivityDay && (
              <p className="adm-footnote adm-footnote--inset">
                Activité mesurée depuis le {formatDay(u.activity.firstActivityDay)}.
              </p>
            )}
          </div>
        </>
      )}

      {action && u && (
        <ConfirmDialog
          title={ACTIONS[action].title}
          confirmLabel={ACTIONS[action].confirm}
          danger={ACTIONS[action].danger}
          withReason
          onConfirm={confirm}
          onClose={() => setAction(null)}
        >
          <p>
            <strong>
              {u.firstName} {u.lastName}
            </strong>{' '}
            ({u.email})
          </p>
          <p>{ACTIONS[action].body}</p>
        </ConfirmDialog>
      )}
    </section>
  )
}
