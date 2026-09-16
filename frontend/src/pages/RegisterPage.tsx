import { useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../features/auth/useAuth'
import { ApiError } from '../lib/apiClient'
import { formatWaitTime } from '../lib/formatWaitTime'

export function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()

  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [email, setEmail] = useState('')
  const [plainPassword, setPlainPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  // See LoginPage: disabled={isSubmitting} alone doesn't stop two
  // near-simultaneous submits, since the state update isn't applied to the
  // DOM synchronously relative to a second click/Enter fired right after.
  const isSubmittingRef = useRef(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (isSubmittingRef.current) {
      return
    }
    isSubmittingRef.current = true
    setError(null)
    setIsSubmitting(true)

    try {
      await register({ email, plainPassword, firstName, lastName })
      navigate('/', { replace: true })
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setError('Cet email est déjà utilisé.')
      } else if (err instanceof ApiError && err.status === 422) {
        const violations = (err.body as { violations?: Record<string, string> } | null)?.violations
        setError(violations ? Object.values(violations)[0] : 'Certaines informations sont invalides.')
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
      <h1>Créer un compte</h1>
      <form onSubmit={handleSubmit}>
        <label>
          Prénom
          <input
            type="text"
            value={firstName}
            onChange={(event) => setFirstName(event.target.value)}
            autoComplete="given-name"
            required
          />
        </label>
        <label>
          Nom
          <input
            type="text"
            value={lastName}
            onChange={(event) => setLastName(event.target.value)}
            autoComplete="family-name"
            required
          />
        </label>
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
            value={plainPassword}
            onChange={(event) => setPlainPassword(event.target.value)}
            autoComplete="new-password"
            minLength={8}
            required
          />
        </label>
        {error && <p role="alert">{error}</p>}
        <button type="submit" disabled={isSubmitting}>
          Créer mon compte
        </button>
      </form>
      <p>
        Déjà un compte ? <Link to="/login">Se connecter</Link>
      </p>
    </section>
  )
}
