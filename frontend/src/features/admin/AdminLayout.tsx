import { Link, NavLink, Outlet } from 'react-router-dom'
import { Icon, type IconName } from '../../components/Icon'
import { Logo } from '../../components/Logo'
import { useAuth } from '../auth/useAuth'
import { initials } from './format'
import './admin.css'

const NAV: { to: string; label: string; icon: IconName; end?: boolean }[] = [
  { to: '/admin', label: 'Vue générale', icon: 'home', end: true },
  { to: '/admin/users', label: 'Utilisateurs', icon: 'users' },
  { to: '/admin/statistics', label: 'Statistiques', icon: 'pulse' },
  { to: '/admin/activity', label: 'Activité', icon: 'rows' },
  { to: '/admin/infrastructure', label: 'Infrastructure', icon: 'layers' },
  { to: '/admin/settings', label: 'Paramètres', icon: 'settings' },
]

/**
 * Frame of the platform administration (docs/admin.md §9): its own navigation, same charter as the app — a
 * sidebar from 760px, a scrollable tab strip under the brand bar on a phone. Rendered only for platform
 * administrators (AdminRoute); the data itself is protected by the API, never by this component.
 */
export function AdminLayout() {
  const { user } = useAuth()

  return (
    <div className="shell adm-shell">
      <aside className="shell__sidebar adm-sidebar">
        <div className="shell__brand">
          <Logo size={36} />
          <span className="shell__brand-name">MedVue</span>
          <span className="adm-badge">Admin</span>
        </div>

        <nav aria-label="Navigation de l’administration" className="shell__nav">
          {NAV.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className="shell__nav-link">
              <Icon name={item.icon} size={20} strokeWidth={1.9} />
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="adm-sidebar__footer">
          <Link to="/" className="btn btn--ghost btn--sm adm-back">
            <Icon name="left" size={16} strokeWidth={2} />
            Retour à MedVue
          </Link>
          {user && (
            <div className="shell__user">
              <span className="shell__avatar">{initials(user.firstName, user.lastName)}</span>
              <div className="shell__user-text">
                <div className="shell__user-name">
                  {user.firstName} {user.lastName}
                </div>
                <span className="adm-sidebar__role">Administrateur de la plateforme</span>
              </div>
            </div>
          )}
        </div>
      </aside>

      <div className="shell__body">
        <header className="shell__topbar adm-topbar">
          <div className="shell__brand shell__brand--sm">
            <Logo size={30} />
            <span className="shell__brand-name">MedVue</span>
            <span className="adm-badge">Admin</span>
          </div>
          <Link to="/" className="btn btn--ghost btn--sm" aria-label="Retour à MedVue">
            <Icon name="x" size={18} strokeWidth={2} />
          </Link>
        </header>
        <nav aria-label="Navigation de l’administration (mobile)" className="adm-tabs">
          {NAV.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className="adm-tabs__link">
              {item.label}
            </NavLink>
          ))}
        </nav>

        <main className="shell__content adm-content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
