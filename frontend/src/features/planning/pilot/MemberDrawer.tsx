import { useCallback, useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchMemberStatus, remindMember } from './api'
import { formatDateOnly, formatDateTime, formatPeriod } from './format'
import { COLLECTION_STATE_TAG, ROLE_LABEL } from './labels'
import type { MemberPeriod, MemberStatusDetail } from './types'

type Props = {
  planningStableId: string
  memberStableId: string
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
 * identity and participation, where they stand in the collection, their
 * unavailabilities *limited to the planning period* — read from their own
 * calendar, never copied per planning — their preferences kept apart, and
 * the reminder history with "Envoyer un rappel".
 */
export function MemberDrawer({
  planningStableId,
  memberStableId,
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
      fetchMemberStatus(planningStableId, memberStableId)
        .then((result) => {
          setDetail(result)
          setError(null)
        })
        .catch(() => setError('Impossible de charger ce membre.')),
    [planningStableId, memberStableId],
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
      await remindMember(planningStableId, memberStableId)
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
  const canRemind = member?.collectionState === 'PENDING'

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
              État de la collecte
            </h3>
            <p>
              <span className={`tag ${COLLECTION_STATE_TAG[member.collectionState].tone}`}>
                {COLLECTION_STATE_TAG[member.collectionState].label}
              </span>
            </p>
            {member.collectionState === 'ACKNOWLEDGED' && member.acknowledgedAt && (
              <p>
                Dernière confirmation : {formatDateTime(member.acknowledgedAt, timezone)}
                {member.acknowledgementKind === 'NO_UNAVAILABILITY' &&
                  ' — « Je n’ai aucune indisponibilité »'}
              </p>
            )}
            {member.collectionState === 'PENDING' && (
              <>
                <p>Aucune confirmation reçue.</p>
                <p className="muted drawer-note">
                  Une absence d’indisponibilité enregistrée ne vaut pas confirmation : seul le membre peut
                  confirmer que ses disponibilités sont à jour.
                </p>
              </>
            )}
            {member.lastAvailabilityChangeAt && (
              <p className="muted drawer-note">
                Calendrier modifié le {formatDateTime(member.lastAvailabilityChangeAt, timezone)}.
              </p>
            )}
            {detail.collections.length > 1 && (
              <ul className="drawer-list">
                {detail.collections.map((response) => (
                  <li key={response.collectionStableId}>
                    {response.startsAt} → {response.lastDay} :{' '}
                    {response.status === 'ACKNOWLEDGED' && response.acknowledgedAt
                      ? `confirmé le ${formatDateOnly(response.acknowledgedAt, timezone)}`
                      : response.status === 'WITHDRAWN'
                        ? 'a quitté le planning'
                        : 'en attente'}
                  </li>
                ))}
              </ul>
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
