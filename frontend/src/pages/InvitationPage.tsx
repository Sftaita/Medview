import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Icon, type IconName } from '../components/Icon'
import { RegistrationForm } from '../features/auth/RegistrationForm'
import type { JoinedTeam } from '../features/auth/types'
import { useAuth } from '../features/auth/useAuth'
import {
  acceptInvitation,
  describeInvitationError,
  fetchInvitation,
  type InvitationInfo,
} from '../features/invitations/api'
import { ApiError } from '../lib/apiClient'

/**
 * Landing page of the link in an invitation email. Public: the invitee
 * usually has no account yet. Three situations:
 *  - no account for the invitation's address → the sign-up form, email
 *    locked, names prefilled but editable;
 *  - an account already exists (created after the invitation was sent) →
 *    log in, then accept with one click (never a second account);
 *  - the link is unusable (unknown / expired / revoked / already used).
 */
export function InvitationPage() {
  const { token } = useParams<{ token: string }>()
  const { user, isLoading: authLoading } = useAuth()
  const navigate = useNavigate()

  const [info, setInfo] = useState<InvitationInfo | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [accepted, setAccepted] = useState<JoinedTeam[] | null>(null)
  const [acceptError, setAcceptError] = useState<string | null>(null)
  const [isAccepting, setIsAccepting] = useState(false)
  const isAcceptingRef = useRef(false)

  useEffect(() => {
    if (!token) {
      return
    }
    let cancelled = false
    fetchInvitation(token)
      .then((result) => {
        if (!cancelled) setInfo(result)
      })
      .catch((err: unknown) => {
        if (cancelled) return
        setLoadError(
          err instanceof ApiError
            ? describeInvitationError(err.status, err.body)
            : 'Impossible de charger cette invitation. Merci de réessayer.',
        )
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [token])

  async function handleAccept() {
    if (!token || isAcceptingRef.current) {
      return
    }
    isAcceptingRef.current = true
    setIsAccepting(true)
    setAcceptError(null)
    try {
      const result = await acceptInvitation(token)
      setAccepted(result.joinedTeams)
    } catch (err) {
      setAcceptError(
        err instanceof ApiError && err.status === 403
          ? 'Cette invitation a été envoyée à une autre adresse email que celle de votre compte.'
          : err instanceof ApiError
            ? describeInvitationError(err.status, err.body)
            : 'Une erreur est survenue. Merci de réessayer.',
      )
    } finally {
      isAcceptingRef.current = false
      setIsAccepting(false)
    }
  }

  if (!token) {
    return <p role="alert">Invitation introuvable.</p>
  }

  if (loading || authLoading) {
    return (
      <p role="status" className="app-loading">
        Chargement…
      </p>
    )
  }

  if (loadError || !info) {
    return (
      <section className="auth__stack">
        <StatusIcon name="alert" tone="red" />
        <div>
          <h1 className="auth__title">Invitation</h1>
          <p role="alert" className="auth__intro">
            {loadError ?? 'Impossible de charger cette invitation.'}
          </p>
        </div>
        <Link to="/login" className="btn btn--secondary btn--full">
          Se connecter
        </Link>
      </section>
    )
  }

  if (accepted) {
    return (
      <section className="auth__stack">
        <StatusIcon name="check" tone="green" />
        <div>
          <h1 className="auth__title">Invitation acceptée</h1>
          <p role="status" className="auth__intro">
            Vous avez rejoint {accepted.length > 1 ? 'les équipes' : "l'équipe"} :{' '}
            {accepted.map((team) => team.teamName).join(', ')}.
          </p>
        </div>
        <Link
          to={accepted.length === 1 ? `/plannings/${accepted[0].planningStableId}` : '/plannings'}
          className="btn btn--primary btn--lg btn--full"
        >
          Accéder au planning
          <Icon name="arrow" size={18} strokeWidth={2} />
        </Link>
      </section>
    )
  }

  if (info.accountExists) {
    const sameAccount = user?.email.toLowerCase() === info.email
    return (
      <section className="auth__stack">
        <StatusIcon name="users" tone={user && sameAccount ? 'green' : 'blue'} />
        <h1 className="auth__title">Invitation à rejoindre {info.teamName}</h1>
        {!user && (
          <>
            <p className="auth__intro">
              Un compte MedVue existe déjà avec l'adresse <strong>{info.email}</strong>. Connectez-vous pour
              rejoindre l'équipe <strong>{info.teamName}</strong>.
            </p>
            <Link
              to="/login"
              state={{ from: { pathname: `/invitations/${token}` } }}
              className="btn btn--primary btn--lg btn--full"
            >
              Se connecter
            </Link>
          </>
        )}
        {user && !sameAccount && (
          <p role="alert" className="alert alert--error">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>
              Cette invitation a été envoyée à <strong>{info.email}</strong>, mais vous êtes connecté avec un
              autre compte. Déconnectez-vous puis connectez-vous avec la bonne adresse.
            </span>
          </p>
        )}
        {user && sameAccount && (
          <>
            <p className="auth__intro">
              <strong>{info.inviterName}</strong> vous invite à rejoindre l'équipe{' '}
              <strong>{info.teamName}</strong>.
            </p>
            <button
              type="button"
              className="btn btn--primary btn--lg btn--full"
              onClick={handleAccept}
              disabled={isAccepting}
            >
              Rejoindre l'équipe
            </button>
          </>
        )}
        {acceptError && (
          <p role="alert" className="alert alert--error">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>{acceptError}</span>
          </p>
        )}
      </section>
    )
  }

  return (
    <section className="auth__stack">
      <h1 className="auth__title">Créer votre compte MedVue</h1>
      <RegistrationForm invitation={{ token, info }} onRegistered={() => navigate('/', { replace: true })} />
      <p className="auth__alt">
        Déjà un compte ? <Link to="/login">Se connecter</Link>
      </p>
    </section>
  )
}

function StatusIcon({ name, tone }: { name: IconName; tone: 'blue' | 'green' | 'red' }) {
  return (
    <span className={`auth__icon${tone === 'blue' ? '' : ` auth__icon--${tone}`}`}>
      <Icon name={name} size={30} strokeWidth={1.9} />
    </span>
  )
}
