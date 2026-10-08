import { useEffect, useId, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { useAuth } from '../features/auth/useAuth'
import {
  addTeamMember,
  createPlanningLine,
  deletePlanningLine,
  fetchPlanning,
  renamePlanning,
} from '../features/planning/api'
import { AvailabilityCollectionsPanel } from '../features/planning/AvailabilityCollectionsPanel'
import { PlanningCalendar } from '../features/planning/calendar/PlanningCalendar'
import { ActionMenu } from '../features/planning/detail/ActionMenu'
import { PlanningSwapHistory } from '../features/swaps/PlanningSwapHistory'
import { LinesTab, type Face } from '../features/planning/detail/LinesTab'
import { MembersSheet } from '../features/planning/detail/MembersSheet'
import { LineSettingsDialog } from '../features/planning/coverage/LineSettingsDialog'
import { PeriodCard } from '../features/planning/detail/PeriodCard'
import { formatShortDate } from '../features/planning/detail/period'
import { PlanningSteps, type DetailTab, type Step } from '../features/planning/detail/PlanningSteps'
import { Sheet } from '../features/planning/detail/Sheet'
import '../features/planning/detail/planningDetail.css'
import { ExtendPlanningForm } from '../features/planning/ExtendPlanningForm'
import { PlanningJobBanner } from '../features/planning/jobs/PlanningJobBanner'
import { usePlanningJob } from '../features/planning/jobs/usePlanningJob'
import { PersonalPlanningView } from '../features/planning/PersonalPlanningView'
import { CollectionStatusPanel } from '../features/planning/pilot/CollectionStatusPanel'
import { ResultBody } from '../features/planning/pilot/GenerationModal'
import { PilotHeaderActions, type PilotDialog } from '../features/planning/pilot/PilotHeaderActions'
import type { CollectionStatus, LaunchResult, PlanningJob } from '../features/planning/pilot/types'
import { completePlanning } from '../features/planning/result/api'
import { usePlanningPilot } from '../features/planning/pilot/usePlanningPilot'
import type { PlanningDetail, PlanningLineSummary, PlanningPeriodStatus } from '../features/planning/types'
import { ApiError } from '../lib/apiClient'

/** Lifecycle of the primary line's period, shown next to the planning's name. */
const PERIOD_STATUS: Record<PlanningPeriodStatus, string> = {
  DRAFT: 'Brouillon',
  GENERATED: 'Généré',
  VALIDATED: 'Validé',
  PUBLISHED: 'Publié',
  ARCHIVED: 'Archivé',
}

const plural = (n: number, word: string) => `${n} ${word}${n > 1 ? 's' : ''}`

/**
 * /plannings/:id, laid out as docs/Design/react_planning_detail (docs/decisions.md
 * D140): header, period, the three steps, and the "Lignes de garde" /
 * "Indisponibilités" / "Planning" tabs. Every action keeps its existing
 * component and endpoint; only the layout changed.
 */
export function PlanningDetailPage() {
  const { planningId } = useParams<{ planningId: string }>()
  const { user } = useAuth()
  const [planning, setPlanning] = useState<PlanningDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [tab, setTab] = useState<DetailTab>('lines')
  const [saving, setSaving] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const [renameOpen, setRenameOpen] = useState(false)
  const [newName, setNewName] = useState('')
  const [extendOpen, setExtendOpen] = useState(false)
  const [membersLine, setMembersLine] = useState<PlanningLineSummary | null>(null)
  const [settingsLine, setSettingsLine] = useState<PlanningLineSummary | null>(null)
  const [pilotDialog, setPilotDialog] = useState<PilotDialog | null>(null)
  // The per-collection detail (history, close) is one click away for a manager, not the first thing they see.
  const [showCollectionHistory, setShowCollectionHistory] = useState(false)
  // Bumped after an extension so the availability follow-up shows the collection it just opened.
  const [collectionsReload, setCollectionsReload] = useState(0)
  // Bumped after a generation so the per-person view (which reads on mount) shows the new assignments.
  const [generationVersion, setGenerationVersion] = useState(0)
  // The vertical multi-line calendar is the main screen after a generation (docs/decisions.md D148).
  // "swaps": the managers' read-only swap history (docs/decisions.md D178).
  const [planningView, setPlanningView] = useState<'calendar' | 'person' | 'swaps'>('calendar')

  const pilot = usePlanningPilot(planningId ?? '', planning?.canManageAvailability === true)
  const [jobDetail, setJobDetail] = useState<PlanningJob | null>(null)
  // The engine job (generation or completion) is followed from the server, on every tab
  // (docs/decisions.md D149): when it ends on this screen, everything that shows its effect re-reads.
  const jobs = usePlanningJob(planningId ?? '', (job) => {
    setGenerationVersion((count) => count + 1)
    load()
    void pilot.reload()
    if (job.kind === 'GENERATE' && job.status === 'SUCCEEDED') {
      setTab('planning')
    }
  })

  async function requestCompletion() {
    if (!planningId) return
    const { job } = await completePlanning(planningId)
    jobs.track(job)
  }
  const tabsId = useId()

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

  const facesByTeam = useMemo(() => facesFrom(pilot.status, user?.stableId), [pilot.status, user?.stableId])

  async function handleRename() {
    if (!planningId || !newName) {
      return
    }

    setSaving(true)
    setActionError(null)
    try {
      await renamePlanning(planningId, newName)
      setRenameOpen(false)
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
      void pilot.reload()
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setActionError('Vous faites déjà partie de la ligne principale.')
      } else {
        setActionError('Impossible de vous inclure dans ce planning.')
      }
    } finally {
      setSaving(false)
    }
  }

  async function handleAddLine(name: string): Promise<boolean> {
    if (!planningId) {
      return false
    }

    setSaving(true)
    setActionError(null)
    try {
      await createPlanningLine(planningId, { name })
      load()
      return true
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setActionError('Cette équipe a déjà un planning sur cette période.')
      } else {
        setActionError("Impossible d'ajouter cette ligne.")
      }
      return false
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

  if (!planningId) {
    return <p role="alert">Planning introuvable.</p>
  }

  if (loading || error || !planning) {
    return (
      <div className="pd-page">
        <Link to="/plannings" className="pd-back">
          <Icon name="left" size={16} strokeWidth={2.2} />
          Plannings
        </Link>
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
      </div>
    )
  }

  const primaryLine = planning.lines.find((line) => line.type === 'PRIMARY')
  const status = primaryLine?.periodStatus ?? 'DRAFT'
  const generated = planning.lines.some((line) => line.periodStatus && line.periodStatus !== 'DRAFT')
  // After publication no general (re)generation is offered (the server refuses it anyway, PERIOD_LOCKED).
  const published = planning.lines.some((line) => line.active && line.periodStatus === 'PUBLISHED')
  const steps = buildSteps(planning, pilot.status, generated, status)
  const onRename = planning.canManage
    ? () => {
        setNewName(planning.name)
        setRenameOpen(true)
      }
    : undefined
  const tabs: { id: DetailTab; long: string; short: string; count?: number }[] = [
    { id: 'lines', long: 'Lignes de garde', short: 'Lignes', count: planning.lines.length },
    {
      id: 'availability',
      long: 'Indisponibilités',
      short: 'Indispos',
      count: pilot.status?.summary.expectedCount,
    },
    { id: 'planning', long: 'Planning', short: 'Planning' },
  ]

  return (
    <div className="pd-page">
      <section className="pd-head">
        <Link to="/plannings" className="pd-back">
          <Icon name="left" size={16} strokeWidth={2.2} />
          Plannings
        </Link>
        <div className="pd-head-row">
          <div className="pd-head-title">
            <div className="pd-title-line">
              <h1>{planning.name}</h1>
              <span className="pd-badge" data-status={status}>
                <span className="pd-dot" />
                {PERIOD_STATUS[status]}
              </span>
            </div>
            {planning.participating ? (
              <span className="pd-me">
                <span className="pd-dot pd-dot-brand" />
                Vous en faites partie
              </span>
            ) : (
              <span className="pd-me pd-me--out">
                Vous ne figurez pas parmi les candidats de ce planning.
                {planning.canManage && (
                  <button type="button" className="pd-link" onClick={handleIncludeMe} disabled={saving}>
                    M&apos;inclure dans ce planning
                  </button>
                )}
              </span>
            )}
          </div>
          {planning.canGenerate ? (
            <PilotHeaderActions
              planningStableId={planning.stableId}
              timezone={planning.timezone}
              status={pilot.status}
              primaryLineStableId={primaryLine?.stableId}
              primaryLineName={primaryLine?.name}
              onRename={onRename}
              onChanged={() => void pilot.reload()}
              onLaunched={(job) => jobs.track(job)}
              canLaunchGeneration={!published}
              busy={jobs.active}
              dialog={pilotDialog}
              onDialogChange={setPilotDialog}
            />
          ) : (
            onRename && (
              <div className="pd-head-actions">
                <ActionMenu
                  label="Plus d’actions"
                  large
                  items={[{ label: 'Modifier le nom', icon: 'pencil', onSelect: onRename }]}
                />
              </div>
            )
          )}
        </div>
      </section>

      <PlanningJobBanner
        job={jobs.job}
        finished={jobs.finished}
        canRelaunch={planning.canGenerate === true || planning.canManageCalendar === true}
        onRelaunch={(kind) => {
          if (kind === 'GENERATE') {
            setPilotDialog('generate')
          } else {
            requestCompletion().catch(() =>
              setActionError('La complétion automatique n’a pas pu être relancée.'),
            )
          }
        }}
        onShowDetail={setJobDetail}
        onDismiss={jobs.dismiss}
      />

      {actionError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{actionError}</span>
        </p>
      )}

      <PeriodCard
        startsAt={planning.startsAt}
        endsAt={planning.endsAt}
        timezone={planning.timezone}
        onExtend={planning.canManage ? () => setExtendOpen(true) : undefined}
      />

      <PlanningSteps steps={steps} tab={tab} onSelect={setTab} />

      <section className="pd-tabs-section">
        <div className="pd-tabs" role="tablist" aria-label="Sections du planning">
          {tabs.map((t) => (
            <button
              key={t.id}
              type="button"
              role="tab"
              id={`${tabsId}-${t.id}`}
              aria-selected={tab === t.id}
              aria-controls={`${tabsId}-panel`}
              // One accessible name at every width: CSS swaps the visible long/short label.
              aria-label={t.count !== undefined ? `${t.long} (${t.count})` : t.long}
              className="pd-tab"
              onClick={() => setTab(t.id)}
            >
              <span className="pd-lg" aria-hidden>
                {t.long}
              </span>
              <span className="pd-sm" aria-hidden>
                {t.short}
              </span>
              {t.count !== undefined && (
                <span className="pd-tab-count" aria-hidden>
                  {t.count}
                </span>
              )}
            </button>
          ))}
        </div>

        <div role="tabpanel" id={`${tabsId}-panel`} aria-labelledby={`${tabsId}-${tab}`}>
          {tab === 'lines' && (
            <LinesTab
              lines={planning.lines}
              facesByTeam={facesByTeam}
              canManage={planning.canManage}
              canConfigure={planning.canManageLineStructure === true}
              saving={saving}
              onManageMembers={setMembersLine}
              onDeleteLine={handleDeleteLine}
              onConfigureLine={setSettingsLine}
              onAddLine={handleAddLine}
            />
          )}

          {tab === 'availability' &&
            (planning.canManageAvailability ? (
              <div className="pd-stack">
                {pilot.error && (
                  <p role="alert" className="alert alert--error">
                    <Icon name="alert" size={18} strokeWidth={2} />
                    <span>{pilot.error}</span>
                  </p>
                )}
                {!pilot.status && !pilot.error && (
                  <p role="status" className="muted">
                    Chargement du suivi de la collecte…
                  </p>
                )}
                {pilot.status && (
                  <CollectionStatusPanel
                    status={pilot.status}
                    onChanged={() => void pilot.reload()}
                    onEditDeadline={planning.canGenerate ? () => setPilotDialog('settings') : undefined}
                  />
                )}
                <div>
                  <button
                    type="button"
                    className="pd-link"
                    aria-expanded={showCollectionHistory}
                    onClick={() => setShowCollectionHistory((value) => !value)}
                  >
                    <Icon name={showCollectionHistory ? 'down' : 'right'} size={16} strokeWidth={2} />
                    Collectes par fenêtre (échéances, clôture)
                  </button>
                </div>
                {showCollectionHistory && (
                  <AvailabilityCollectionsPanel
                    planningStableId={planning.stableId}
                    reloadToken={collectionsReload}
                  />
                )}
              </div>
            ) : (
              <AvailabilityCollectionsPanel
                planningStableId={planning.stableId}
                reloadToken={collectionsReload}
              />
            ))}

          {tab === 'planning' &&
            (generated ? (
              <div className="pd-stack">
                <div className="stats-panel__tabs" role="group" aria-label="Affichage du planning">
                  <button
                    type="button"
                    aria-pressed={planningView === 'calendar'}
                    className={`btn btn--sm ${planningView === 'calendar' ? 'btn--primary' : 'btn--secondary'}`}
                    onClick={() => setPlanningView('calendar')}
                  >
                    Calendrier
                  </button>
                  <button
                    type="button"
                    aria-pressed={planningView === 'person'}
                    className={`btn btn--sm ${planningView === 'person' ? 'btn--primary' : 'btn--secondary'}`}
                    onClick={() => setPlanningView('person')}
                  >
                    Par personne
                  </button>
                  {planning.canManageCalendar && (
                    <button
                      type="button"
                      aria-pressed={planningView === 'swaps'}
                      className={`btn btn--sm ${planningView === 'swaps' ? 'btn--primary' : 'btn--secondary'}`}
                      onClick={() => setPlanningView('swaps')}
                    >
                      Échanges
                    </button>
                  )}
                </div>
                {planningView === 'swaps' && planning.canManageCalendar ? (
                  <PlanningSwapHistory planningStableId={planning.stableId} />
                ) : planningView === 'calendar' ? (
                  <PlanningCalendar
                    key={generationVersion}
                    planning={planning}
                    onPublished={load}
                    onRequestCompletion={requestCompletion}
                    jobActive={jobs.active}
                    jobKind={jobs.active ? (jobs.job?.kind ?? null) : null}
                  />
                ) : (
                  <PersonalPlanningView key={generationVersion} planning={planning} />
                )}
              </div>
            ) : (
              <div className="pd-card pd-empty">
                <span className="pd-empty-icon" aria-hidden>
                  <Icon name="calendar" size={24} strokeWidth={1.9} />
                </span>
                <strong>Pas encore de planning</strong>
                <span>
                  {planning.canGenerate
                    ? 'Lancez la génération : les gardes de chaque membre s’afficheront ici, mois par mois.'
                    : 'Les gardes s’afficheront ici dès que le planning aura été généré.'}
                </span>
                {planning.canGenerate && (
                  <button
                    type="button"
                    className="pd-btn pd-btn-primary"
                    onClick={() => setPilotDialog('generate')}
                  >
                    Générer le planning
                  </button>
                )}
              </div>
            ))}
        </div>
      </section>

      {jobDetail?.outcome && (
        <Sheet title="Résultat de la génération" wide onClose={() => setJobDetail(null)}>
          <ResultBody result={jobDetail.outcome as unknown as LaunchResult} timezone={planning.timezone} />
        </Sheet>
      )}

      {renameOpen && (
        <Sheet title="Modifier le nom" onClose={() => setRenameOpen(false)}>
          <form
            className="form"
            onSubmit={(event) => {
              event.preventDefault()
              void handleRename()
            }}
          >
            <Field
              label="Nouveau nom"
              type="text"
              value={newName}
              autoFocus
              onChange={(event) => setNewName(event.target.value)}
            />
            <button
              type="submit"
              className="pd-btn pd-btn-primary pd-btn-lg pd-full"
              disabled={saving || !newName}
            >
              Enregistrer
            </button>
          </form>
        </Sheet>
      )}

      {extendOpen && (
        <Sheet title="Prolonger la période" onClose={() => setExtendOpen(false)}>
          <ExtendPlanningForm
            planning={planning}
            onCancel={() => setExtendOpen(false)}
            onExtended={() => {
              setCollectionsReload((count) => count + 1)
              void pilot.reload()
              load()
            }}
          />
        </Sheet>
      )}

      {settingsLine && (
        <LineSettingsDialog
          line={settingsLine}
          onClose={() => setSettingsLine(null)}
          onSaved={() => {
            load()
            void pilot.reload()
          }}
        />
      )}

      {membersLine && (
        <MembersSheet
          planningStableId={planning.stableId}
          line={membersLine}
          canManage={planning.canManage}
          onClose={() => setMembersLine(null)}
          onChanged={() => {
            load()
            void pilot.reload()
          }}
        />
      )}
    </div>
  )
}

/** Équipe → Indisponibilités → Génération, from what the page already loaded. */
function buildSteps(
  planning: PlanningDetail,
  pilot: CollectionStatus | null,
  generated: boolean,
  status: PlanningPeriodStatus,
): Step[] {
  const memberTotal = planning.lines.reduce((sum, line) => sum + (line.memberCount ?? 0), 0)
  const staffed = planning.lines.every((line) => (line.memberCount ?? 0) > 0)
  const team: Step = {
    title: 'Équipe',
    detail: `${plural(planning.lines.length, 'ligne')} · ${plural(memberTotal, 'membre')}`,
    state: staffed ? 'done' : 'todo',
    tag: staffed ? 'Prêt' : 'À compléter',
    tab: 'lines',
  }

  let availability: Step
  if (pilot) {
    const { confirmedCount, expectedCount, pendingCount } = pilot.summary
    const deadline = pilot.availabilityDeadline
      ? ` · fin souhaitée ${formatShortDate(pilot.availabilityDeadline)}`
      : ''
    availability =
      expectedCount > 0 && pendingCount > 0
        ? {
            title: 'Indisponibilités',
            detail: `${confirmedCount}/${expectedCount} confirmés${deadline}`,
            state: 'current',
            tag: 'En cours',
            tab: 'availability',
          }
        : {
            title: 'Indisponibilités',
            detail:
              expectedCount > 0 ? `${confirmedCount}/${expectedCount} confirmés` : 'Personne n’est attendu',
            state: 'done',
            tag: 'Prêt',
            tab: 'availability',
          }
  } else {
    availability = {
      title: 'Indisponibilités',
      detail: planning.canManageAvailability ? 'Chargement du suivi…' : 'Vos réponses aux collectes',
      state: 'current',
      tag: 'Collecte',
      tab: 'availability',
    }
  }

  const generation: Step = generated
    ? {
        title: 'Génération',
        detail: status === 'PUBLISHED' ? 'Planning publié' : 'Planning généré',
        state: 'done',
        tag: PERIOD_STATUS[status],
        tab: 'planning',
      }
    : { title: 'Génération', detail: 'Pas encore lancée', state: 'todo', tag: 'À faire', tab: 'planning' }

  return [team, availability, generation]
}

/** Initials of each team's participants (pilot data), the current user first-class ("me"). */
function facesFrom(status: CollectionStatus | null, myStableId: string | undefined): Record<string, Face[]> {
  const byTeam: Record<string, Face[]> = {}
  for (const row of status?.members ?? []) {
    ;(byTeam[row.team.stableId] ??= []).push({
      key: row.memberStableId,
      initials: `${row.firstName.charAt(0)}${row.lastName.charAt(0)}`.toUpperCase(),
      me: row.userStableId === myStableId,
    })
  }
  return byTeam
}
