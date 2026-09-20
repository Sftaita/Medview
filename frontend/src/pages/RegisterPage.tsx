import { Link, useNavigate } from 'react-router-dom'
import { RegistrationForm } from '../features/auth/RegistrationForm'

export function RegisterPage() {
  const navigate = useNavigate()

  return (
    <section>
      <h1>Créer un compte</h1>
      <RegistrationForm onRegistered={() => navigate('/', { replace: true })} />
      <p>
        Déjà un compte ? <Link to="/login">Se connecter</Link>
      </p>
    </section>
  )
}
