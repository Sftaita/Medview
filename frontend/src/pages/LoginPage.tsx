import { useRef, useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Field, PasswordField } from '../components/Field'
import { Icon } from '../components/Icon'
import { useAuth } from '../features/auth/useAuth'
import { ApiError } from '../lib/apiClient'
import { formatWaitTime } from '../lib/formatWaitTime'

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  // UAT found that disabled={isSubmitting} alone doesn't stop two
  // near-simultaneous submits (e.g. double-click, held Enter): the state
  // update that disables the button isn't applied to the DOM synchronously
  // relative to the second event. This ref is checked and set
  // synchronously, before anything else in the handler runs.
  const isSubmittingRef = useRef(false)

  const redirectTo = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname ?? '/'

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (isSubmittingRef.current) {
      return
    }
    isSubmittingRef.current = true
    setError(null)
    setIsSubmitting(true)

    try {
      await login(email, password)
      navigate(redirectTo, { replace: true })
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        setError('Email ou mot de passe incorrect.')
      } else if (err instanceof ApiError && err.status === 429) {
        setError(
          err.retryAfterSeconds !== null
            ? `Trop de tentatives. Merci de réessayer dans ${formatWaitTime(err.retryAfterSeconds)}.`
            : 'Trop de tentatives. Merci de réessayer plus tard.',
        )
      } else {
        setError('Une erreur est survenue. Merci de réessayer.')
      }
    } finally {
      isSubmittingRef.current = false
      setIsSubmitting(false)
    }
  }

  return (
    <section className="auth__stack">
      <div>
        <h1 className="auth__title">Connexion</h1>
        <p className="auth__intro">
          Accédez à vos gardes, à vos plannings et à votre calendrier d&apos;indisponibilités.
        </p>
      </div>
      <form onSubmit={handleSubmit} className="form">
        <Field
          label="Email"
          type="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          autoComplete="email"
          placeholder="prenom.nom@exemple.be"
          required
        />
        <PasswordField
          label="Mot de passe"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          autoComplete="current-password"
          required
        />
        {error && (
          <p role="alert" className="alert alert--error">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>{error}</span>
          </p>
        )}
        <button type="submit" className="btn btn--primary btn--lg btn--full" disabled={isSubmitting}>
          Se connecter
        </button>
      </form>
      <div className="auth__divider">Pas encore de compte&nbsp;?</div>
      <Link to="/register" className="btn btn--secondary btn--full">
        Créer un compte
      </Link>
    </section>
  )
}
