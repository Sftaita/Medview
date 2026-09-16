import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import {
  createNonParticipationPeriod,
  deleteNonParticipationPeriod,
  fetchNonParticipationPeriods,
  fetchTeamMembers,
} from '../features/teams/api'
import type { NonParticipationPeriod, TeamMemberSummary } from '../features/teams/types'
import { ApiError } from '../lib/apiClient'

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' })
}

function NonParticipationPanel({ teamId, member }: { teamId: string; member: TeamMemberSummary }) {
  const [periods, setPeriods] = useState<NonParticipationPeriod[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  function load() {
    setLoading(true)
    setError(null)
    fetchNonParticipationPeriods(teamId, member.stableId)
      .then(setPeriods)
      .catch((err: unknown) => {
        if (err instanceof ApiError && err.status === 403) {
          setError("Vous n'avez pas le droit de voir ces périodes.")
        } else {
          setError('Impossible de charger les périodes de non-participation.')
        }
      })
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [teamId, member.stableId])

  async function handleCreate() {
    if (!startsAt || !endsAt) {
      return
    }

    setSaving(true)
    setFormError(null)
    try {
      await createNonParticipationPeriod(teamId, member.stableId, {
        startsAt: new Date(startsAt).toISOString(),
        endsAt: new Date(endsAt).toISOString(),
      })
      setStartsAt('')
      setEndsAt('')
      load()
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) {
        setFormError('Seuls les OWNER/ADMIN de cette équipe peuvent créer une période de non-participation.')
      } else if (err instanceof ApiError && err.status === 409) {
        setFormError('Cette période chevauche ou touche une période existante.')
      } else if (err instanceof ApiError && err.status === 422) {
        setFormError('Cette période est invalide.')
      } else {
        setFormError('Une erreur est survenue. Merci de réessayer.')
      }
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(stableId: string) {
    setSaving(true)
    try {
      await deleteNonParticipationPeriod(teamId, member.stableId, stableId)
      load()
    } catch {
      setFormError('Impossible de supprimer cette période.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="non-participation-panel">
      <h3>
        Non-participation administrative — {member.firstName} {member.lastName}
      </h3>

      {loading && <p>Chargement…</p>}
      {error && (
        <p role="alert" className="availability-error">
          {error}
        </p>
      )}

      {!loading && !error && (
        <>
          {periods.length === 0 && <p>Aucune période de non-participation.</p>}
          <ul className="non-participation-list">
            {periods.map((period) => (
              <li key={period.stableId}>
                <span>
                  {formatDateTime(period.startsAt)} → {formatDateTime(period.endsAt)}
                </span>
                <button type="button" onClick={() => handleDelete(period.stableId)} disabled={saving}>
                  Supprimer
                </button>
              </li>
            ))}
          </ul>

          <div className="availability-time-range">
            <label>
              Début
              <input
                type="datetime-local"
                value={startsAt}
                onChange={(event) => setStartsAt(event.target.value)}
              />
            </label>
            <label>
              Fin
              <input
                type="datetime-local"
                value={endsAt}
                onChange={(event) => setEndsAt(event.target.value)}
              />
            </label>
          </div>

          {formError && (
            <p role="alert" className="availability-error">
              {formError}
            </p>
          )}

          <div className="availability-form-actions">
            <button type="button" onClick={handleCreate} disabled={saving || !startsAt || !endsAt}>
              Ajouter
            </button>
          </div>
        </>
      )}
    </div>
  )
}

export function TeamDetailPage() {
  const { teamId } = useParams<{ teamId: string }>()
  const [members, setMembers] = useState<TeamMemberSummary[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [selectedMember, setSelectedMember] = useState<TeamMemberSummary | null>(null)

  function loadMembers() {
    if (!teamId) {
      return
    }

    setLoading(true)
    setError(null)
    fetchTeamMembers(teamId)
      .then(setMembers)
      .catch((err: unknown) => {
        if (err instanceof ApiError && err.status === 404) {
          setError('Équipe introuvable.')
        } else if (err instanceof ApiError && err.status === 403) {
          setError("Vous n'êtes pas membre de cette équipe.")
        } else {
          setError('Impossible de charger les membres de cette équipe.')
        }
      })
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadMembers()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [teamId])

  if (!teamId) {
    return <p role="alert">Équipe introuvable.</p>
  }

  return (
    <section>
      <h1>Détail de l'équipe</h1>

      {loading && <p>Chargement…</p>}
      {error && (
        <p role="alert" className="availability-error">
          {error}
        </p>
      )}

      {!loading && !error && (
        <table className="team-members-table">
          <thead>
            <tr>
              <th>Prénom</th>
              <th>Nom</th>
              <th>Rôle</th>
              <th>Actif</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {members.map((member) => (
              <tr key={member.stableId}>
                <td>{member.firstName}</td>
                <td>{member.lastName}</td>
                <td>{member.role}</td>
                <td>{member.active ? 'Oui' : 'Non'}</td>
                <td>
                  <button type="button" onClick={() => setSelectedMember(member)}>
                    Non-participation
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {selectedMember && <NonParticipationPanel teamId={teamId} member={selectedMember} />}
    </section>
  )
}
