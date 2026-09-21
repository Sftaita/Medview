import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import {
  addTeamMember,
  createPlanningLine,
  deletePlanningLine,
  endTeamMembership,
  fetchPlanning,
  fetchTeamMembers,
  renamePlanning,
} from '../features/planning/api'
import { useAuth } from '../features/auth/useAuth'
import { AvailabilityCollectionsPanel } from '../features/planning/AvailabilityCollectionsPanel'
import { ExtendPlanningForm } from '../features/planning/ExtendPlanningForm'
import { PersonalPlanningView } from '../features/planning/PersonalPlanningView'
import { TeamInvitePanel } from '../features/planning/TeamInvitePanel'
import type { PlanningDetail, PlanningTeamMember } from '../features/planning/types'
import { ApiError } from '../lib/apiClient'

export function PlanningDetailPage() {
  const { planningId } = useParams<{ planningId: string }>()
  const { user } = useAuth()
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
  // Bumped after an extension so the availability follow-up shows the collection it just opened.
  const [collectionsReload, setCollectionsReload] = useState(0)
  const [saving, setSaving] = useState(false)

  function load() {
    if (!planningId) {
      return
    }

    // No spinner on a reload: the page stays as it is (and keeps its notices) while the data refreshes.
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
    setLoading(true)
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

  /**
   * "M'inclure dans ce planning" for a creator who was not a participant: an
   * ordinary membership of the primary line, like anyone else's (docs/decisions.md D123).
   */
  async function handleIncludeMe() {
    const primary = planning?.lines.find((line) => line.type === 'PRIMARY')
    if (!planningId || !planning || !primary || !user) {
      return
    }

    setSaving(true)
    setActionError(null)
    try {
      const today = new Date().toISOString().slice(0, 10)
      await addTeamMember(planningId, primary.team.stableId, {
        userStableId: user.stableId,
        role: 'OWNER',
        // From the start of the planning, so the whole range is covered.
        membershipStart: today < planning.startsAt ? today : planning.startsAt,
      })
      load()
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setActionError('Vous participez déjà à ce planning.')
      } else {
        setActionError('Impossible de vous inclure dans ce planning.')
      }
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
    <section className="page">
      <nav aria-label="Fil d'Ariane" className="breadcrumb">
        <Link to="/plannings">Plannings</Link>
        <Icon name="right" size={14} />
        <span aria-current="page">{planning?.name ?? '…'}</span>
      </nav>

      {loading && (
        <p role="status" className="muted">
          Chargement…
        </p>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}

      {!loading && !error && planning && (
        <>
          <header className="page__header">
            <div>
              <h1>{planning.name}</h1>
              <p className="page__lead tnum">
                {planning.startsAt} → {planning.endsAt} ({planning.timezone})
              </p>
              <p className="page__participation">
                {planning.participating ? (
                  <span className="tag tag--green">Vous participez à ce planning</span>
                ) : (
                  <span className="muted">Vous ne figurez pas parmi les candidats de ce planning.</span>
                )}
                {planning.canManage && !planning.participating && (
                  <button
                    type="button"
                    className="btn btn--secondary btn--sm"
                    onClick={handleIncludeMe}
                    disabled={saving}
                  >
                    M&apos;inclure dans ce planning
                  </button>
                )}
              </p>
            </div>
            {planning.canManage && !renaming && (
              <button type="button" className="btn btn--secondary" onClick={() => setRenaming(true)}>
                <Icon name="pencil" size={18} strokeWidth={2} />
                Modifier le nom
              </button>
            )}
          </header>

          {planning.canManage && renaming && (
            <div className="card form planning-rename">
              <Field
                label="Nouveau nom"
                type="text"
                value={newName}
                placeholder={planning.name}
                onChange={(event) => setNewName(event.target.value)}
              />
              <div className="form-actions">
                <button
                  type="button"
                  className="btn btn--primary"
                  onClick={handleRename}
                  disabled={saving || !newName}
                >
                  Enregistrer
                </button>
                <button
                  type="button"
                  className="btn btn--secondary"
                  onClick={() => setRenaming(false)}
                  disabled={saving}
                >
                  Annuler
                </button>
              </div>
            </div>
          )}

          <div className="planning-detail">
            <div className="planning-detail__lines">
              <div className="section-title">
                <h2>Lignes de garde</h2>
                {planning.canManage && !showLineForm && (
                  <button
                    type="button"
                    className="btn btn--secondary btn--sm"
                    onClick={() => setShowLineForm(true)}
                  >
                    <Icon name="plus" size={16} strokeWidth={2} />
                    Ajouter une ligne
                  </button>
                )}
              </div>

              {actionError && (
                <p role="alert" className="alert alert--error">
                  <Icon name="alert" size={18} strokeWidth={2} />
                  <span>{actionError}</span>
                </p>
              )}

              {planning.canManage && showLineForm && (
                <div className="card form">
                  <Field
                    label="Nom de la ligne"
                    type="text"
                    value={lineName}
                    onChange={(event) => setLineName(event.target.value)}
                  />
                  <div className="form-actions">
                    <button
                      type="button"
                      className="btn btn--primary"
                      onClick={handleAddLine}
                      disabled={saving || !lineName}
                    >
                      Ajouter
                    </button>
                    <button
                      type="button"
                      className="btn btn--secondary"
                      onClick={() => setShowLineForm(false)}
                      disabled={saving}
                    >
                      Annuler
                    </button>
                  </div>
                </div>
              )}

              <ul className="list planning-lines">
                {planning.lines.map((line) => {
                  const open = expandedTeamStableId === line.team.stableId
                  return (
                    <li
                      key={line.stableId}
                      className={`card planning-line${open ? ' planning-line--open' : ''}`}
                    >
                      <div className="planning-line__head">
                        <div className="planning-line__main">
                          <h3>{line.name}</h3>
                          <p className="muted">
                            {line.team.name} · {line.memberCount ?? '—'} membre
                            {(line.memberCount ?? 0) > 1 ? 's' : ''}
                          </p>
                        </div>
                        <span className={`tag ${line.type === 'PRIMARY' ? 'tag--green' : 'tag--blue'}`}>
                          {line.type === 'PRIMARY' ? 'Principale' : 'Secondaire'}
                        </span>
                      </div>
                      <div className="planning-line__actions">
                        <button
                          type="button"
                          className="btn btn--secondary btn--sm"
                          aria-expanded={open}
                          onClick={() => toggleTeam(line.team.stableId)}
                        >
                          <Icon name={open ? 'down' : 'users'} size={16} strokeWidth={2} />
                          {open ? 'Masquer les membres' : 'Gérer les membres'}
                        </button>
                        {planning.canManage && line.type === 'SECONDARY' && (
                          <button
                            type="button"
                            className="btn btn--danger btn--sm"
                            onClick={() => handleDeleteLine(line.stableId)}
                            disabled={saving}
                          >
                            Supprimer
                          </button>
                        )}
                      </div>
                    </li>
                  )
                })}
              </ul>
            </div>

            {expandedTeamStableId && (
              <div className="planning-detail__members card card--flush">
                <div className="card__header">
                  <h2>Membres de l'équipe</h2>
                </div>
                {membersLoading && <p className="muted planning-members__note">Chargement des membres…</p>}
                {!membersLoading && (
                  <ul className="list">
                    {members.map((member) => (
                      <li key={member.stableId} className="list-row">
                        <span className="avatar">
                          {`${member.firstName.charAt(0)}${member.lastName.charAt(0)}`.toUpperCase()}
                        </span>
                        <div className="list-row__main">
                          <div className="list-row__title">
                            {member.firstName} {member.lastName}
                          </div>
                          <div className="list-row__meta">
                            <span className={`tag ${ROLE_TAG[member.role].tone}`}>
                              {ROLE_TAG[member.role].label}
                            </span>
                            {member.membershipEnd ? ` Adhésion terminée le ${member.membershipEnd}` : ''}
                          </div>
                        </div>
                        {planning.canManage && !member.membershipEnd && (
                          <button
                            type="button"
                            className="btn btn--ghost btn--sm"
                            onClick={() => handleEndMembership(expandedTeamStableId, member.stableId)}
                            disabled={saving}
                          >
                            Terminer l'adhésion
                          </button>
                        )}
                      </li>
                    ))}
                    {members.length === 0 && <li className="list-row muted">Aucun membre pour le moment.</li>}
                  </ul>
                )}

                {memberError && (
                  <p role="alert" className="alert alert--error planning-members__note">
                    <Icon name="alert" size={18} strokeWidth={2} />
                    <span>{memberError}</span>
                  </p>
                )}

                {planning.lines.find((line) => line.team.stableId === expandedTeamStableId)?.team
                  .canInvite && (
                  <TeamInvitePanel
                    key={expandedTeamStableId}
                    planningStableId={planningId}
                    teamStableId={expandedTeamStableId}
                    onMembersChanged={() => loadMembers(expandedTeamStableId)}
                  />
                )}

                {planning.canManage && (
                  <div className="planning-members__existing form">
                    <p className="muted">
                      Utilisateur existant (identifiant connu) — permet aussi de choisir le rôle et la date
                      d'entrée.
                    </p>
                    <Field
                      label="Identifiant de l'utilisateur"
                      type="text"
                      value={memberUserStableId}
                      onChange={(event) => setMemberUserStableId(event.target.value)}
                      placeholder="stableId de l'utilisateur"
                    />
                    <div className="field">
                      <label htmlFor="member-role" className="field__label">
                        Rôle
                      </label>
                      <select
                        id="member-role"
                        className="field__input"
                        value={memberRole}
                        onChange={(event) =>
                          setMemberRole(event.target.value as 'OWNER' | 'ADMIN' | 'MEMBER')
                        }
                      >
                        <option value="MEMBER">Membre</option>
                        <option value="ADMIN">Admin</option>
                        <option value="OWNER">Propriétaire</option>
                      </select>
                    </div>
                    <Field
                      label="Date d'entrée"
                      type="date"
                      value={memberStart}
                      onChange={(event) => setMemberStart(event.target.value)}
                    />
                    <button
                      type="button"
                      className="btn btn--primary"
                      onClick={() => handleAddMember(expandedTeamStableId)}
                      disabled={saving || !memberUserStableId || !memberStart}
                    >
                      Ajouter
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>

          <AvailabilityCollectionsPanel
            planningStableId={planning.stableId}
            reloadToken={collectionsReload}
          />

          {planning.canManage && (
            <ExtendPlanningForm
              planning={planning}
              onExtended={() => {
                setCollectionsReload((count) => count + 1)
                load()
              }}
            />
          )}

          <PersonalPlanningView planning={planning} />
        </>
      )}
    </section>
  )
}

const ROLE_TAG: Record<'OWNER' | 'ADMIN' | 'MEMBER', { label: string; tone: string }> = {
  OWNER: { label: 'Propriétaire', tone: 'tag--green' },
  ADMIN: { label: 'Admin', tone: 'tag--blue' },
  MEMBER: { label: 'Membre', tone: '' },
}
