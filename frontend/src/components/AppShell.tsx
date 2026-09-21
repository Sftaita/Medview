import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../features/auth/useAuth'
import { HealthStatus } from '../features/system/HealthStatus'
import { Icon, type IconName } from './Icon'
import { Logo } from './Logo'

type NavItem = {
  to: string
  icon: IconName
  /** Full label (sidebar). */
  label: string
  /** Short label (bottom bar, 5 entries on a phone). */
  shortLabel: string
  end?: boolean
}

const NAV_ITEMS: NavItem[] = [
  { to: '/', icon: 'home', label: 'Tableau de bord', shortLabel: 'Accueil', end: true },
  { to: '/plannings', icon: 'layers', label: 'Plannings', shortLabel: 'Plannings' },
  { to: '/my-duties', icon: 'moon', label: 'Mes gardes', shortLabel: 'Mes gardes' },
  { to: '/my-availability', icon: 'calendarX', label: 'Mes indisponibilités', shortLabel: 'Calendrier' },
]

function initials(firstName: string, lastName: string): string {
  return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase()
}

/**
 * Authenticated frame: a left sidebar on desktop, a brand bar on top and a
 * five-entry bottom navigation on a phone (one DOM, switched by CSS).
 */
export function AppShell() {
  const { user, logout } = useAuth()

  return (
    <div className="shell">
      <aside className="shell__sidebar">
        <div className="shell__brand">
          <Logo size={36} />
          <span className="shell__brand-name">MedVue</span>
        </div>

        <nav aria-label="Navigation principale" className="shell__nav">
          {NAV_ITEMS.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className="shell__nav-link">
              <Icon name={item.icon} size={20} />
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="shell__sidebar-footer">
          <HealthStatus />
          {user && (
            <NavLink to="/account" className="shell__account">
              <span className="avatar avatar--sm">{initials(user.firstName, user.lastName)}</span>
              <span>
                <span className="shell__account-name">
                  {user.firstName} {user.lastName}
                </span>
                <span className="shell__account-sub">Mon compte</span>
              </span>
            </NavLink>
          )}
          <button type="button" className="btn btn--ghost btn--sm shell__logout" onClick={logout}>
            <Icon name="logout" size={18} />
            Se déconnecter
          </button>
        </div>
      </aside>

      <div className="shell__body">
        <header className="shell__topbar">
          <Logo size={32} />
          <span className="shell__brand-name">MedVue</span>
        </header>

        <main className="shell__content">
          <Outlet />
        </main>
      </div>

      <nav aria-label="Navigation mobile" className="shell__tabbar">
        {NAV_ITEMS.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} className="shell__tab">
            <span className="shell__tab-icon">
              <Icon name={item.icon} size={22} />
            </span>
            <span className="shell__tab-label">{item.shortLabel}</span>
          </NavLink>
        ))}
        <NavLink to="/account" className="shell__tab">
          <span className="shell__tab-icon">
            <Icon name="user" size={22} />
          </span>
          <span className="shell__tab-label">Compte</span>
        </NavLink>
      </nav>
    </div>
  )
}
