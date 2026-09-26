import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { PasswordField } from '../components/Field'
import { Icon } from '../components/Icon'
import { confirmPasswordReset } from '../features/auth/api'
import { ApiError, clearStoredToken, UNAUTHORIZED_EVENT } from '../lib/apiClient'

const INVALID_OR_EXPIRED_MESSAGE = 'Ce lien est invalide ou a expiré.'

/**
 * Reads the raw reset token from the URL *fragment* (#token=...), never a
 * query string — the fragment is never sent to the server on the initial
 * page load, keeping the secret out of access logs, a reverse proxy,
 * analytics or a Referer header (docs/authentication.md §16). Deliberately
 * NOT wrapped in PublicOnlyRoute (see App.tsx): the person opening this
 * link may still have an old session in this browser, and the link must
 * keep working regardless — the reset itself ends that session below.
 */
function readTokenFromFragment(): string | null {
  const hash = window.location.hash
  const match = /token=([^&]+)/.exec(hash)
  if (!match) {
    return null
  }
  try {
    return decodeURIComponent(match[1])
  } catch {
    return null
  }
}

export function ResetPasswordPage() {
  // Read synchronously during the initial render: window.location is an
  // external value already available then, not something that needs an
  // effect just to be read once.
  const [token] = useState<string | null>(() => readTokenFromFragment())
  const [newPassword, setNewPassword] = useState('')
  const [confirmNewPassword, setConfirmNewPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isDone, setIsDone] = useState(false)
  const isSubmittingRef = useRef(false)

  useEffect(() => {
    // The secret must not linger in the URL/browser history once read —
    // this is the one genuine side effect here (mutating browser history),
    // so it alone belongs in an effect.
    if (window.location.hash) {
      history.replaceState(null, '', window.location.pathname + window.location.search)
    }
  }, [])

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (isSubmittingRef.current || !token) {
      return
    }
    if (newPassword !== confirmNewPassword) {
      setError('Les deux mots de passe ne correspondent pas.')
      return
    }
    isSubmittingRef.current = true
    setError(null)
    setIsSubmitting(true)

    try {
      await confirmPasswordReset(token, newPassword)
      // The reset just invalidated every session server-side; mirror that
      // in this browser (and notify any other open tab, via the same
      // `storage` mechanism AuthProvider already listens to for logout —
      // see docs/authentication.md §8/§18). Never auto-login afterwards.
      clearStoredToken()
      window.dispatchEvent(new Event(UNAUTHORIZED_EVENT))
      setIsDone(true)
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const violations = (err.body as { violations?: Record<string, string> } | null)?.violations
        setError(violations?.newPassword ?? 'Merci de vérifier le mot de passe saisi.')
      } else {
        // Unknown/expired/consumed/revoked token, or account no longer
        // eligible: always the same generic message, never enough detail
        // to distinguish which case it is.
        setError(INVALID_OR_EXPIRED_MESSAGE)
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
          <h1 className="auth__title">Mot de passe modifié</h1>
          <p className="auth__intro">
            Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.
          </p>
        </div>
        <Link to="/login" className="btn btn--primary btn--lg btn--full">
          Se connecter
        </Link>
      </section>
    )
  }

  if (!token) {
    return (
      <section className="auth__stack">
        <div>
          <h1 className="auth__title">Lien invalide</h1>
          <p className="auth__intro">{INVALID_OR_EXPIRED_MESSAGE}</p>
        </div>
        <Link to="/forgot-password" className="btn btn--secondary btn--full">
          Demander un nouveau lien
        </Link>
      </section>
    )
  }

  return (
    <section className="auth__stack">
      <div>
        <h1 className="auth__title">Choisir un nouveau mot de passe</h1>
        <p className="auth__intro">Votre nouveau mot de passe doit contenir au moins 8 caractères.</p>
      </div>
      <form onSubmit={handleSubmit} className="form">
        <PasswordField
          label="Nouveau mot de passe"
          value={newPassword}
          onChange={(event) => setNewPassword(event.target.value)}
          autoComplete="new-password"
          minLength={8}
          required
        />
        <PasswordField
          label="Confirmer le mot de passe"
          value={confirmNewPassword}
          onChange={(event) => setConfirmNewPassword(event.target.value)}
          autoComplete="new-password"
          minLength={8}
          required
        />
        {error && (
          <p role="alert" className="alert alert--error">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>{error}</span>
          </p>
        )}
        <button type="submit" className="btn btn--primary btn--lg btn--full" disabled={isSubmitting}>
          Modifier mon mot de passe
        </button>
      </form>
    </section>
  )
}
