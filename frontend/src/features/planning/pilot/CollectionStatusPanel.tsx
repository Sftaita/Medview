import { useMemo, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { AbsenceExportButton } from './AbsenceExportButton'
import { remindParticipants } from './api'
import { formatDateOnly, formatLongDate, overdueMessage, plural } from './format'
import { ROLE_LABEL } from './labels'
import { MemberDrawer } from './MemberDrawer'
import { PlanningHistoryPanel } from './PlanningHistoryPanel'
import type { CollectionStatus, ParticipantRemindersResult } from './types'

type Props = {
  status: CollectionStatus
  /** Refresh the pilot data (a reminder was sent). */
  onChanged: () => void
  /** Opens the planning settings, where the (informative) deadline is set. */
  onEditDeadline?: () => void
}

type Sort = 'name' | 'unavailabilities'

/**
 * The OWNER/ADMIN follow-up of the availabilities of a period
 * (docs/availability-collection.md §15), laid out as the "Indisponibilités" tab
 * of docs/Design/react_planning_detail: how many people take part and how many
 * unavailabilities they declared, the indicative deadline, "Rappeler les
 * participants", then one row per participant — searchable, sortable, click a
 * row for the drawer — "Exporter les absences (PDF)" (docs/decisions.md D181)
 * and the history of the period (D217). docs/decisions.md D214: nobody
 * confirms anything — without any unavailability, a person is available.
 * Nothing here is ever disabled by the deadline.
 */
export function CollectionStatusPanel({ status, onChanged, onEditDeadline }: Props) {
  const { summary, planning } = status
  const [openMember, setOpenMember] = useState<string | null>(null)
  const [confirmingBulk, setConfirmingBulk] = useState(false)
  const [bulkSending, setBulkSending] = useState(false)
  const [bulkResult, setBulkResult] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<Sort>('name')
  // A ref, not state: two clicks in the same tick both read the state as "idle".
  const bulkInFlight = useRef(false)

  const concerned = summary.concernedCount
  const referenceYear = Number(planning.startsAt.slice(0, 4))
  const maxCount = Math.max(1, ...status.members.map((row) => row.unavailabilityCount))

  const rows = useMemo(() => {
    const q = query.trim().toLocaleLowerCase('fr')
    return status.members
      .filter((row) => !q || `${row.firstName} ${row.lastName}`.toLocaleLowerCase('fr').includes(q))
      .sort((a, b) =>
        sort === 'unavailabilities' && b.unavailabilityCount !== a.unavailabilityCount
          ? b.unavailabilityCount - a.unavailabilityCount
          : `${a.lastName} ${a.firstName}`.localeCompare(`${b.lastName} ${b.firstName}`, 'fr'),
      )
  }, [status.members, query, sort])

  async function sendToParticipants() {
    if (bulkInFlight.current) {
      return
    }
    bulkInFlight.current = true
    setBulkSending(true)
    setBulkResult(null)
    try {
      setBulkResult({
        tone: 'success',
        text: bulkMessage(await remindParticipants(planning.stableId, status.period?.stableId)),
      })
      onChanged()
    } catch {
      setBulkResult({ tone: 'error', text: 'Impossible d’envoyer les rappels.' })
    } finally {
      bulkInFlight.current = false
      setBulkSending(false)
      setConfirmingBulk(false)
    }
  }

  return (
    <section className="pd-stack" aria-label="Suivi des indisponibilités">
      <div className="pd-card pd-collecte pd-collecte-open">
        <div className="pd-collecte-head">
          <strong>Indisponibilités des participants</strong>
          {concerned > 0 && <span className="pd-collecte-count">{plural(concerned, 'participant')}</span>}
        </div>
        <p className="pd-help">
          {concerned === 0
            ? 'Aucun participant pour cette période.'
            : `${plural(summary.unavailabilityCount, 'période d’indisponibilité déclarée', 'périodes d’indisponibilité déclarées')} sur cette période.`}
        </p>
        <p className="pd-muted">
          Sans indisponibilité renseignée, un participant est considéré comme disponible : personne n’a rien à
          confirmer, et chacun peut modifier son calendrier à tout moment.
        </p>

        {status.availabilityDeadline && (
          <p className="pd-deadline">
            Date limite indicative : {formatLongDate(status.availabilityDeadline)}
          </p>
        )}
        {status.deadlineOverdueDays !== null && (
          <p className="alert alert--warning" role="status">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>
              {overdueMessage(status.deadlineOverdueDays)} Cette date reste indicative : chacun peut encore
              compléter ses disponibilités.
            </span>
          </p>
        )}

        {confirmingBulk ? (
          <div className="pd-confirm" role="group" aria-label="Confirmer la relance">
            <span>Envoyer un rappel par email à {plural(concerned, 'participant')} ?</span>
            <div className="pd-row-actions">
              <button
                type="button"
                className="pd-btn pd-btn-primary"
                onClick={sendToParticipants}
                disabled={bulkSending}
                aria-busy={bulkSending}
              >
                {bulkSending ? 'Envoi en cours…' : 'Envoyer les rappels'}
              </button>
              <button
                type="button"
                className="pd-btn pd-btn-ghost"
                onClick={() => setConfirmingBulk(false)}
                disabled={bulkSending}
              >
                Annuler
              </button>
            </div>
          </div>
        ) : (
          <div className="pd-row-actions">
            {concerned > 0 && status.openCollectionCount > 0 && (
              <button
                type="button"
                className="pd-btn pd-btn-secondary"
                onClick={() => setConfirmingBulk(true)}
              >
                <Icon name="mail" size={18} strokeWidth={2} />
                Rappeler les participants
              </button>
            )}
            {onEditDeadline && (
              <button type="button" className="pd-btn pd-btn-ghost" onClick={onEditDeadline}>
                <Icon name="calendar" size={18} strokeWidth={2} />
                {status.availabilityDeadline
                  ? 'Modifier la date limite indicative'
                  : 'Fixer une date limite indicative'}
              </button>
            )}
            <AbsenceExportButton
              planningStableId={planning.stableId}
              planningName={planning.name}
              periodStableId={status.period?.stableId}
            />
          </div>
        )}
      </div>

      {bulkResult && (
        <p
          role={bulkResult.tone === 'error' ? 'alert' : 'status'}
          className={`alert ${bulkResult.tone === 'error' ? 'alert--error' : 'alert--success'}`}
        >
          <Icon name={bulkResult.tone === 'error' ? 'alert' : 'check'} size={18} strokeWidth={2} />
          <span>{bulkResult.text}</span>
        </p>
      )}

      {status.members.length > 0 && (
        <div className="pd-toolbar">
          <label className="pd-search">
            <Icon name="search" size={18} strokeWidth={2} />
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Rechercher un membre"
              aria-label="Rechercher un membre"
            />
          </label>
          <div className="pd-segmented" role="group" aria-label="Trier">
            <button type="button" aria-pressed={sort === 'name'} onClick={() => setSort('name')}>
              Nom
            </button>
            <button
              type="button"
              aria-pressed={sort === 'unavailabilities'}
              onClick={() => setSort('unavailabilities')}
            >
              Indisponibilités
            </button>
          </div>
        </div>
      )}

      <ul className="pd-card pd-list" aria-label="Participants">
        {rows.map((row) => {
          return (
            <li
              key={row.memberStableId}
              className="pd-member"
              data-member-row
              data-participant={row.participant}
              onClick={() => setOpenMember(row.memberStableId)}
            >
              <span className="pd-avatar" aria-hidden>
                {`${row.firstName.charAt(0)}${row.lastName.charAt(0)}`.toUpperCase()}
              </span>
              <div className="pd-member-name">
                <button
                  type="button"
                  className="pd-member-open"
                  aria-haspopup="dialog"
                  onClick={(event) => {
                    event.stopPropagation()
                    setOpenMember(row.memberStableId)
                  }}
                >
                  {row.firstName} {row.lastName}
                </button>
                <span>
                  {ROLE_LABEL[row.role]} · {row.team.name} · Calendrier modifié le{' '}
                  <span className="tnum">
                    {row.lastAvailabilityChangeAt
                      ? formatDateOnly(row.lastAvailabilityChangeAt, planning.timezone)
                      : '—'}
                  </span>{' '}
                  · Dernier rappel :{' '}
                  <span className="tnum">
                    {row.lastReminderAt ? formatDateOnly(row.lastReminderAt, planning.timezone) : '—'}
                  </span>
                </span>
              </div>
              <div
                className="pd-member-days"
                title="Périodes d’indisponibilité déclarées qui touchent la période du planning"
              >
                <div className="pd-bar" aria-hidden>
                  <span style={{ width: `${Math.round((row.unavailabilityCount / maxCount) * 100)}%` }} />
                </div>
                <span className="pd-num">{row.unavailabilityCount}</span>
              </div>
              {!row.participant && <span className="tag pd-status">Non concerné</span>}
            </li>
          )
        })}
        {status.members.length === 0 && <li className="pd-empty-row">Aucun membre pour le moment.</li>}
        {status.members.length > 0 && rows.length === 0 && (
          <li className="pd-empty-row">Aucun membre ne correspond à « {query} ».</li>
        )}
      </ul>
      {status.members.length > 0 && (
        <p className="pd-footnote">
          Nombre de périodes d’indisponibilité déclarées qui touchent la période du planning.
        </p>
      )}

      {openMember && (
        <MemberDrawer
          key={openMember}
          planningStableId={planning.stableId}
          memberStableId={openMember}
          periodStableId={status.period?.stableId}
          timezone={planning.timezone}
          referenceYear={referenceYear}
          onClose={() => setOpenMember(null)}
          onReminderSent={onChanged}
        />
      )}

      <PlanningHistoryPanel
        planningStableId={planning.stableId}
        periodStableId={status.period?.stableId}
        timezone={planning.timezone}
      />
    </section>
  )
}

function bulkMessage(result: ParticipantRemindersResult): string {
  if (result.targetedCount === 0) {
    return 'Aucun participant : aucun rappel envoyé.'
  }
  const parts = [`${plural(result.sentCount, 'rappel envoyé', 'rappels envoyés')}`]
  if (result.skippedRecentlyCount > 0) {
    parts.push(`${plural(result.skippedRecentlyCount, 'personne')} déjà relancée à l’instant`)
  }
  if (result.failedCount > 0) {
    parts.push(`${plural(result.failedCount, 'envoi', 'envois')} en échec`)
  }
  return `${parts.join(' · ')}.`
}
