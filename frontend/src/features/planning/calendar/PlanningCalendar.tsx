import { useCallback, useEffect, useMemo, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { ApiError } from '../../../lib/apiClient'
import { Sheet } from '../detail/Sheet'
import { ExportModal } from '../export/ExportModal'
import { fetchPlanningResult, fetchPublicationPdf, fetchPublicationState } from '../result/api'
import { PublishModal } from '../result/PublishModal'
import { impactSummary } from '../result/impacts'
import { ReassignmentModal } from '../result/ReassignmentModal'
import { StatisticsPanel } from '../result/StatisticsPanel'
import type { PlanningResult, PublicationState } from '../result/types'
import type { PlanningDetail } from '../types'
import { buildCalendar, type CalendarItem, presentDuty } from './calendarModel'
import { RepublishModal } from './RepublishModal'
import './calendar.css'

type Props = {
  planning: PlanningDetail
  /** A publication changed the planning's status: the page re-reads it. */
  onPublished?: () => void
  /**
   * "Compléter automatiquement" — queued by the page, which follows the job
   * (docs/decisions.md D149). Resolves once the request was accepted, never
   * once the completion is done; rejects with the API error when refused.
   */
  onRequestCompletion?: () => Promise<void>
  /** A generation or a completion is queued or running on this planning. */
  jobActive?: boolean
}

const MONTH = new Intl.DateTimeFormat('fr-BE', { month: 'long', year: 'numeric', timeZone: 'UTC' })
const DAY = new Intl.DateTimeFormat('fr-BE', {
  weekday: 'short',
  day: '2-digit',
  month: '2-digit',
  timeZone: 'UTC',
})
const WEEK = new Intl.DateTimeFormat('fr-BE', { day: 'numeric', month: 'long', timeZone: 'UTC' })

function utc(date: string): Date {
  return new Date(`${date}T00:00:00Z`)
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

function publishedAtLabel(iso: string, timeZone: string): string {
  const date = new Date(iso)
  const day = new Intl.DateTimeFormat('fr-BE', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone,
  }).format(date)
  const time = new Intl.DateTimeFormat('fr-BE', { hour: '2-digit', minute: '2-digit', timeZone }).format(date)
  return `${day} à ${time}`
}

/** The month to open on: the current one, kept inside the planning. */
function initialMonth(keys: string[]): string | undefined {
  const current = new Date().toISOString().slice(0, 7)
  if (keys.length === 0) return undefined
  if (current < keys[0]) return keys[0]
  if (current > keys[keys.length - 1]) return keys[keys.length - 1]
  return current
}

/**
 * The planning screen after generation (docs/decisions.md D148): dates
 * vertically, one column per line, grouped by week, month by month. A
 * block's days are drawn as one unit and editing any of them edits the
 * whole block. An uncovered duty reads "⚠ Non attribué" — a deliberate
 * intermediate state, never an error.
 *
 * Everything is read from the current calendar (`/result`); nothing is
 * computed here beyond laying it out. A manager can edit a duty, complete
 * the holes automatically, open the statistics, publish, republish the
 * changes and download the PDF of the last diffusion (frozen); a member
 * only reads. Once the planning is published, anyone who reads it can also
 * export the calendar as it is now (PDF or Excel, docs/planning-export.md)
 * — two different documents, labelled so they are not confused.
 */
export function PlanningCalendar({ planning, onPublished, onRequestCompletion, jobActive = false }: Props) {
  const canEdit = planning.canManageCalendar === true
  const canPublish = planning.canPublish === true
  const [result, setResult] = useState<PlanningResult | null>(null)
  const [state, setState] = useState<PublicationState | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [month, setMonth] = useState<string | undefined>(undefined)
  const [editing, setEditing] = useState<string | null>(null)
  const [dialog, setDialog] = useState<'stats' | 'publish' | 'republish' | 'export' | null>(null)
  const [completing, setCompleting] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null)
  const [statsKey, setStatsKey] = useState(0)

  // Both reads always come from the server — the current calendar and its publication state.
  const fetchAll = useCallback(() => {
    fetchPlanningResult(planning.stableId)
      .then((value) => {
        setResult(value)
        setError(null)
      })
      .catch(() => setError('Impossible de charger le planning.'))
    fetchPublicationState(planning.stableId)
      .then(setState)
      .catch(() => setState(null))
  }, [planning.stableId])

  useEffect(() => {
    fetchAll()
  }, [fetchAll])

  /** After any write: the calendar, the "Modifications non publiées" state and the statistics all re-read. */
  const reload = useCallback(() => {
    fetchAll()
    setStatsKey((key) => key + 1)
  }, [fetchAll])

  const model = useMemo(
    () => (result ? buildCalendar(result.lines, planning.startsAt, planning.endsAt) : null),
    [result, planning.startsAt, planning.endsAt],
  )
  const monthKeys = useMemo(() => model?.months.map((m) => m.key) ?? [], [model])
  const shownMonth = month && monthKeys.includes(month) ? month : initialMonth(monthKeys)
  const monthIndex = shownMonth ? monthKeys.indexOf(shownMonth) : -1
  const current = model && monthIndex >= 0 ? model.months[monthIndex] : null

  async function handleComplete() {
    if (!onRequestCompletion) return
    setCompleting(true)
    setNotice(null)
    try {
      await onRequestCompletion()
    } catch (err) {
      const code = err instanceof ApiError ? (err.body as { error?: string } | null)?.error : undefined
      setNotice({
        tone: 'error',
        text:
          code === 'job_in_progress'
            ? 'Une génération ou une complétion est déjà en cours sur ce planning.'
            : 'La complétion automatique n’a pas pu être lancée.',
      })
    } finally {
      setCompleting(false)
    }
  }

  async function handleDownload() {
    try {
      const blob = await fetchPublicationPdf(planning.stableId)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `planning-${planning.name}.pdf`
      link.click()
      URL.revokeObjectURL(url)
    } catch {
      setNotice({ tone: 'error', text: 'Le PDF n’a pas pu être téléchargé.' })
    }
  }

  const published = state?.published === true
  const dirty = state?.hasUnpublishedChanges === true
  const changeCount = state?.changes?.length ?? 0

  return (
    <section className="cal" aria-label="Calendrier du planning">
      <div className="cal-toolbar">
        <div className="cal-status" aria-live="polite">
          {published ? (
            <>
              <span className="pd-badge" data-status="PUBLISHED">
                <span className="pd-dot" />
                Planning publié
              </span>
              {state?.lastPublishedAt && (
                <span className="pd-muted">
                  Dernière diffusion le {publishedAtLabel(state.lastPublishedAt, planning.timezone)}
                </span>
              )}
              {dirty && (
                <span className="cal-dirty">
                  <Icon name="alert" size={14} strokeWidth={2.2} />
                  Modifications non publiées ({changeCount})
                </span>
              )}
            </>
          ) : (
            <span className="pd-badge" data-status="GENERATED">
              <span className="pd-dot" />
              Non publié
            </span>
          )}
        </div>
        <div className="cal-actions">
          {canEdit && (
            <button
              type="button"
              className="pd-btn pd-btn-secondary"
              onClick={handleComplete}
              disabled={completing || jobActive || !model || model.uncoveredCount === 0}
              aria-busy={completing}
              title={model?.uncoveredCount === 0 ? 'Aucune garde non attribuée' : undefined}
            >
              {completing ? 'Envoi…' : 'Compléter automatiquement'}
            </button>
          )}
          {canEdit && (
            <button type="button" className="pd-btn pd-btn-secondary" onClick={() => setDialog('stats')}>
              Statistiques
            </button>
          )}
          {published && (
            <button
              type="button"
              className="pd-btn pd-btn-secondary"
              onClick={handleDownload}
              title="Le planning tel qu’il a été diffusé, sans les modifications faites depuis"
            >
              PDF de la dernière diffusion
            </button>
          )}
          {published && (
            <button
              type="button"
              className="pd-btn pd-btn-secondary"
              onClick={() => setDialog('export')}
              title="PDF ou Excel du calendrier tel qu’il est maintenant"
            >
              Exporter
            </button>
          )}
          {canPublish &&
            (published ? (
              <button
                type="button"
                className="pd-btn pd-btn-primary"
                onClick={() => setDialog('republish')}
                disabled={!dirty || jobActive}
                title={
                  jobActive
                    ? 'Un calcul est en cours sur ce planning'
                    : dirty
                      ? undefined
                      : 'Aucune modification depuis la dernière diffusion'
                }
              >
                Republier les modifications
              </button>
            ) : (
              <button
                type="button"
                className="pd-btn pd-btn-primary"
                onClick={() => setDialog('publish')}
                disabled={jobActive}
                title={jobActive ? 'Un calcul est en cours sur ce planning' : undefined}
              >
                Publier le planning
              </button>
            ))}
        </div>
      </div>

      {notice && (
        <p
          role={notice.tone === 'error' ? 'alert' : 'status'}
          className={`alert alert--${notice.tone === 'info' ? 'warning' : notice.tone}`}
        >
          <Icon
            name={notice.tone === 'error' || notice.tone === 'info' ? 'alert' : 'check'}
            size={18}
            strokeWidth={2}
          />
          <span>{notice.text}</span>
        </p>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
      {!result && !error && (
        <p role="status" className="muted">
          Chargement du planning…
        </p>
      )}

      {model && model.uncoveredCount > 0 && (
        <p className="cal-summary">
          <span className="cal-uncovered">
            ⚠ {summaryText(model.uncoveredCount - model.missingReinforcementCount, model.missingReinforcementCount)}
          </span>
          {canEdit && ' — attribuez-les une à une ou utilisez « Compléter automatiquement ».'}
        </p>
      )}
      {model && model.undeterminedCount > 0 && (
        <p className="cal-summary">
          <span className="cal-undetermined">
            ? {model.undeterminedCount} renfort{model.undeterminedCount > 1 ? 's' : ''} non évalué
            {model.undeterminedCount > 1 ? 's' : ''}
          </span>{' '}
          — la garde dont {model.undeterminedCount > 1 ? 'ils dépendent' : 'il dépend'} n’a pas de titulaire.
          Attribuez-la d’abord.
        </p>
      )}
      {model && model.superfluousCount > 0 && (
        <p className="cal-summary cal-summary--quiet">
          {model.superfluousCount} renfort{model.superfluousCount > 1 ? 's' : ''} attribué
          {model.superfluousCount > 1 ? 's' : ''} mais plus nécessaire{model.superfluousCount > 1 ? 's' : ''} (« Renfort non
          requis ») — {model.superfluousCount > 1 ? 'ils restent' : 'il reste'} en place tant que vous ne
          {model.superfluousCount > 1 ? ' les' : ' le'} retirez pas.
        </p>
      )}

      {model && current && (
        <>
          <div className="cal-nav">
            <button
              type="button"
              className="pd-icon-btn"
              aria-label="Mois précédent"
              disabled={monthIndex <= 0}
              onClick={() => setMonth(monthKeys[monthIndex - 1])}
            >
              <Icon name="left" size={20} strokeWidth={2} />
            </button>
            <span className="cal-month" aria-live="polite">
              {capitalize(MONTH.format(utc(`${current.key}-01`)))}
            </span>
            <button
              type="button"
              className="pd-icon-btn"
              aria-label="Mois suivant"
              disabled={monthIndex >= monthKeys.length - 1}
              onClick={() => setMonth(monthKeys[monthIndex + 1])}
            >
              <Icon name="right" size={20} strokeWidth={2} />
            </button>
          </div>

          <div className="cal-scroll">
            <table className="cal-table" style={{ ['--cal-lines' as string]: model.lines.length }}>
              <thead>
                <tr>
                  <th scope="col" className="cal-date">
                    Date
                  </th>
                  {model.lines.map((line) => (
                    <th key={line.stableId} scope="col">
                      {line.name}
                    </th>
                  ))}
                </tr>
              </thead>
              {current.weeks.map((week) => (
                <tbody key={week.monday}>
                  <tr className="cal-week">
                    <th colSpan={model.lines.length + 1} scope="rowgroup">
                      Semaine du {WEEK.format(utc(week.monday))}
                    </th>
                  </tr>
                  {week.days.map((day) => (
                    <tr key={day.date} className={day.weekday >= 6 ? 'cal-day cal-day--weekend' : 'cal-day'}>
                      <th scope="row" className="cal-date tnum">
                        {capitalize(DAY.format(utc(day.date)))}
                      </th>
                      {day.cells.map((items, index) => (
                        <td key={model.lines[index].stableId} data-label={model.lines[index].name}>
                          {items.length === 0 ? (
                            <span className="cal-none" aria-label="Pas de garde">
                              —
                            </span>
                          ) : (
                            items.map((item) => (
                              <DutyCell
                                key={item.duty.dutyStableId}
                                item={item}
                                canEdit={canEdit}
                                onEdit={setEditing}
                              />
                            ))
                          )}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              ))}
            </table>
          </div>
        </>
      )}

      {editing && (
        <ReassignmentModal
          planningStableId={planning.stableId}
          dutyStableId={editing}
          onClose={() => setEditing(null)}
          onChanged={(impacts) => {
            // A completion/publication message no longer describes the calendar once it is edited; what the change
            // did to the reinforcements depending on it (docs/decisions.md D165) stays in view instead.
            const consequences = impactSummary(impacts)
            setNotice(
              consequences.length > 0
                ? { tone: 'info', text: `Conséquence${consequences.length > 1 ? 's' : ''} sur les renforts — ${consequences.join(' ; ')}.` }
                : null,
            )
            reload()
          }}
        />
      )}

      {dialog === 'stats' && (
        <Sheet title="Statistiques" wide onClose={() => setDialog(null)}>
          <StatisticsPanel planningStableId={planning.stableId} refreshKey={statsKey} />
        </Sheet>
      )}

      {dialog === 'publish' && (
        <PublishModal
          planningStableId={planning.stableId}
          onClose={() => setDialog(null)}
          onPublished={() => {
            reload()
            onPublished?.()
          }}
        />
      )}

      {dialog === 'export' && <ExportModal planning={planning} onClose={() => setDialog(null)} />}

      {dialog === 'republish' && state?.changes && (
        <RepublishModal
          planningStableId={planning.stableId}
          changes={state.changes}
          onClose={() => setDialog(null)}
          onRepublished={() => {
            reload()
            onPublished?.()
          }}
        />
      )}
    </section>
  )
}

function DutyCell({
  item,
  canEdit,
  onEdit,
}: {
  item: CalendarItem
  canEdit: boolean
  onEdit: (dutyStableId: string) => void
}) {
  const { duty, blockPart, showType } = item
  const who = duty.assignment ? `${duty.assignment.user.firstName} ${duty.assignment.user.lastName}` : null
  // docs/decisions.md D167: the live state decides — the calendar only presents it.
  const presentation = presentDuty(duty)
  const gap = presentation.gap ?? 'Non attribué'
  const content = (
    <>
      {who ? (
        <span className="cal-who">{who}</span>
      ) : presentation.tone === 'undetermined' ? (
        <span className="cal-undetermined">? {gap}</span>
      ) : (
        <span className="cal-uncovered">⚠ {gap}</span>
      )}
      {presentation.tag && <span className={`cal-tag cal-tag--${presentation.tone}`}>{presentation.tag}</span>}
      {showType && <span className="cal-type"> · {duty.dutyType.name}</span>}
      {(blockPart === 'first' || blockPart === 'single') && (
        <span className="cal-block-label">Bloc {duty.groupLabel ?? ''}</span>
      )}
    </>
  )
  const className = [
    'cal-item',
    blockPart ? `cal-block cal-block--${blockPart}` : '',
    who ? '' : presentation.tone === 'undetermined' ? 'cal-item--undetermined' : 'cal-item--uncovered',
    presentation.tone === 'superfluous' ? 'cal-item--superfluous' : '',
  ]
    .filter(Boolean)
    .join(' ')
  const label = `${who ?? gap}${presentation.spoken && who ? ` (${presentation.spoken})` : ''}${blockPart ? ` — bloc ${duty.groupLabel ?? ''}` : ''}, ${duty.date}`

  return canEdit ? (
    <button
      type="button"
      className={className}
      onClick={() => onEdit(duty.dutyStableId)}
      aria-label={`Modifier : ${label}`}
    >
      {content}
    </button>
  ) : (
    <div className={className}>{content}</div>
  )
}

/** "3 gardes non attribuées, dont 1 renfort requis" — reinforcements named as such (D167). */
function summaryText(duties: number, reinforcements: number): string {
  const parts: string[] = []
  if (duties > 0) parts.push(`${duties} garde${duties > 1 ? 's' : ''} non attribuée${duties > 1 ? 's' : ''}`)
  if (reinforcements > 0)
    parts.push(`${reinforcements} renfort${reinforcements > 1 ? 's' : ''} requis non attribué${reinforcements > 1 ? 's' : ''}`)
  return parts.join(' et ')
}
