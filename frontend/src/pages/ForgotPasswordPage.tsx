import { useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { requestPasswordReset } from '../features/auth/api'
import { ApiError } from '../lib/apiClient'
import { formatWaitTime } from '../lib/formatWaitTime'

const GENERIC_MESSAGE = 'Si un compte correspond à cette adresse, un email de réinitialisation a été envoyé.'

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isDone, setIsDone] = useState(false)
  // Same synchronous double-submit guard as LoginPage/RegisterPage.
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
      await requestPasswordReset(email)
      // Always the generic outcome, whatever the backend actually did
      // (unknown email, disabled account, real send…) — never branch the
      // UI on account existence, see docs/authentication.md §16.
      setIsDone(true)
    } catch (err) {
      if (err instanceof ApiError && err.status === 429) {
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

  if (isDone) {
    return (
      <section className="auth__stack">
        <div>
          <h1 className="auth__title">Vérifiez votre boîte mail</h1>
          <p className="auth__intro">{GENERIC_MESSAGE}</p>
        </div>
        <Link to="/login" className="btn btn--secondary btn--full">
          Retour à la connexion
        </Link>
      </section>
    )
  }

  return (
    <section className="auth__stack">
      <div>
        <h1 className="auth__title">Mot de passe oublié</h1>
        <p className="auth__intro">
          Indiquez votre adresse email : si un compte y correspond, nous vous envoyons un lien pour choisir un
          nouveau mot de passe.
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
        {error && (
          <p role="alert" className="alert alert--error">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>{error}</span>
          </p>
        )}
        <button type="submit" className="btn btn--primary btn--lg btn--full" disabled={isSubmitting}>
          Envoyer le lien de réinitialisation
        </button>
      </form>
      <Link to="/login" className="auth__alt">
        Retour à la connexion
      </Link>
    </section>
  )
}
