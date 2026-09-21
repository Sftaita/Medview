import { Icon } from '../components/Icon'
import { useAuth } from '../features/auth/useAuth'

export function AccountPage() {
  const { user, logout } = useAuth()

  if (!user) {
    return null
  }

  return (
    <section className="page">
      <header className="account__header">
        <span className="avatar account__avatar">
          {`${user.firstName.charAt(0)}${user.lastName.charAt(0)}`.toUpperCase()}
        </span>
        <div>
          <h1>Mon compte</h1>
          <p className="page__lead">
            {user.firstName} {user.lastName}
          </p>
        </div>
      </header>

      <div className="card card--flush">
        <dl className="account__details">
          <div>
            <dt>Nom</dt>
            <dd>
              {user.firstName} {user.lastName}
            </dd>
          </div>
          <div>
            <dt>Email</dt>
            <dd>{user.email}</dd>
          </div>
          <div>
            <dt>Statut</dt>
            <dd>{user.active ? 'Actif' : 'Désactivé'}</dd>
          </div>
        </dl>
      </div>

      <button type="button" className="btn btn--secondary account__logout" onClick={logout}>
        <Icon name="logout" size={18} />
        Se déconnecter
      </button>
    </section>
  )
}
