import { useEffect, useState } from 'react'
import { Field } from '../../../components/Field'
import { Icon } from '../../../components/Icon'
import { ApiError } from '../../../lib/apiClient'
import { addTeamMember, endTeamMembership, fetchTeamMembers } from '../api'
import { TeamInvitePanel } from '../TeamInvitePanel'
import type { PlanningLineSummary, PlanningTeamMember } from '../types'
import { Sheet } from './Sheet'

type Role = 'OWNER' | 'ADMIN' | 'MEMBER'

type Props = {
  planningStableId: string
  line: PlanningLineSummary
  /** May end memberships and add an existing user (creator of the planning). */
  canManage: boolean
  onClose: () => void
  /** Membership added, ended or invitation accepted: the page refreshes its counts and the follow-up. */
  onChanged: () => void
}

const ROLE_TAG: Record<Role, { label: string; tone: string }> = {
  OWNER: { label: 'Propriétaire', tone: 'tag--green' },
  ADMIN: { label: 'Admin', tone: 'tag--blue' },
  MEMBER: { label: 'Membre', tone: '' },
}

/** "Membres" of a line: who is in its team, invitations, and (creator) memberships. */
export function MembersSheet({ planningStableId, line, canManage, onClose, onChanged }: Props) {
  const teamStableId = line.team.stableId
  const [members, setMembers] = useState<PlanningTeamMember[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [userStableId, setUserStableId] = useState('')
  const [role, setRole] = useState<Role>('MEMBER')
  const [start, setStart] = useState('')

  function load() {
    setError(null)
    return fetchTeamMembers(planningStableId, teamStableId)
      .then(setMembers)
      .catch(() => setError('Impossible de charger les membres.'))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [planningStableId, teamStableId])

  function changed() {
    void load()
    onChanged()
  }

  async function handleEnd(memberStableId: string) {
    setSaving(true)
    setError(null)
    try {
      await endTeamMembership(planningStableId, teamStableId, memberStableId)
      changed()
    } catch {
      setError('Impossible de mettre fin à cette adhésion.')
    } finally {
      setSaving(false)
    }
  }

  async function handleAdd() {
    if (!userStableId || !start) return
    setSaving(true)
    setError(null)
    try {
      await addTeamMember(planningStableId, teamStableId, { userStableId, role, membershipStart: start })
      setUserStableId('')
      setStart('')
      setRole('MEMBER')
      changed()
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        setError('Utilisateur introuvable — vérifiez son identifiant.')
      } else if (err instanceof ApiError && err.status === 409) {
        setError('Cet utilisateur a déjà une adhésion ouverte dans ce planning.')
      } else {
        setError("Impossible d'ajouter ce membre.")
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Sheet title={`Membres · ${line.name}`} onClose={onClose} wide>
      <p className="pd-help">Équipe {line.team.name}.</p>

      {loading ? (
        <p className="muted" role="status">
          Chargement des membres…
        </p>
      ) : (
        <ul className="pd-card pd-list" aria-label="Membres de l’équipe">
          {members.map((member) => (
            <li key={member.stableId} className="pd-line">
              <span className="pd-avatar" aria-hidden>
                {`${member.firstName.charAt(0)}${member.lastName.charAt(0)}`.toUpperCase()}
              </span>
              <div className="pd-line-main">
                <strong>
                  {member.firstName} {member.lastName}
                </strong>
                <span className="pd-muted">
                  <span className={`tag ${ROLE_TAG[member.role].tone}`}>{ROLE_TAG[member.role].label}</span>
                  {member.membershipEnd ? ` Adhésion terminée le ${member.membershipEnd}` : ''}
                </span>
              </div>
              {canManage && !member.membershipEnd && (
                <button
                  type="button"
                  className="pd-btn pd-btn-ghost"
                  onClick={() => handleEnd(member.stableId)}
                  disabled={saving}
                >
                  Terminer l'adhésion
                </button>
              )}
            </li>
          ))}
          {members.length === 0 && <li className="pd-empty-row">Aucun membre pour le moment.</li>}
        </ul>
      )}

      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}

      {line.team.canInvite && (
        <TeamInvitePanel
          planningStableId={planningStableId}
          teamStableId={teamStableId}
          onMembersChanged={changed}
        />
      )}

      {canManage && (
        <div className="planning-members__existing form">
          <p className="muted">
            Utilisateur existant (identifiant connu) — permet aussi de choisir le rôle et la date d'entrée.
          </p>
          <Field
            label="Identifiant de l'utilisateur"
            type="text"
            value={userStableId}
            onChange={(event) => setUserStableId(event.target.value)}
            placeholder="stableId de l'utilisateur"
          />
          <div className="field">
            <label htmlFor="member-role" className="field__label">
              Rôle
            </label>
            <select
              id="member-role"
              className="field__input"
              value={role}
              onChange={(event) => setRole(event.target.value as Role)}
            >
              <option value="MEMBER">Membre</option>
              <option value="ADMIN">Admin</option>
              <option value="OWNER">Propriétaire</option>
            </select>
          </div>
          <Field
            label="Date d'entrée"
            type="date"
            value={start}
            onChange={(event) => setStart(event.target.value)}
          />
          <button
            type="button"
            className="btn btn--primary"
            onClick={handleAdd}
            disabled={saving || !userStableId || !start}
          >
            Ajouter
          </button>
        </div>
      )}
    </Sheet>
  )
}
