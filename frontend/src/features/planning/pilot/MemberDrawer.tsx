import { useCallback, useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchMemberStatus, remindMember } from './api'
import { formatDateTime, formatPeriod } from './format'
import { ROLE_LABEL } from './labels'
import { PlanningHistoryPanel } from './PlanningHistoryPanel'
import type { MemberPeriod, MemberStatusDetail } from './types'

type Props = {
  planningStableId: string
  memberStableId: string
  /** The period followed (docs/planning-cycles.md §7.4): its collections, its reminders. */
  periodStableId?: string
  timezone: string
  /** Periods show their year only when it differs from this one (the planning's first year). */
  referenceYear: number
  onClose: () => void
  /** A reminder was sent: the list behind must refresh its "Dernier rappel". */
  onReminderSent: () => void
}

type Feedback = { tone: 'success' | 'error'; text: string }

/**
 * The side panel of one participant (docs/availability-collection.md §15):
 * identity and participation, whether announcements and reminders reach them, their
 * unavailabilities *limited to the planning period* — read from their own
 * calendar, never copied per planning — their preferences kept apart, and
 * the reminder history with "Envoyer un rappel", and their own history of the
 * period (docs/decisions.md D217).
 */
export function MemberDrawer({
  planningStableId,
  memberStableId,
  periodStableId,
  timezone,
  referenceYear,
  onClose,
  onReminderSent,
}: Props) {
  const [detail, setDetail] = useState<MemberStatusDetail | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)
  const [feedback, setFeedback] = useState<Feedback | null>(null)
  // A ref, not state: two clicks in the same tick both read the state as "idle".
  const inFlight = useRef(false)

  const load = useCallback(
    () =>
      fetchMemberStatus(planningStableId, memberStableId, periodStableId)
        .then((result) => {
          setDetail(result)
          setError(null)
        })
        .catch(() => setError('Impossible de charger ce membre.')),
    [planningStableId, memberStableId, periodStableId],
  )

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  async function sendReminder() {
    if (inFlight.current) {
      return
    }
    inFlight.current = true
    setSending(true)
    setFeedback(null)
    try {
      await remindMember(planningStableId, memberStableId, periodStableId)
      setFeedback({ tone: 'success', text: 'Rappel envoyé.' })
      await load()
      onReminderSent()
    } catch (err) {
      setFeedback({ tone: 'error', text: reminderErrorMessage(err) })
    } finally {
      inFlight.current = false
      setSending(false)
    }
  }

  const member = detail?.member
  const title = member ? `${member.firstName} ${member.lastName}` : 'Membre'
  // docs/decisions.md D214: every participant may be reminded, whatever their calendar holds.
  const canRemind = member?.participant === true

  return (
    <Overlay title={title} variant="drawer" onClose={onClose}>
      {!detail && !error && (
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

      {detail && member && (
        <div className="drawer-sections">
          <section aria-labelledby="drawer-identity">
            <h3 id="drawer-identity" className="drawer-section__title">
              Identité et participation
            </h3>
            <dl className="drawer-facts">
              <div>
                <dt>Prénom</dt>
                <dd>{member.firstName}</dd>
              </div>
              <div>
                <dt>Nom</dt>
                <dd>{member.lastName}</dd>
              </div>
              <div>
                <dt>Rôle</dt>
                <dd>{ROLE_LABEL[member.role]}</dd>
              </div>
              <div>
                <dt>Équipe</dt>
                <dd>{member.team.name}</dd>
              </div>
              <div>
                <dt>Participation</dt>
                <dd>{participationText(detail)}</dd>
              </div>
            </dl>
            {detail.participation.nonParticipationPeriods.length > 0 && (
              <p className="muted drawer-note">
                Non-participation administrative :{' '}
                {detail.participation.nonParticipationPeriods
                  .map((period) => formatPeriod(period.startsAt, period.endsAt, timezone, referenceYear))
                  .join(' · ')}
                .
              </p>
            )}
          </section>

          <section aria-labelledby="drawer-collection">
            <h3 id="drawer-collection" className="drawer-section__title">
              Calendrier
            </h3>
            {member.participant ? (
              <p>Participe à la période : reçoit l’annonce et les rappels.</p>
            ) : (
              <p>
                <span className="tag">Non concerné</span> — ne reçoit ni l’annonce ni les rappels.
              </p>
            )}
            <p className="muted drawer-note">
              Sans indisponibilité renseignée, ce membre est considéré comme disponible. Rien à confirmer.
            </p>
            {member.lastAvailabilityChangeAt && (
              <p className="muted drawer-note">
                Calendrier modifié le {formatDateTime(member.lastAvailabilityChangeAt, timezone)}.
              </p>
            )}
          </section>

          <section aria-labelledby="drawer-unavailabilities">
            <h3 id="drawer-unavailabilities" className="drawer-section__title">
              Indisponibilités ({detail.unavailabilities.length})
            </h3>
            <PeriodList
              periods={detail.unavailabilities}
              timezone={timezone}
              referenceYear={referenceYear}
              empty="Aucune indisponibilité enregistrée sur cette période."
            />
          </section>

          {detail.preferences.length > 0 && (
            <section aria-labelledby="drawer-preferences">
              <h3 id="drawer-preferences" className="drawer-section__title">
                Préférences de garde
              </h3>
              <PeriodList
                periods={detail.preferences}
                timezone={timezone}
                referenceYear={referenceYear}
                empty=""
              />
            </section>
          )}

          <section aria-labelledby="drawer-reminders">
            <h3 id="drawer-reminders" className="drawer-section__title">
              Rappels
            </h3>
            <p>
              {member.lastReminderAt
                ? `Dernier rappel : ${formatDateTime(member.lastReminderAt, timezone)}`
                : 'Aucun rappel envoyé.'}
            </p>
            {canRemind && (
              <button
                type="button"
                className="btn btn--primary"
                onClick={sendReminder}
                disabled={sending}
                aria-busy={sending}
              >
                <Icon name={sending ? 'loader' : 'mail'} size={18} strokeWidth={2} />
                {sending ? 'Envoi en cours…' : 'Envoyer un rappel'}
              </button>
            )}
            {feedback && (
              <p
                role={feedback.tone === 'error' ? 'alert' : 'status'}
                className={`alert ${feedback.tone === 'error' ? 'alert--error' : 'alert--success'}`}
              >
                <Icon name={feedback.tone === 'error' ? 'alert' : 'check'} size={18} strokeWidth={2} />
                <span>{feedback.text}</span>
              </p>
            )}
            {detail.reminders.length > 0 && (
              <details className="drawer-history">
                <summary>Historique des rappels ({detail.reminders.length})</summary>
                <ul className="drawer-list">
                  {detail.reminders.map((reminder) => (
                    <li key={reminder.stableId}>
                      {formatDateTime(reminder.sentAt, timezone)} — par {reminder.sentByName}
                      {reminder.bulk && ' (relance groupée)'}
                    </li>
                  ))}
                </ul>
              </details>
            )}
          </section>

          <PlanningHistoryPanel
            planningStableId={planningStableId}
            periodStableId={periodStableId}
            memberStableId={memberStableId}
            timezone={timezone}
            compact
          />
        </div>
      )}
    </Overlay>
  )
}

function PeriodList({
  periods,
  timezone,
  referenceYear,
  empty,
}: {
  periods: MemberPeriod[]
  timezone: string
  referenceYear: number
  empty: string
}) {
  if (periods.length === 0) {
    return empty ? <p className="muted">{empty}</p> : null
  }
  return (
    <ul className="drawer-list drawer-periods">
      {periods.map((period) => (
        <li key={period.stableId} className="tnum">
          {formatPeriod(period.startsAt, period.endsAt, timezone, referenceYear)}
        </li>
      ))}
    </ul>
  )
}

function participationText(detail: MemberStatusDetail): string {
  const { membershipStart, membershipEnd, participationPeriods } = detail.participation
  const parts = [membershipEnd ? `du ${membershipStart} au ${membershipEnd}` : `depuis le ${membershipStart}`]
  const reduced = participationPeriods.filter((period) => Math.abs(period.participationFactor - 1) > 1e-9)
  if (reduced.length > 0) {
    parts.push(
      `facteur ${reduced.map((period) => String(period.participationFactor).replace('.', ',')).join(' / ')}`,
    )
  }
  return parts.join(' · ')
}

function reminderErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    const code = (err.body as { error?: string } | null)?.error
    if (code === 'reminder_recently_sent') {
      return 'Un rappel vient d’être envoyé à cette personne. Réessayez dans quelques minutes.'
    }
    if (code === 'nothing_to_remind') {
      return 'Cette personne n’a plus rien à confirmer : aucun rappel n’est nécessaire.'
    }
    if (err.status === 502) {
      return 'L’email n’a pas pu être envoyé. Aucun rappel n’a été enregistré.'
    }
  }
  return 'Impossible d’envoyer le rappel.'
}
