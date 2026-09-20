import { useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '../../lib/apiClient'
import { formatWaitTime } from '../../lib/formatWaitTime'
import { describeInvitationError, invitationErrorCode, type InvitationInfo } from '../invitations/api'
import type { JoinedTeam } from './types'
import { useAuth } from './useAuth'

type Props = {
  /** Present when the form is opened from an invitation link. */
  invitation?: { token: string; info: InvitationInfo }
  onRegistered: (joinedTeams: JoinedTeam[]) => void
}

const VIOLATION_MESSAGES: Record<string, string> = {
  phone: 'Numéro de téléphone invalide. Utilisez le format international, par exemple +32 470 12 34 56.',
  email: "L'adresse email est invalide ou ne correspond pas à l'invitation.",
}

/**
 * The sign-up form, shared by /register (classic) and the invitation page.
 * With an invitation the email is fixed (locked) and first/last name are
 * only suggestions the person can correct; the team needs no input at all
 * — the backend attaches it when the account is created.
 */
export function RegistrationForm({ invitation, onRegistered }: Props) {
  const { register } = useAuth()

  const [firstName, setFirstName] = useState(invitation?.info.proposedFirstName ?? '')
  const [lastName, setLastName] = useState(invitation?.info.proposedLastName ?? '')
  const [email, setEmail] = useState(invitation?.info.email ?? '')
  const [phone, setPhone] = useState('')
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
      const joinedTeams = await register({
        email,
        plainPassword,
        firstName,
        lastName,
        phone,
        ...(invitation ? { invitationToken: invitation.token } : {}),
      })
      onRegistered(joinedTeams)
    } catch (err) {
      setError(messageFor(err))
    } finally {
      isSubmittingRef.current = false
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      {invitation && (
        <p role="note" className="registration-invitation-note">
          <strong>{invitation.info.inviterName}</strong> vous invite à rejoindre l'équipe{' '}
          <strong>{invitation.info.teamName}</strong>. Vous serez ajouté à cette équipe automatiquement une
          fois votre compte créé.
        </p>
      )}
      {invitation && (
        <p role="note" className="registration-invitation-note">
          Votre prénom et votre nom ont été renseignés par la personne qui vous a invité. Vérifiez-les avant
          de continuer.
        </p>
      )}
      <label>
        Prénom
        <input
          type="text"
          value={firstName}
          onChange={(event) => setFirstName(event.target.value)}
          autoComplete="given-name"
          maxLength={100}
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
          maxLength={100}
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
          readOnly={Boolean(invitation)}
          aria-readonly={Boolean(invitation)}
          required
        />
      </label>
      {invitation && (
        <small>
          Cette adresse est celle à laquelle l'invitation a été envoyée ; elle ne peut pas être modifiée.
        </small>
      )}
      <label>
        Téléphone
        <input
          type="tel"
          value={phone}
          onChange={(event) => setPhone(event.target.value)}
          autoComplete="tel"
          placeholder="+32 470 12 34 56"
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
      {error && (
        <p role="alert">
          {error}
          {error === ACCOUNT_EXISTS_MESSAGE && invitation && (
            <>
              {' '}
              <Link to="/login" state={{ from: { pathname: `/invitations/${invitation.token}` } }}>
                Se connecter
              </Link>
            </>
          )}
        </p>
      )}
      {/* Registration is followed by a login and a profile fetch, all under
          this one submit: the label keeps saying so until the redirect, so a
          disabled-but-unchanged button never looks like a lost click. */}
      <button type="submit" disabled={isSubmitting} aria-busy={isSubmitting}>
        {isSubmitting ? 'Création du compte…' : 'Créer mon compte'}
      </button>
    </form>
  )
}

const ACCOUNT_EXISTS_MESSAGE =
  "Un compte existe déjà avec cette adresse. Connectez-vous pour rejoindre l'équipe."

function messageFor(err: unknown): string {
  if (!(err instanceof ApiError)) {
    return 'Une erreur est survenue. Merci de réessayer.'
  }

  const code = invitationErrorCode(err.body)

  if (err.status === 409 && code === 'account_exists_for_invitation') {
    return ACCOUNT_EXISTS_MESSAGE
  }
  if (err.status === 409) {
    return 'Cet email est déjà utilisé.'
  }
  if (err.status === 404 || err.status === 410) {
    return describeInvitationError(err.status, err.body)
  }
  if (err.status === 422) {
    const violations = (err.body as { violations?: Record<string, string> } | null)?.violations
    if (!violations) {
      return 'Certaines informations sont invalides.'
    }
    const [field, message] = Object.entries(violations)[0]
    return VIOLATION_MESSAGES[field] ?? message
  }
  if (err.status === 429) {
    return err.retryAfterSeconds !== null
      ? `Trop de tentatives. Merci de réessayer dans ${formatWaitTime(err.retryAfterSeconds)}.`
      : 'Trop de tentatives. Merci de réessayer plus tard.'
  }

  return 'Une erreur est survenue. Merci de réessayer.'
}
