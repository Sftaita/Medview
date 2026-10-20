import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { Field } from '../components/Field'
import { Icon } from '../components/Icon'
import { useAuth } from '../features/auth/useAuth'
import { createPeriod, openPeriodCollection } from '../features/periods/api'
import { CreatePeriodDialog } from '../features/periods/components/CreatePeriodDialog'
import { Icon as PeriodIcon } from '../features/periods/components/Icon'
import { PeriodDetailCard } from '../features/periods/components/PeriodDetailCard'
import { PeriodSelector } from '../features/periods/components/PeriodSelector'
import { PeriodTabs } from '../features/periods/components/PeriodTabs'
import { PreparationSteps } from '../features/periods/components/PreparationSteps'
import { addDays, formatRange } from '../features/periods/dates'
import { collectionOpeningMessage, periodCreationError } from '../features/periods/errors'
import { defaultPeriodNumber, hasReadableCalendar, lineStates, toPeriod } from '../features/periods/model'
import '../features/periods/periodes.css'
import type { ISODate } from '../features/periods/types'
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
import type { PlanningDetail, PlanningLineSummary } from '../features/planning/types'
import { ApiError } from '../lib/apiClient'

type DetailTab = 'lines' | 'availability' | 'planning'

const plural = (n: number, word: string) => `${n} ${word}${n > 1 ? 's' : ''}`

/** "YYYY-MM-DD" of the browser's day — only when the server sends no `today` of the planning. */
function localToday(): ISODate {
  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/**
 * /plannings/:id (docs/decisions.md D140), one permanent planning and its
 * successive periods (docs/planning-cycles.md §8, lot L6): the header common
 * to every period, the periods (docs/Design/medvue-periodes: frise, selected
 * period, creation of the next one), then everything of the selected period —
 * its preparation, its lines, its availability, its calendar, statistics and
 * publications. The period is in the URL (`?periode=N`), so it can be shared
 * and the browser's back and forward move between periods. Every action keeps
 * its existing component and endpoint, now told which period it works on; an
 * answer for a period no longer selected is never shown.
 */
export function PlanningDetailPage() {
  const { planningId } = useParams<{ planningId: string }>()
  const [searchParams, setSearchParams] = useSearchParams()
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
  const [createOpen, setCreateOpen] = useState(false)
  const [toast, setToast] = useState<string | null>(null)
  const toastTimer = useRef<number | undefined>(undefined)
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

  // --- the periods (docs/planning-cycles.md §4.1) ---------------------------------------------
  const today = planning?.today ?? localToday()
  const periodViews = useMemo(() => planning?.periods ?? [], [planning?.periods])
  const periods = useMemo(() => periodViews.map(toPeriod), [periodViews])
  const requested = Number(searchParams.get('periode'))
  const selectedNumber = periods.some((p) => p.number === requested)
    ? requested
    : defaultPeriodNumber(periods, today)
  const periodView = periodViews.find((p) => p.number === selectedNumber)
  const period = periods.find((p) => p.number === selectedNumber)
  const periodStableId = periodView?.stableId
  const single = periods.length <= 1

  /** Another period: a new history entry, so the browser's back button returns to the previous one. */
  const selectPeriod = useCallback(
    (number: number) => {
      setSearchParams((previous) => {
        const next = new URLSearchParams(previous)
        next.set('periode', String(number))
        return next
      })
    },
    [setSearchParams],
  )

  const pilot = usePlanningPilot(planningId ?? '', planning?.canManageAvailability === true, periodStableId)
  const [jobDetail, setJobDetail] = useState<PlanningJob | null>(null)
  // The engine job (generation or completion) is followed from the server, on every tab
  // (docs/decisions.md D149): when it ends on this screen, everything that shows its effect re-reads.
  const jobs = usePlanningJob(planningId ?? '', (job) => {
    setGenerationVersion((count) => count + 1)
    void load()
    void pilot.reload()
    if (job.kind === 'GENERATE' && job.status === 'SUCCEEDED') {
      setTab('planning')
    }
  })

  async function requestCompletion() {
    if (!planningId) return
    const { job } = await completePlanning(planningId, periodStableId)
    jobs.track(job)
  }

  function load(): Promise<void> {
    if (!planningId) {
      return Promise.resolve()
    }

    // No spinner on a reload: the page stays as it is (and keeps its notices) while the data refreshes.
    setError(null)
    return fetchPlanning(planningId)
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
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [planningId])

  useEffect(() => () => window.clearTimeout(toastTimer.current), [])

  const facesByTeam = useMemo(() => facesFrom(pilot.status, user?.stableId), [pilot.status, user?.stableId])

  function showToast(message: string) {
    setToast(message)
    window.clearTimeout(toastTimer.current)
    toastTimer.current = window.setTimeout(() => setToast(null), 4500)
  }

  /** docs/planning-cycles.md §5.1: the dialog shows the last day included, the API takes the exclusive end. */
  async function handleCreatePeriod(lastDay: ISODate, acceptHistoricalBlockCuts: boolean) {
    const last = periodViews[periodViews.length - 1]
    if (!planningId || !last) return
    let created
    try {
      created = await createPeriod(planningId, {
        endsAt: addDays(lastDay, 1),
        previousPeriodStableId: last.stableId,
        acceptHistoricalBlockCuts,
      })
    } catch (err) {
      throw periodCreationError(err)
    }
    setCreateOpen(false)
    await load()
    selectPeriod(created.number)
    showToast(`Période ${created.number} créée · ${formatRange(created.startsAt, created.lastDay)}`)
  }

  async function handleOpenCollection() {
    if (!planningId || !periodStableId) return
    try {
      await openPeriodCollection(planningId, periodStableId)
    } catch (err) {
      throw new Error(collectionOpeningMessage(err))
    }
    setCollectionsReload((count) => count + 1)
    void pilot.reload()
    await load()
    showToast(`Collecte des disponibilités ouverte pour la période ${selectedNumber}`)
  }

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
      void load()
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
      const day = new Date().toISOString().slice(0, 10)
      await addTeamMember(planningId, primary.team.stableId, {
        userStableId: user.stableId,
        role: 'OWNER',
        // From the start of the planning, so the whole range is covered.
        membershipStart: day < planning.startsAt ? day : planning.startsAt,
      })
      void load()
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
      void load()
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
      void load()
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
  // The selected period's own state — never the planning's other periods (docs/planning-cycles.md §4.2).
  // docs/decisions.md D204: a plain member reads the published lines only — a draft period has nothing to show them.
  const generated = periodView
    ? hasReadableCalendar(periodView, planning.canManageCalendar === true)
    : planning.lines.some((line) => line.periodStatus && line.periodStatus !== 'DRAFT')
  // A fully published period offers no (re)generation (the server refuses it anyway, PERIOD_LOCKED). A partly
  // published one does: the dialog offers to generate only the lines not published (docs/decisions.md D195).
  const fullyPublished = periodView
    ? periodView.status === 'published'
    : planning.lines.some((line) => line.active && line.periodStatus === 'PUBLISHED')
  const latest = periods[periods.length - 1]
  const selectedIndex = period ? periods.indexOf(period) : -1
  const memberTotal = planning.memberCount ?? 0
  const activeLines = planning.lines.filter((line) => line.active).length
  const canCreatePeriod = planning.canCreatePeriod === true
  const onRename = planning.canManage
    ? () => {
        setNewName(planning.name)
        setRenameOpen(true)
      }
    : undefined
  // Extending the planning (docs/decisions.md D122) only means something while it has a single period.
  const onExtend = planning.canManage && single ? () => setExtendOpen(true) : undefined
  const tabs: { id: DetailTab; label: string; short: string; count?: number }[] = [
    { id: 'lines', label: 'Lignes de garde', short: 'Lignes', count: planning.lines.length },
    {
      id: 'availability',
      label: 'Indisponibilités',
      short: 'Indispos',
      count: pilot.status?.summary.expectedCount,
    },
    { id: 'planning', label: single ? 'Planning' : `Gardes · période ${selectedNumber}`, short: 'Gardes' },
  ]
  // Lot L7: each line with the people who take part in the selected period — never the default period's count.
  const linesOfPeriod = planning.lines.map((line) => {
    const count = line.periods?.find((p) => p.periodStableId === periodStableId)?.memberCount
    return count === undefined ? line : { ...line, memberCount: count }
  })
  const calendarPeriod = periodView
    ? {
        stableId: periodView.stableId,
        number: periodView.number,
        startsAt: periodView.startsAt,
        endsAt: periodView.endsAt,
        lastDay: periodView.lastDay,
      }
    : undefined

  return (
    <div className={`pd-page ${canCreatePeriod ? 'pd-page--with-action' : ''}`}>
      <section className="pd-head">
        <Link to="/plannings" className="pd-back">
          <Icon name="left" size={16} strokeWidth={2.2} />
          Plannings
        </Link>
        <div className="pd-head-row">
          <div className="pd-head-title">
            <div className="pd-title-line">
              <h1>{planning.name}</h1>
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
              onExtend={onExtend}
              period={
                periodView
                  ? {
                      stableId: periodView.stableId,
                      number: periodView.number,
                      startsAt: periodView.startsAt,
                      lastDay: periodView.lastDay,
                      named: !single,
                      hasCollection: periodView.collections.open + periodView.collections.closed > 0,
                    }
                  : undefined
              }
              generateLabel={single ? 'Générer le planning' : `Générer la période ${selectedNumber}`}
              onChanged={() => void pilot.reload()}
              onLaunched={(job) => jobs.track(job)}
              canLaunchGeneration={!fullyPublished}
              busy={jobs.active}
              dialog={pilotDialog}
              onDialogChange={setPilotDialog}
            />
          ) : (
            (onRename || onExtend) && (
              <div className="pd-head-actions">
                <ActionMenu
                  label="Plus d’actions"
                  large
                  items={[
                    ...(onRename
                      ? [{ label: 'Modifier le nom', icon: 'pencil' as const, onSelect: onRename }]
                      : []),
                    ...(onExtend
                      ? [{ label: 'Prolonger la période', icon: 'calendar' as const, onSelect: onExtend }]
                      : []),
                  ]}
                />
              </div>
            )
          )}
        </div>
        <div className="mv-shared">
          <span className="mv-eyebrow">{single ? 'Le planning' : 'Commun à toutes les périodes'}</span>
          <span className="mv-pill-link">
            <PeriodIcon name="lines" size={16} />
            {plural(activeLines, 'ligne')} de garde
          </span>
          <span className="mv-pill-link">
            <PeriodIcon name="users" size={16} />
            {plural(memberTotal, 'membre')}
          </span>
          <span className="mv-pill-link">
            <PeriodIcon name="globe" size={16} />
            {planning.timezone}
          </span>
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

      {period && latest && (
        <section className="mv-card mv-periods-card" aria-label="Périodes du planning">
          <div className="mv-periods-card__head">
            <div className="mv-row mv-row--baseline">
              <h2 className="mv-eyebrow mv-eyebrow--strong">
                {single ? 'Période du planning' : `Périodes du planning · ${periods.length}`}
              </h2>
              {!single && (
                <span className="mv-muted mv-small mv-hide-mobile">
                  Cliquez sur une période pour la consulter
                </span>
              )}
            </div>
            {canCreatePeriod && (
              <button
                type="button"
                className="mv-btn mv-btn--primary mv-hide-mobile"
                onClick={() => setCreateOpen(true)}
              >
                <PeriodIcon name="plus" strokeWidth={2.2} /> Créer la période suivante
              </button>
            )}
          </div>

          {(!single || canCreatePeriod) && (
            <PeriodSelector
              periods={periods}
              selectedId={period.id}
              today={today}
              canCreate={canCreatePeriod}
              onSelect={(id) => {
                const chosen = periods.find((p) => p.id === id)
                if (chosen) selectPeriod(chosen.number)
              }}
              onCreate={() => setCreateOpen(true)}
            />
          )}

          <PeriodDetailCard
            period={period}
            today={today}
            latestNumber={latest.number}
            lineCount={activeLines}
            memberCount={memberTotal}
            hasPrev={selectedIndex > 0}
            hasNext={selectedIndex < periods.length - 1}
            single={single}
            onPrev={() => selectPeriod(periods[selectedIndex - 1].number)}
            onNext={() => selectPeriod(periods[selectedIndex + 1].number)}
            onGoLatest={() => selectPeriod(latest.number)}
          />
        </section>
      )}

      {/* Everything below belongs to the selected period: remounted when it changes, so nothing of the previous
          period stays on screen and its late answers land nowhere (docs/planning-cycles.md §8). */}
      <div key={periodStableId ?? 'planning'} className="pd-period-content">
        {period && periodView && (
          <PreparationSteps
            period={period}
            canOpenCollection={planning.canOpenPeriodCollection === true}
            onOpenCollecte={handleOpenCollection}
            lines={lineStates(periodView, planning.lines)}
          />
        )}

        <PeriodTabs
          tabs={tabs}
          selected={tab}
          onSelect={setTab}
          context={
            !single && period
              ? `Période ${period.number} · ${formatRange(period.start, period.end)}`
              : undefined
          }
        >
          {tab === 'lines' && (
            <LinesTab
              lines={linesOfPeriod}
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
                    periodStableId={periodStableId}
                  />
                )}
              </div>
            ) : (
              <AvailabilityCollectionsPanel
                planningStableId={planning.stableId}
                reloadToken={collectionsReload}
                periodStableId={periodStableId}
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
                    period={calendarPeriod}
                    showPeriodNumber={!single}
                    onPublished={() => void load()}
                    onRequestCompletion={requestCompletion}
                    jobActive={jobs.active}
                    jobKind={jobs.active ? (jobs.job?.kind ?? null) : null}
                  />
                ) : (
                  <PersonalPlanningView key={generationVersion} planning={planning} period={calendarPeriod} />
                )}
              </div>
            ) : (
              <div className="pd-card pd-empty">
                <span className="pd-empty-icon" aria-hidden>
                  <Icon name="calendar" size={24} strokeWidth={1.9} />
                </span>
                <strong>
                  {single
                    ? 'Pas encore de planning'
                    : `Pas encore de gardes pour la période ${selectedNumber}`}
                </strong>
                <span>
                  {planning.canGenerate
                    ? 'Lancez la génération : les gardes de chaque membre s’afficheront ici, mois par mois.'
                    : 'Les gardes s’afficheront ici dès que la période aura été générée et publiée.'}
                </span>
                {planning.canGenerate && !fullyPublished && (
                  <button
                    type="button"
                    className="pd-btn pd-btn-primary"
                    onClick={() => setPilotDialog('generate')}
                  >
                    {single ? 'Générer le planning' : `Générer la période ${selectedNumber}`}
                  </button>
                )}
              </div>
            ))}
        </PeriodTabs>
      </div>

      {canCreatePeriod && (
        <div className="mv-mobile-action">
          <button
            type="button"
            className="mv-btn mv-btn--primary mv-btn--block"
            onClick={() => setCreateOpen(true)}
          >
            <PeriodIcon name="plus" strokeWidth={2.2} /> Créer la période suivante
          </button>
        </div>
      )}

      {canCreatePeriod && (
        <CreatePeriodDialog
          open={createOpen}
          planningName={planning.name}
          lineCount={activeLines}
          memberCount={memberTotal}
          periods={periods}
          onCancel={() => setCreateOpen(false)}
          onCreate={handleCreatePeriod}
          onReload={() => {
            setCreateOpen(false)
            void load()
          }}
        />
      )}

      {toast && (
        <div className="mv-toast" role="status">
          <PeriodIcon name="check" strokeWidth={2.6} />
          {toast}
        </div>
      )}

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
              void load()
            }}
          />
        </Sheet>
      )}

      {settingsLine && (
        <LineSettingsDialog
          line={settingsLine}
          onClose={() => setSettingsLine(null)}
          onSaved={() => {
            void load()
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
            void load()
            void pilot.reload()
          }}
        />
      )}
    </div>
  )
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
