import { useMemo, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { remindPendingMembers } from './api'
import { formatDateOnly, formatLongDate, overdueMessage, plural } from './format'
import { COLLECTION_STATE_TAG, ROLE_LABEL } from './labels'
import { MemberDrawer } from './MemberDrawer'
import type { CollectionStatus, PendingRemindersResult } from './types'

type Props = {
  status: CollectionStatus
  /** Refresh the pilot data (a reminder was sent). */
  onChanged: () => void
  /** Opens the planning settings, where the (informative) deadline is set. */
  onEditDeadline?: () => void
}

type Sort = 'name' | 'unavailabilities'

/**
 * The OWNER/ADMIN follow-up of the availability collection
 * (docs/availability-collection.md §15), laid out as the "Indisponibilités" tab
 * of docs/Design/react_planning_detail: the "X / Y ont confirmé" summary, the
 * (informative) deadline, "Relancer les membres en attente", then one row per
 * participant — searchable, sortable, click a row for the drawer. Nothing here
 * is ever disabled by the deadline: it only ever raises a visual warning.
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

  const expected = summary.expectedCount
  const progressWidth = expected === 0 ? 0 : (summary.confirmedCount / expected) * 100
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

  async function sendToPending() {
    if (bulkInFlight.current) {
      return
    }
    bulkInFlight.current = true
    setBulkSending(true)
    setBulkResult(null)
    try {
      setBulkResult({ tone: 'success', text: bulkMessage(await remindPendingMembers(planning.stableId)) })
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
    <section className="pd-stack" aria-label="Suivi de la collecte des disponibilités">
      <div className="pd-card pd-collecte pd-collecte-open">
        <div className="pd-collecte-head">
          <strong>Collecte des disponibilités</strong>
          {expected > 0 && (
            <span className="pd-collecte-count">
              {summary.confirmedCount}/{expected} confirmés
            </span>
          )}
        </div>
        <p className="pd-help">
          {expected === 0
            ? 'Aucun membre n’est attendu pour cette collecte.'
            : `${summary.confirmedCount} / ${expected} membres ont confirmé leurs disponibilités.`}
        </p>
        {expected > 0 && (
          <>
            <div
              className="pd-progress"
              role="progressbar"
              aria-label="Avancement des confirmations"
              aria-valuemin={0}
              aria-valuemax={expected}
              aria-valuenow={summary.confirmedCount}
            >
              <span style={{ width: `${progressWidth}%` }} />
            </div>
            <p className="pd-muted">
              {summary.pendingCount === 0
                ? 'Tout le monde a confirmé.'
                : `Il reste ${plural(summary.pendingCount, 'membre')} en attente.`}
            </p>
          </>
        )}

        {status.availabilityDeadline && (
          <p className="pd-deadline">
            Fin souhaitée d’encodage : {formatLongDate(status.availabilityDeadline)}
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
            <span>Envoyer un rappel par email à {plural(summary.pendingCount, 'membre')} en attente ?</span>
            <div className="pd-row-actions">
              <button
                type="button"
                className="pd-btn pd-btn-primary"
                onClick={sendToPending}
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
          (summary.pendingCount > 0 || onEditDeadline) && (
            <div className="pd-row-actions">
              {summary.pendingCount > 0 && (
                <button
                  type="button"
                  className="pd-btn pd-btn-secondary"
                  onClick={() => setConfirmingBulk(true)}
                >
                  <Icon name="mail" size={18} strokeWidth={2} />
                  Relancer les membres en attente
                </button>
              )}
              {onEditDeadline && (
                <button type="button" className="pd-btn pd-btn-ghost" onClick={onEditDeadline}>
                  <Icon name="calendar" size={18} strokeWidth={2} />
                  {status.availabilityDeadline ? 'Modifier la date souhaitée' : 'Fixer une date souhaitée'}
                </button>
              )}
            </div>
          )
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
              Indispos
            </button>
          </div>
        </div>
      )}

      <ul className="pd-card pd-list" aria-label="Membres attendus">
        {rows.map((row) => {
          const tag = COLLECTION_STATE_TAG[row.collectionState]
          return (
            <li
              key={row.memberStableId}
              className="pd-member"
              data-member-row
              data-state={row.collectionState}
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
                  {ROLE_LABEL[row.role]} · {row.team.name} · Dernier rappel :{' '}
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
              <span className={`tag ${tag.tone} pd-status`}>{tag.label}</span>
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
          timezone={planning.timezone}
          referenceYear={referenceYear}
          onClose={() => setOpenMember(null)}
          onReminderSent={onChanged}
        />
      )}
    </section>
  )
}

function bulkMessage(result: PendingRemindersResult): string {
  if (result.targetedCount === 0) {
    return 'Personne n’est en attente : aucun rappel envoyé.'
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
