import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
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
    return <p>Chargement…</p>
  }

  if (loadError || !info) {
    return (
      <section>
        <h1>Invitation</h1>
        <p role="alert">{loadError ?? 'Impossible de charger cette invitation.'}</p>
        <p>
          <Link to="/login">Se connecter</Link>
        </p>
      </section>
    )
  }

  if (accepted) {
    return (
      <section>
        <h1>Invitation acceptée</h1>
        <p role="status">
          Vous avez rejoint {accepted.length > 1 ? 'les équipes' : "l'équipe"} :{' '}
          {accepted.map((team) => team.teamName).join(', ')}.
        </p>
        <p>
          <Link to={accepted.length === 1 ? `/plannings/${accepted[0].planningStableId}` : '/plannings'}>
            Accéder au planning
          </Link>
        </p>
      </section>
    )
  }

  if (info.accountExists) {
    const sameAccount = user?.email.toLowerCase() === info.email
    return (
      <section>
        <h1>Invitation à rejoindre {info.teamName}</h1>
        {!user && (
          <>
            <p>
              Un compte MedVue existe déjà avec l'adresse <strong>{info.email}</strong>. Connectez-vous pour
              rejoindre l'équipe <strong>{info.teamName}</strong>.
            </p>
            <Link to="/login" state={{ from: { pathname: `/invitations/${token}` } }}>
              Se connecter
            </Link>
          </>
        )}
        {user && !sameAccount && (
          <p role="alert">
            Cette invitation a été envoyée à <strong>{info.email}</strong>, mais vous êtes connecté avec un
            autre compte. Déconnectez-vous puis connectez-vous avec la bonne adresse.
          </p>
        )}
        {user && sameAccount && (
          <>
            <p>
              <strong>{info.inviterName}</strong> vous invite à rejoindre l'équipe{' '}
              <strong>{info.teamName}</strong>.
            </p>
            <button type="button" onClick={handleAccept} disabled={isAccepting}>
              Rejoindre l'équipe
            </button>
          </>
        )}
        {acceptError && <p role="alert">{acceptError}</p>}
      </section>
    )
  }

  return (
    <section>
      <h1>Créer votre compte MedVue</h1>
      <RegistrationForm invitation={{ token, info }} onRegistered={() => navigate('/', { replace: true })} />
    </section>
  )
}
