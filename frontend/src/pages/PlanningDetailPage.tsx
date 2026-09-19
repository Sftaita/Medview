import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import {
  addTeamMember,
  createPlanningLine,
  deletePlanningLine,
  endTeamMembership,
  fetchPlanning,
  fetchTeamMembers,
  renamePlanning,
} from '../features/planning/api'
import type { PlanningDetail, PlanningTeamMember } from '../features/planning/types'
import { ApiError } from '../lib/apiClient'

export function PlanningDetailPage() {
  const { planningId } = useParams<{ planningId: string }>()
  const [planning, setPlanning] = useState<PlanningDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [renaming, setRenaming] = useState(false)
  const [newName, setNewName] = useState('')

  const [showLineForm, setShowLineForm] = useState(false)
  const [lineName, setLineName] = useState('')

  const [expandedTeamStableId, setExpandedTeamStableId] = useState<string | null>(null)
  const [members, setMembers] = useState<PlanningTeamMember[]>([])
  const [membersLoading, setMembersLoading] = useState(false)
  const [memberUserStableId, setMemberUserStableId] = useState('')
  const [memberRole, setMemberRole] = useState<'OWNER' | 'ADMIN' | 'MEMBER'>('MEMBER')
  const [memberStart, setMemberStart] = useState('')
  const [memberError, setMemberError] = useState<string | null>(null)

  const [actionError, setActionError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  function load() {
    if (!planningId) {
      return
    }

    setLoading(true)
    setError(null)
    fetchPlanning(planningId)
      .then(setPlanning)
      .catch((err: unknown) => {
        if (err instanceof ApiError && err.status === 404) {
          setError('Planning introuvable.')
        } else if (err instanceof ApiError && err.status === 403) {
          setError("Vous n'avez pas accès à ce planning.")
        } else {
          setError('Impossible de charger ce planning.')
        }
      })
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [planningId])

  async function handleRename() {
    if (!planningId || !newName) {
      return
    }

    setSaving(true)
    setActionError(null)
    try {
      await renamePlanning(planningId, newName)
      setRenaming(false)
      setNewName('')
      load()
    } catch {
      setActionError('Impossible de renommer ce planning.')
    } finally {
      setSaving(false)
    }
  }

  async function handleAddLine() {
    if (!planningId || !lineName) {
      return
    }

    setSaving(true)
    setActionError(null)
    try {
      await createPlanningLine(planningId, { name: lineName })
      setLineName('')
      setShowLineForm(false)
      load()
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setActionError('Cette équipe a déjà un planning sur cette période.')
      } else {
        setActionError("Impossible d'ajouter cette ligne.")
      }
    } finally {
      setSaving(false)
    }
  }

  async function handleDeleteLine(lineStableId: string) {
    if (!planningId) {
      return
    }

    setSaving(true)
    setActionError(null)
    try {
      await deletePlanningLine(planningId, lineStableId)
      load()
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setActionError('La ligne principale ne peut pas être supprimée.')
      } else {
        setActionError('Impossible de supprimer cette ligne.')
      }
    } finally {
      setSaving(false)
    }
  }

  function loadMembers(teamStableId: string) {
    if (!planningId) {
      return
    }

    setMembersLoading(true)
    setMemberError(null)
    fetchTeamMembers(planningId, teamStableId)
      .then(setMembers)
      .catch(() => setMemberError('Impossible de charger les membres.'))
      .finally(() => setMembersLoading(false))
  }

  function toggleTeam(teamStableId: string) {
    if (expandedTeamStableId === teamStableId) {
      setExpandedTeamStableId(null)
      setMembers([])
      return
    }

    setExpandedTeamStableId(teamStableId)
    setMemberUserStableId('')
    setMemberStart('')
    setMemberRole('MEMBER')
    loadMembers(teamStableId)
  }

  async function handleAddMember(teamStableId: string) {
    if (!planningId || !memberUserStableId || !memberStart) {
      return
    }

    setSaving(true)
    setMemberError(null)
    try {
      await addTeamMember(planningId, teamStableId, {
        userStableId: memberUserStableId,
        role: memberRole,
        membershipStart: memberStart,
      })
      setMemberUserStableId('')
      setMemberStart('')
      setMemberRole('MEMBER')
      loadMembers(teamStableId)
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        setMemberError('Utilisateur introuvable — vérifiez son identifiant.')
      } else if (err instanceof ApiError && err.status === 409) {
        setMemberError('Cet utilisateur a déjà une adhésion ouverte dans ce planning.')
      } else {
        setMemberError("Impossible d'ajouter ce membre.")
      }
    } finally {
      setSaving(false)
    }
  }

  async function handleEndMembership(teamStableId: string, memberStableId: string) {
    if (!planningId) {
      return
    }

    setSaving(true)
    setMemberError(null)
    try {
      await endTeamMembership(planningId, teamStableId, memberStableId)
      loadMembers(teamStableId)
    } catch {
      setMemberError('Impossible de mettre fin à cette adhésion.')
    } finally {
      setSaving(false)
    }
  }

  if (!planningId) {
    return <p role="alert">Planning introuvable.</p>
  }

  return (
    <section>
      {loading && <p>Chargement…</p>}
      {error && (
        <p role="alert" className="availability-error">
          {error}
        </p>
      )}

      {!loading && !error && planning && (
        <>
          <h1>{planning.name}</h1>
          <p>
            {planning.startsAt} → {planning.endsAt} ({planning.timezone})
          </p>

          {planning.canManage && (
            <div className="planning-actions">
              {!renaming && (
                <button type="button" onClick={() => setRenaming(true)}>
                  Modifier le nom
                </button>
              )}
              {renaming && (
                <div className="planning-rename-form">
                  <input
                    type="text"
                    value={newName}
                    placeholder={planning.name}
                    onChange={(event) => setNewName(event.target.value)}
                  />
                  <button type="button" onClick={handleRename} disabled={saving || !newName}>
                    Enregistrer
                  </button>
                  <button type="button" onClick={() => setRenaming(false)} disabled={saving}>
                    Annuler
                  </button>
                </div>
              )}
            </div>
          )}

          <h2>Lignes de garde</h2>
          <table className="planning-lines-table">
            <thead>
              <tr>
                <th>Nom</th>
                <th>Équipe</th>
                <th>Membres</th>
                <th>Rôle</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {planning.lines.map((line) => (
                <tr key={line.stableId}>
                  <td>{line.name}</td>
                  <td>{line.team.name}</td>
                  <td>{line.memberCount ?? '—'}</td>
                  <td>{line.type === 'PRIMARY' ? 'Principale' : 'Secondaire'}</td>
                  <td>
                    <button type="button" onClick={() => toggleTeam(line.team.stableId)}>
                      {expandedTeamStableId === line.team.stableId
                        ? 'Masquer les membres'
                        : 'Gérer les membres'}
                    </button>
                    {planning.canManage && line.type === 'SECONDARY' && (
                      <button type="button" onClick={() => handleDeleteLine(line.stableId)} disabled={saving}>
                        Supprimer
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {expandedTeamStableId && (
            <div className="planning-team-members">
              <h3>Membres de l'équipe</h3>
              {membersLoading && <p>Chargement des membres…</p>}
              {!membersLoading && (
                <ul>
                  {members.map((member) => (
                    <li key={member.stableId}>
                      {member.firstName} {member.lastName} — {member.role}
                      {member.membershipEnd ? ` (terminée le ${member.membershipEnd})` : ''}
                      {planning.canManage && !member.membershipEnd && (
                        <button
                          type="button"
                          onClick={() => handleEndMembership(expandedTeamStableId, member.stableId)}
                          disabled={saving}
                        >
                          Terminer l'adhésion
                        </button>
                      )}
                    </li>
                  ))}
                  {members.length === 0 && <li>Aucun membre pour le moment.</li>}
                </ul>
              )}

              {memberError && (
                <p role="alert" className="availability-error">
                  {memberError}
                </p>
              )}

              {planning.canManage && (
                <div className="planning-team-member-form">
                  <label>
                    Identifiant de l'utilisateur
                    <input
                      type="text"
                      value={memberUserStableId}
                      onChange={(event) => setMemberUserStableId(event.target.value)}
                      placeholder="stableId de l'utilisateur"
                    />
                  </label>
                  <label>
                    Rôle
                    <select
                      value={memberRole}
                      onChange={(event) => setMemberRole(event.target.value as 'OWNER' | 'ADMIN' | 'MEMBER')}
                    >
                      <option value="MEMBER">Membre</option>
                      <option value="ADMIN">Admin</option>
                      <option value="OWNER">Owner</option>
                    </select>
                  </label>
                  <label>
                    Date d'entrée
                    <input
                      type="date"
                      value={memberStart}
                      onChange={(event) => setMemberStart(event.target.value)}
                    />
                  </label>
                  <button
                    type="button"
                    onClick={() => handleAddMember(expandedTeamStableId)}
                    disabled={saving || !memberUserStableId || !memberStart}
                  >
                    Ajouter
                  </button>
                </div>
              )}
            </div>
          )}

          {actionError && (
            <p role="alert" className="availability-error">
              {actionError}
            </p>
          )}

          {planning.canManage && !showLineForm && (
            <button type="button" onClick={() => setShowLineForm(true)}>
              Ajouter une ligne
            </button>
          )}

          {planning.canManage && showLineForm && (
            <div className="planning-line-form">
              <label>
                Nom de la ligne
                <input type="text" value={lineName} onChange={(event) => setLineName(event.target.value)} />
              </label>
              <div className="availability-form-actions">
                <button type="button" onClick={handleAddLine} disabled={saving || !lineName}>
                  Ajouter
                </button>
                <button type="button" onClick={() => setShowLineForm(false)} disabled={saving}>
                  Annuler
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </section>
  )
}
