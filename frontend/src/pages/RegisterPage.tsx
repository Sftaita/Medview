import { Link, useNavigate } from 'react-router-dom'
import { RegistrationForm } from '../features/auth/RegistrationForm'

export function RegisterPage() {
  const navigate = useNavigate()

  return (
    <section className="auth__stack">
      <div>
        <h1 className="auth__title">Créer un compte</h1>
        <p className="auth__intro">
          Créez votre compte pour consulter vos gardes et déclarer vos indisponibilités.
        </p>
      </div>
      <RegistrationForm onRegistered={() => navigate('/', { replace: true })} />
      <p className="auth__alt">
        Déjà un compte ? <Link to="/login">Se connecter</Link>
      </p>
    </section>
  )
}
