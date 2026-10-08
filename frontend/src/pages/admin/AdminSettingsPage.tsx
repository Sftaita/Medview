import { useState } from 'react'
import { Icon } from '../../components/Icon'
import { fetchSettings, grantPlatformAdmin, revokePlatformAdmin } from '../../features/admin/api'
import { AdminPageHeader, ConfirmDialog, LoadState } from '../../features/admin/components'
import { formatDuration, formatRelative, initials } from '../../features/admin/format'
import type { PlatformAdmin } from '../../features/admin/types'
import { useAdminData } from '../../features/admin/useAdminData'
import { useAuth } from '../../features/auth/useAuth'

/**
 * "Paramètres": platform administrators (the only thing editable here, password re-confirmed and audited) and
 * the security settings in force, read-only — configuration is never edited from the browser.
 */
export function AdminSettingsPage() {
  const { user: me } = useAuth()
  const settings = useAdminData(fetchSettings, [])
  const [granting, setGranting] = useState(false)
  const [email, setEmail] = useState('')
  const [revoking, setRevoking] = useState<PlatformAdmin | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const s = settings.data

  return (
    <section className="page adm-page">
      <AdminPageHeader
        title="Paramètres"
        lead="Administrateurs de la plateforme et paramètres de sécurité en vigueur."
      />

      {notice && (
        <p role="status" className="alert alert--success">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>{notice}</span>
        </p>
      )}

      <LoadState loading={settings.loading && !s} error={settings.error} onRetry={settings.reload} />
      {s && (
        <>
          <section className="card card--flush" aria-labelledby="adm-admins">
            <div className="card__header">
              <h2 id="adm-admins" className="adm-card__title">
                Administrateurs de la plateforme
              </h2>
              <button type="button" className="btn btn--secondary btn--sm" onClick={() => setGranting(true)}>
                <Icon name="plus" size={16} strokeWidth={2} />
                Ajouter
              </button>
            </div>
            <ul className="list">
              {s.platformAdmins.map((admin) => (
                <li key={admin.stableId} className="list-row">
                  <span className="avatar avatar--sm" aria-hidden="true">
                    {initials(admin.firstName, admin.lastName)}
                  </span>
                  <div className="list-row__main">
                    <div className="list-row__title">
                      {admin.firstName} {admin.lastName}
                      {admin.stableId === me?.stableId && <span className="tag adm-inline-tag">Vous</span>}
                    </div>
                    <div className="list-row__meta">
                      {admin.email} · dernière activité {formatRelative(admin.lastActivityAt).toLowerCase()}
                    </div>
                  </div>
                  {admin.stableId !== me?.stableId && (
                    <button
                      type="button"
                      className="btn btn--danger btn--sm"
                      onClick={() => setRevoking(admin)}
                    >
                      Retirer
                    </button>
                  )}
                </li>
              ))}
            </ul>
            <p className="adm-footnote adm-footnote--inset">
              Ce rôle est indépendant des rôles d’équipe et ne donne aucun droit sur les plannings. Le premier
              administrateur se nomme depuis la console du serveur (
              <span className="adm-mono">app:platform-admin grant</span>) ; on ne peut ni s’attribuer ni se
              retirer soi-même le rôle.
            </p>
          </section>

          <section className="card" aria-labelledby="adm-security">
            <h2 id="adm-security" className="adm-card__title">
              Sécurité des sessions
            </h2>
            <dl className="adm-facts">
              <div>
                <dt>Jeton d’accès</dt>
                <dd>{formatDuration(s.security.accessTokenTtlSeconds)}</dd>
              </div>
              <div>
                <dt>Session (jeton de rafraîchissement, glissant)</dt>
                <dd>{formatDuration(s.security.refreshTokenTtlSeconds)}</dd>
              </div>
              <div>
                <dt>Lien de réinitialisation du mot de passe</dt>
                <dd>{formatDuration(s.security.passwordResetTokenTtlSeconds)}</dd>
              </div>
              <div>
                <dt>Invitation d’équipe</dt>
                <dd>{formatDuration(s.security.invitationTtlHours * 3600)}</dd>
              </div>
              <div>
                <dt>Cookies sécurisés (HTTPS)</dt>
                <dd>{s.security.secureCookies ? 'Oui' : 'Non — à activer en production'}</dd>
              </div>
            </dl>
            <p className="adm-footnote">
              Valeurs issues de la configuration du serveur, affichées pour contrôle. Elles ne se modifient
              pas depuis l’interface.
            </p>
          </section>

          <section className="card" aria-labelledby="adm-data">
            <h2 id="adm-data" className="adm-card__title">
              Données de supervision
            </h2>
            <dl className="adm-facts">
              <div>
                <dt>Fuseau des statistiques</dt>
                <dd>{s.telemetry.timezone}</dd>
              </div>
              <div>
                <dt>Activité des utilisateurs</dt>
                <dd>Conservée {s.telemetry.activityRetentionDays} jours (utilisateur et date uniquement)</dd>
              </div>
              <div>
                <dt>Erreurs techniques</dt>
                <dd>Conservées {s.telemetry.technicalErrorRetentionDays} jours</dd>
              </div>
              <div>
                <dt>Journal d’audit</dt>
                <dd>Permanent, non modifiable</dd>
              </div>
              <div>
                <dt>Version</dt>
                <dd>
                  <span className="adm-mono">{s.version.release ?? 'non renseignée'}</span> (
                  {s.version.environment})
                </dd>
              </div>
            </dl>
          </section>
        </>
      )}

      {granting && (
        <ConfirmDialog
          title="Ajouter un administrateur de la plateforme"
          confirmLabel="Attribuer le rôle"
          withPassword
          onClose={() => setGranting(false)}
          onConfirm={async ({ password }) => {
            const updated = await grantPlatformAdmin(email.trim(), password)
            settings.setData(updated)
            setNotice(`${email.trim()} est désormais administrateur de la plateforme.`)
            setEmail('')
            setGranting(false)
          }}
        >
          <p>
            Le compte doit déjà exister. Il aura accès à toute l’administration de MedVue (comptes,
            statistiques, journal d’audit), sans aucun droit sur les plannings.
          </p>
          <label className="field">
            <span className="field__label">Email du compte</span>
            <input
              className="field__input"
              type="email"
              autoComplete="off"
              data-autofocus
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
            />
          </label>
        </ConfirmDialog>
      )}

      {revoking && (
        <ConfirmDialog
          title="Retirer le rôle d’administrateur"
          confirmLabel="Retirer le rôle"
          danger
          withPassword
          onClose={() => setRevoking(null)}
          onConfirm={async ({ password }) => {
            const updated = await revokePlatformAdmin(revoking.stableId, password)
            settings.setData(updated)
            setNotice(
              `${revoking.firstName} ${revoking.lastName} n’est plus administrateur de la plateforme.`,
            )
            setRevoking(null)
          }}
        >
          <p>
            <strong>
              {revoking.firstName} {revoking.lastName}
            </strong>{' '}
            ({revoking.email}) perdra l’accès à l’administration dès sa prochaine action. Son compte MedVue
            reste actif.
          </p>
        </ConfirmDialog>
      )}
    </section>
  )
}
