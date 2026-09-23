import { useRef, useState } from 'react'
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
}

/**
 * The OWNER/ADMIN follow-up of the availability collection
 * (docs/availability-collection.md §15): the "X / Y ont confirmé" summary, the
 * (informative) deadline, "Relancer les membres en attente", and one row per
 * participant — click a row for the drawer. Nothing here is ever disabled by
 * the deadline: it only ever raises a visual warning.
 */
export function CollectionStatusPanel({ status, onChanged }: Props) {
  const { summary, planning } = status
  const [openMember, setOpenMember] = useState<string | null>(null)
  const [confirmingBulk, setConfirmingBulk] = useState(false)
  const [bulkSending, setBulkSending] = useState(false)
  const [bulkResult, setBulkResult] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  // A ref, not state: two clicks in the same tick both read the state as "idle".
  const bulkInFlight = useRef(false)

  const expected = summary.expectedCount
  const progressWidth = expected === 0 ? 0 : (summary.confirmedCount / expected) * 100
  const referenceYear = Number(planning.startsAt.slice(0, 4))

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
    <section className="card pilot-panel" aria-label="Suivi de la collecte des disponibilités">
      <div className="section-title">
        <h2>Collecte des disponibilités</h2>
      </div>

      <p className="pilot-panel__summary">
        {expected === 0
          ? 'Aucun membre n’est attendu pour cette collecte.'
          : `${summary.confirmedCount} / ${expected} membres ont confirmé leurs disponibilités.`}
      </p>
      {expected > 0 && (
        <>
          <div
            className="progress"
            role="progressbar"
            aria-label="Avancement des confirmations"
            aria-valuemin={0}
            aria-valuemax={expected}
            aria-valuenow={summary.confirmedCount}
          >
            <span className="progress__bar" style={{ width: `${progressWidth}%` }} />
          </div>
          <p className="muted pilot-panel__remaining">
            {summary.pendingCount === 0
              ? 'Tout le monde a confirmé.'
              : `Il reste ${plural(summary.pendingCount, 'membre')} en attente.`}
          </p>
        </>
      )}

      {status.availabilityDeadline && (
        <p className="pilot-panel__deadline">
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

      {summary.pendingCount > 0 && (
        <div className="pilot-panel__bulk">
          {!confirmingBulk ? (
            <button type="button" className="btn btn--secondary" onClick={() => setConfirmingBulk(true)}>
              <Icon name="mail" size={18} strokeWidth={2} />
              Relancer les membres en attente
            </button>
          ) : (
            <div className="pilot-panel__confirm" role="group" aria-label="Confirmer la relance">
              <span>Envoyer un rappel par email à {plural(summary.pendingCount, 'membre')} en attente ?</span>
              <div className="form-actions">
                <button
                  type="button"
                  className="btn btn--primary btn--sm"
                  onClick={sendToPending}
                  disabled={bulkSending}
                  aria-busy={bulkSending}
                >
                  {bulkSending ? 'Envoi en cours…' : 'Envoyer les rappels'}
                </button>
                <button
                  type="button"
                  className="btn btn--secondary btn--sm"
                  onClick={() => setConfirmingBulk(false)}
                  disabled={bulkSending}
                >
                  Annuler
                </button>
              </div>
            </div>
          )}
        </div>
      )}
      {bulkResult && (
        <p
          role={bulkResult.tone === 'error' ? 'alert' : 'status'}
          className={`alert ${bulkResult.tone === 'error' ? 'alert--error' : 'alert--success'}`}
        >
          <Icon name={bulkResult.tone === 'error' ? 'alert' : 'check'} size={18} strokeWidth={2} />
          <span>{bulkResult.text}</span>
        </p>
      )}

      <div className="pilot-table__wrap">
        <table className="pilot-table">
          <thead>
            <tr>
              <th scope="col">Membre</th>
              <th scope="col">Collecte</th>
              <th scope="col" className="pilot-table__num">
                Indisponibilités
              </th>
              <th scope="col">Dernier rappel</th>
            </tr>
          </thead>
          <tbody>
            {status.members.map((row) => {
              const tag = COLLECTION_STATE_TAG[row.collectionState]
              return (
                <tr
                  key={row.memberStableId}
                  className="pilot-table__row"
                  data-state={row.collectionState}
                  onClick={() => setOpenMember(row.memberStableId)}
                >
                  <td data-label="Membre">
                    <button
                      type="button"
                      className="pilot-table__name"
                      aria-haspopup="dialog"
                      onClick={(event) => {
                        event.stopPropagation()
                        setOpenMember(row.memberStableId)
                      }}
                    >
                      {row.firstName} {row.lastName}
                    </button>
                    <span className="pilot-table__role muted">{ROLE_LABEL[row.role]}</span>
                  </td>
                  <td data-label="Collecte">
                    <span className={`tag ${tag.tone}`}>{tag.label}</span>
                  </td>
                  <td data-label="Indisponibilités" className="pilot-table__num tnum">
                    {row.unavailabilityCount}
                  </td>
                  <td data-label="Dernier rappel" className="tnum">
                    {row.lastReminderAt ? formatDateOnly(row.lastReminderAt, planning.timezone) : '—'}
                  </td>
                </tr>
              )
            })}
            {status.members.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">
                  Aucun membre pour le moment.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

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
