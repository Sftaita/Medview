import { useAuth } from '../features/auth/useAuth'

export function AccountPage() {
  const { user, logout } = useAuth()

  if (!user) {
    return null
  }

  return (
    <section>
      <h1>Mon compte</h1>
      <dl>
        <dt>Nom</dt>
        <dd>
          {user.firstName} {user.lastName}
        </dd>
        <dt>Email</dt>
        <dd>{user.email}</dd>
        <dt>Statut</dt>
        <dd>{user.active ? 'Actif' : 'Désactivé'}</dd>
      </dl>
      <button type="button" onClick={logout}>
        Se déconnecter
      </button>
    </section>
  )
}
