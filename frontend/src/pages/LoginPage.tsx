import { useRef, useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
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
    <section>
      <h1>Connexion</h1>
      <form onSubmit={handleSubmit}>
        <label>
          Email
          <input
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            required
          />
        </label>
        <label>
          Mot de passe
          <input
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="current-password"
            required
          />
        </label>
        {error && <p role="alert">{error}</p>}
        <button type="submit" disabled={isSubmitting}>
          Se connecter
        </button>
      </form>
      <p>
        Pas encore de compte ? <Link to="/register">Créer un compte</Link>
      </p>
    </section>
  )
}
