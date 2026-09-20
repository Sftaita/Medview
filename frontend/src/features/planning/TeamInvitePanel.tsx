import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../../lib/apiClient'
import { fetchTeamInvitations, inviteToTeam, revokeTeamInvitation } from './api'
import type { InviteResult, TeamInvitation } from './types'

type Props = {
  planningStableId: string
  teamStableId: string
  /** Called after an existing user was added, so the members list can refresh. */
  onMembersChanged: () => void
}

function fullName(person: { firstName: string; lastName: string }): string {
  return `${person.firstName} ${person.lastName}`.trim()
}

/** The business outcome, in plain French — one of the four states the API distinguishes. */
function describeInviteResult(result: InviteResult, email: string, name: string): string {
  const emailNote = result.emailSent ? '' : " L'email n'a pas pu être envoyé pour le moment."
  switch (result.status) {
    case 'USER_ADDED':
      return `${name} a déjà un compte MedVue : cette personne a été ajoutée à l'équipe.${result.emailSent ? " Un email l'en informe." : emailNote}`
    case 'INVITATION_CREATED':
      return result.emailSent
        ? `Invitation envoyée à ${email}.`
        : `L'invitation pour ${email} a été créée, mais l'email n'a pas pu être envoyé. Révoquez-la puis recommencez.`
    case 'ALREADY_MEMBER':
      return `${name} fait déjà partie de cette équipe.`
    case 'INVITATION_ALREADY_PENDING':
      return `Une invitation est déjà en attente pour ${email}.`
  }
}

/**
 * "Ajouter une personne" for one team, plus the pending invitations shown
 * as such — distinct from the member list, never as TeamMembers. Only
 * rendered for someone the API said may invite (creator, OWNER, ADMIN).
 */
export function TeamInvitePanel({ planningStableId, teamStableId, onMembersChanged }: Props) {
  const [invitations, setInvitations] = useState<TeamInvitation[]>([])
  const [showForm, setShowForm] = useState(false)
  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [email, setEmail] = useState('')
  const [feedback, setFeedback] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  // See LoginPage: a ref is the only guard that holds against a double click.
  const isSubmittingRef = useRef(false)

  const loadInvitations = useCallback(() => {
    fetchTeamInvitations(planningStableId, teamStableId)
      .then(setInvitations)
      .catch(() => setError('Impossible de charger les invitations en attente.'))
  }, [planningStableId, teamStableId])

  useEffect(() => {
    loadInvitations()
  }, [loadInvitations])

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (isSubmittingRef.current) {
      return
    }
    isSubmittingRef.current = true
    setIsSubmitting(true)
    setError(null)
    setFeedback(null)

    try {
      const result = await inviteToTeam(planningStableId, teamStableId, { email, firstName, lastName })
      setFeedback(
        describeInviteResult(
          result,
          result.invitation?.email ?? email.trim().toLowerCase(),
          fullName({ firstName, lastName }),
        ),
      )
      if (result.status === 'USER_ADDED') {
        onMembersChanged()
      }
      if (result.status === 'USER_ADDED' || result.status === 'INVITATION_CREATED') {
        setFirstName('')
        setLastName('')
        setEmail('')
      }
      loadInvitations()
    } catch (err) {
      setError(messageFor(err))
    } finally {
      isSubmittingRef.current = false
      setIsSubmitting(false)
    }
  }

  async function handleRevoke(invitation: TeamInvitation) {
    setError(null)
    setFeedback(null)
    try {
      await revokeTeamInvitation(planningStableId, teamStableId, invitation.stableId)
      setFeedback(`L'invitation pour ${invitation.email} a été révoquée.`)
      loadInvitations()
    } catch {
      setError('Impossible de révoquer cette invitation.')
    }
  }

  return (
    <div className="team-invite-panel">
      <h4>Invitations en attente</h4>
      <ul>
        {invitations.map((invitation) => (
          <li key={invitation.stableId}>
            {fullName(invitation)} ({invitation.email}) —{' '}
            {invitation.status === 'PENDING' ? 'Invitation en attente' : 'Invitation expirée'}
            <button type="button" onClick={() => handleRevoke(invitation)}>
              Révoquer
            </button>
          </li>
        ))}
        {invitations.length === 0 && <li>Aucune invitation en attente.</li>}
      </ul>

      {feedback && <p role="status">{feedback}</p>}
      {error && (
        <p role="alert" className="availability-error">
          {error}
        </p>
      )}

      {!showForm && (
        <button type="button" onClick={() => setShowForm(true)}>
          Ajouter une personne
        </button>
      )}
      {showForm && (
        <form onSubmit={handleSubmit} className="planning-team-member-form">
          <label>
            Prénom
            <input
              type="text"
              value={firstName}
              onChange={(e) => setFirstName(e.target.value)}
              maxLength={100}
              required
            />
          </label>
          <label>
            Nom
            <input
              type="text"
              value={lastName}
              onChange={(e) => setLastName(e.target.value)}
              maxLength={100}
              required
            />
          </label>
          <label>
            Email
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              maxLength={180}
              required
            />
          </label>
          <button type="submit" disabled={isSubmitting}>
            Ajouter
          </button>
          <button type="button" onClick={() => setShowForm(false)} disabled={isSubmitting}>
            Fermer
          </button>
        </form>
      )}
    </div>
  )
}

function messageFor(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 409) {
      return "Cette personne fait déjà partie d'une autre équipe de ce planning."
    }
    if (err.status === 403) {
      return "Vous n'avez pas le droit d'ajouter des personnes à cette équipe."
    }
    if (err.status === 422) {
      return 'Vérifiez les informations saisies (adresse email valide, prénom et nom renseignés).'
    }
    if (err.status === 429) {
      return "Trop d'invitations envoyées récemment. Merci de réessayer plus tard."
    }
  }
  return "Impossible d'ajouter cette personne."
}
