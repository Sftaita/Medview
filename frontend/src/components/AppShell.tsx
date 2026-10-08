import { Link, NavLink, Outlet, useMatch } from 'react-router-dom'
import { useAuth } from '../features/auth/useAuth'
import { Icon, type IconName } from './Icon'
import { Logo } from './Logo'

type NavItem = {
  to: string
  icon: IconName
  /** Full label (sidebar). */
  label: string
  /** Short label (bottom bar on a phone). */
  shortLabel: string
  end?: boolean
}

const NAV_ITEMS: NavItem[] = [
  { to: '/', icon: 'home', label: 'Tableau de bord', shortLabel: 'Accueil', end: true },
  { to: '/plannings', icon: 'layers', label: 'Plannings', shortLabel: 'Plannings' },
  { to: '/my-duties', icon: 'moon', label: 'Mes gardes', shortLabel: 'Gardes' },
  { to: '/my-availability', icon: 'calendarX', label: 'Mes indisponibilités', shortLabel: 'Indispos' },
]

function initials(firstName: string, lastName: string): string {
  return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase()
}

/**
 * Authenticated frame (docs/Design/react_dashboard): a left sidebar from 760px,
 * a brand bar with the account avatar on top and a four-entry bottom navigation
 * on a phone (one DOM, switched by CSS).
 */
export function AppShell() {
  const { user, logout } = useAuth()
  // The dashboard lays out its own margins, as in its mockup.
  const bare = useMatch('/') !== null
  const userInitials = user ? initials(user.firstName, user.lastName) : ''

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
              <Icon name={item.icon} size={20} strokeWidth={1.9} />
              {item.label}
            </NavLink>
          ))}
        </nav>

        {user?.platformAdmin && (
          <NavLink to="/admin" className="shell__nav-link shell__nav-link--admin">
            <Icon name="settings" size={20} strokeWidth={1.9} />
            Administration
          </NavLink>
        )}

        {user && (
          <div className="shell__user">
            <span className="shell__avatar">{userInitials}</span>
            <div className="shell__user-text">
              <div className="shell__user-name">
                {user.firstName} {user.lastName}
              </div>
              <Link to="/account">Mon compte</Link>
            </div>
            <button
              type="button"
              className="shell__icon-btn"
              onClick={logout}
              aria-label="Se déconnecter"
              title="Se déconnecter"
            >
              <Icon name="logout" size={18} strokeWidth={2} />
            </button>
          </div>
        )}
      </aside>

      <div className="shell__body">
        <header className="shell__topbar">
          <div className="shell__brand shell__brand--sm">
            <Logo size={30} />
            <span className="shell__brand-name">MedVue</span>
          </div>
          {user && (
            <Link to="/account" className="shell__avatar shell__avatar--sm" aria-label="Mon compte">
              {userInitials}
            </Link>
          )}
        </header>

        <main className={bare ? 'shell__content shell__content--bare' : 'shell__content'}>
          <Outlet />
        </main>
      </div>

      <nav aria-label="Navigation mobile" className="shell__tabbar">
        {NAV_ITEMS.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} className="shell__tab">
            <Icon name={item.icon} size={22} strokeWidth={1.9} />
            {item.shortLabel}
          </NavLink>
        ))}
      </nav>
    </div>
  )
}
