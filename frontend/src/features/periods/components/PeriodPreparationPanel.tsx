import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  fetchPeriodPreparation,
  savePeriodPreparation,
  type DayOption,
  type PeriodPreparation,
  type PreparationEmails,
  type PreparationReminder,
  type ReminderUnit,
} from '../api'
import { formatDate } from '../dates'
import { preparationErrorMessage, preparationSaveError } from '../errors'
import { MAXIMUM, dueOf, freeReminderOffset, noFreeReminderReason } from '../preparationRules'
import type { ISODate } from '../types'
import { monthOf } from '../useDateOptions'
import { BoundaryCalendar } from './BoundaryCalendar'
import { Icon } from './Icon'

interface Props {
  planningStableId: string
  periodStableId: string
}

type DraftReminder = { key: string; stableId?: string; value: number | ''; unit: ReminderUnit }
type Draft = { announceOn: ISODate | null; target: ISODate | null; reminders: DraftReminder[] }
type Picker = 'announce' | 'target' | null

/** What an announcement / reminder that ran or did not run became, for a doctor. */
const OUTCOMES: Record<string, string> = {
  collection_opened: 'collecte ouverte',
  collection_already_open: 'la collecte était déjà ouverte',
  collection_closed: 'la collecte était déjà clôturée',
  manager_not_allowed: 'son auteur n’a plus le droit de gérer les disponibilités',
  generation_started: 'la génération a été lancée',
  period_locked: 'la période est validée ou publiée',
  period_over: 'la période est terminée',
  target_passed: 'la date cible était dépassée',
  superseded: 'remplacé par un rappel plus récent',
  no_open_collection: 'aucune collecte ouverte',
  nobody_pending: 'tout le monde avait confirmé',
  sent_to_pending: 'envoyé aux personnes sans confirmation',
}

let keySeed = 0
const newKey = () => `r${++keySeed}`

function draftFrom(view: PeriodPreparation): Draft {
  return {
    announceOn: view.announcement.on,
    target: view.targetGenerationOn,
    reminders: view.reminders
      .filter((r) => r.state === 'scheduled')
      .map((r) => ({ key: r.stableId, stableId: r.stableId, value: r.value, unit: r.unit })),
  }
}

function sameDraft(a: Draft, b: Draft): boolean {
  return (
    JSON.stringify({ ...a, reminders: a.reminders.map(({ key: _k, ...r }) => r) }) ===
    JSON.stringify({ ...b, reminders: b.reminders.map(({ key: _k, ...r }) => r) })
  )
}

function emailsLabel(e: PreparationEmails): string {
  const parts = [`${e.sent} email${e.sent > 1 ? 's' : ''} envoyé${e.sent > 1 ? 's' : ''}`]
  if (e.pending > 0) parts.push(`${e.pending} en cours`)
  if (e.failed > 0) parts.push(`${e.failed} en échec`)
  return parts.join(' · ')
}

function stateBadge(state: PreparationReminder['state']): { label: string; tone: string } {
  switch (state) {
    case 'scheduled':
      return { label: 'Programmé', tone: 'info' }
    case 'executed':
      return { label: 'Exécuté', tone: 'ok' }
    case 'skipped':
      return { label: 'Non exécuté', tone: 'neutral' }
    case 'cancelled':
      return { label: 'Annulé', tone: 'neutral' }
  }
}

/**
 * « Préparer la période » (docs/decisions.md D210): the announcement (the
 * collection opens by itself and the invitations go out), the target date of
 * the manual generation, and the reminders before it — all at 08:00
 * Europe/Brussels. What already ran is shown as history; only what has not
 * run yet changes. The calendars say why a day is refused, like the period
 * dates (BoundaryCalendar); the server checks the same rules again.
 */
export function PeriodPreparationPanel({ planningStableId, periodStableId }: Props) {
  const [view, setView] = useState<PeriodPreparation | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [draft, setDraft] = useState<Draft>({ announceOn: null, target: null, reminders: [] })
  const [picker, setPicker] = useState<Picker>(null)
  const [month, setMonth] = useState<string>('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    let cancelled = false
    fetchPeriodPreparation(planningStableId, periodStableId)
      .then((loaded) => {
        if (cancelled) return
        setView(loaded)
        setDraft(draftFrom(loaded))
      })
      .catch(() => {
        if (!cancelled) setLoadError('La préparation de la période n’a pas pu être chargée.')
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId, periodStableId])

  const announcementRan =
    view !== null && view.announcement.state !== 'none' && view.announcement.state !== 'scheduled'
  const editable = view?.editable === true
  const history = useMemo(() => view?.reminders.filter((r) => r.state !== 'scheduled') ?? [], [view])
  const dirty = view !== null && !sameDraft(draft, draftFrom(view))

  /** The client-side twin of the server's rules, so a refused day says why before saving. */
  const optionOf = useCallback(
    (which: Exclude<Picker, null>) =>
      (day: ISODate): DayOption => {
        if (!view) return { date: day, allowed: false, reasons: [] }
        const reasons: { code: string }[] = []
        if (day < view.today) reasons.push({ code: 'preparation_past' })
        if (day > view.period.lastDay) reasons.push({ code: 'preparation_after_period' })
        if (which === 'target' && draft.announceOn && day <= draft.announceOn)
          reasons.push({ code: 'preparation_target_not_after_announcement' })
        if (which === 'announce' && draft.target && day >= draft.target)
          reasons.push({ code: 'preparation_announcement_not_before_target' })
        return { date: day, allowed: reasons.length === 0, reasons }
      },
    [view, draft.announceOn, draft.target],
  )

  if (loadError) {
    return (
      <section className="mv-prep mv-card" aria-label="Préparer la période">
        <p role="alert" className="mv-prep__error">
          {loadError}
        </p>
      </section>
    )
  }
  if (!view) {
    return (
      <section className="mv-prep mv-card" aria-label="Préparer la période">
        <p role="status" className="mv-muted">
          Chargement de la préparation…
        </p>
      </section>
    )
  }

  /** The day each reminder would run on, as soon as its offset and the target date are known. */
  const dues = draft.reminders.map((r) =>
    draft.target && r.value !== '' && r.value >= 1 && r.value <= MAXIMUM[r.unit]
      ? dueOf(draft.target, r.value, r.unit)
      : null,
  )
  const scheduledDays = new Map<string, number>()
  const reminderProblems = draft.reminders.map((r, index) => {
    if (r.value === '' || r.value < 1 || r.value > MAXIMUM[r.unit])
      return r.unit === 'DAY' ? 'Entre 1 et 365 jours.' : 'Entre 1 et 52 semaines.'
    if (!draft.target) return preparationErrorMessage('reminders_need_target')
    const due = dues[index] as ISODate
    const unchanged = view.reminders.some((v) => v.stableId === r.stableId && v.dueOn === due)
    if (!unchanged && due < view.today) return preparationErrorMessage('reminder_in_past')
    if (draft.announceOn && due <= draft.announceOn)
      return `${preparationErrorMessage('reminder_before_announcement')} L’annonce est le ${formatDate(draft.announceOn)}.`
    scheduledDays.set(due, (scheduledDays.get(due) ?? 0) + 1)
    return null
  })
  const duplicates = dues.map((due) =>
    due && (scheduledDays.get(due) ?? 0) > 1 ? preparationErrorMessage('duplicate_reminder') : null,
  )
  const problems = draft.reminders
    .map((_, index) => ({ index, message: reminderProblems[index] ?? duplicates[index] }))
    .filter((p): p is { index: number; message: string } => Boolean(p.message))
  const invalid = problems.length > 0

  /** What a new reminder would be, or why none can be added (docs/decisions.md D210). */
  const reminderContext = {
    today: view.today,
    target: draft.target,
    announceOn: draft.announceOn,
    taken: dues.filter((d): d is ISODate => d !== null),
    count: draft.reminders.length,
  }
  const nextOffset = freeReminderOffset(reminderContext)
  const noFreeReminder = noFreeReminderReason(reminderContext)

  function openPicker(which: Exclude<Picker, null>) {
    if (picker === which) {
      setPicker(null)
      return
    }
    const current = which === 'announce' ? draft.announceOn : draft.target
    setMonth(monthOf(current ?? view?.today ?? ''))
    setPicker(which)
  }

  function choose(which: Exclude<Picker, null>, day: ISODate) {
    if (!optionOf(which)(day).allowed) return
    setDraft((d) => (which === 'announce' ? { ...d, announceOn: day } : { ...d, target: day }))
    setPicker(null)
    setSaved(false)
  }

  function updateReminder(key: string, patch: Partial<DraftReminder>) {
    setDraft((d) => ({ ...d, reminders: d.reminders.map((r) => (r.key === key ? { ...r, ...patch } : r)) }))
    setSaved(false)
  }

  async function save() {
    if (!view) return
    setSaving(true)
    setError(null)
    try {
      const result = await savePeriodPreparation(planningStableId, periodStableId, {
        announceOn: draft.announceOn,
        targetGenerationOn: draft.target,
        reminders: draft.reminders.map((r) => ({
          ...(r.stableId ? { stableId: r.stableId } : {}),
          value: Number(r.value),
          unit: r.unit,
        })),
      })
      setView(result)
      setDraft(draftFrom(result))
      setSaved(true)
    } catch (err) {
      setError(preparationSaveError(err))
    } finally {
      setSaving(false)
    }
  }

  const a = view.announcement
  const collection = view.collection

  return (
    <section className="mv-prep mv-card" aria-labelledby="mv-prep-title">
      <header className="mv-prep__head">
        <h2 id="mv-prep-title">Préparer la période {view.period.number}</h2>
        <p className="mv-muted mv-small">
          Du {formatDate(view.period.startsAt)} au {formatDate(view.period.lastDay)} · annonce et rappels
          envoyés à {view.sendTime.replace(':', ' h ')} (heure de Bruxelles)
        </p>
      </header>

      {!editable && (
        <p className="mv-prep__notice" role="note">
          <Icon name="lock" size={16} />
          <span>{preparationErrorMessage(view.blockers[0] ?? '')}</span>
        </p>
      )}

      <div className="mv-prep__rows">
        {/* --- Annonce ------------------------------------------------------------------------- */}
        <div className="mv-prep__row">
          <div className="mv-prep__label">
            <strong>Annonce et ouverture de la collecte</strong>
            <span className="mv-muted mv-small">
              La collecte s’ouvre et les participants reçoivent une invitation.
            </span>
          </div>
          <div className="mv-prep__value">
            {announcementRan ? (
              <>
                <span className="mv-prep__date">{a.on ? formatDate(a.on) : '—'}</span>
                <span className={`mv-badge mv-badge--${a.state === 'executed' ? 'ok' : 'neutral'}`}>
                  {a.state === 'executed' ? 'Faite' : a.state === 'cancelled' ? 'Annulée' : 'Non exécutée'}
                </span>
                <span className="mv-muted mv-small">
                  {[
                    a.outcome ? (OUTCOMES[a.outcome] ?? a.outcome) : null,
                    a.state === 'executed' ? emailsLabel(a.emails) : null,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </span>
              </>
            ) : (
              <>
                <span className="mv-prep__date">
                  {draft.announceOn ? formatDate(draft.announceOn) : 'Non programmée'}
                </span>
                {draft.announceOn && <span className="mv-badge mv-badge--info">Programmée</span>}
                {editable && (
                  <span className="mv-prep__actions">
                    <button
                      type="button"
                      className="mv-btn mv-btn--secondary mv-btn--sm"
                      aria-expanded={picker === 'announce'}
                      onClick={() => openPicker('announce')}
                    >
                      {draft.announceOn ? 'Changer la date' : 'Choisir la date'}
                    </button>
                    {draft.announceOn && (
                      <button
                        type="button"
                        className="mv-btn mv-btn--ghost mv-btn--sm"
                        onClick={() => {
                          setDraft((d) => ({ ...d, announceOn: null }))
                          setSaved(false)
                        }}
                      >
                        Annuler l’annonce
                      </button>
                    )}
                  </span>
                )}
              </>
            )}
          </div>
          {picker === 'announce' && (
            <BoundaryCalendar
              month={month}
              onMonthChange={setMonth}
              label="Date d’annonce"
              selected={draft.announceOn ?? ''}
              current={view.announcement.on}
              optionOf={optionOf('announce')}
              onSelect={(day) => choose('announce', day)}
            />
          )}
        </div>

        {/* --- État de la collecte ------------------------------------------------------------- */}
        <div className="mv-prep__row">
          <div className="mv-prep__label">
            <strong>Collecte des indisponibilités</strong>
          </div>
          <div className="mv-prep__value">
            {collection.state === 'open' ? (
              <>
                <span className="mv-badge mv-badge--warn">Ouverte</span>
                <span className="mv-muted mv-small">
                  {collection.responded} / {collection.expected} confirmation
                  {collection.expected > 1 ? 's' : ''}
                </span>
              </>
            ) : collection.state === 'closed' ? (
              <span className="mv-badge mv-badge--neutral">Clôturée</span>
            ) : (
              <span className="mv-badge mv-badge--neutral">Pas encore ouverte</span>
            )}
          </div>
        </div>

        {/* --- Date cible ---------------------------------------------------------------------- */}
        <div className="mv-prep__row">
          <div className="mv-prep__label">
            <strong>Date cible de génération</strong>
            <span className="mv-muted mv-small">
              Sert à organiser la préparation et à placer les rappels. La génération reste manuelle ; la
              collecte n’est pas fermée à cette date.
            </span>
          </div>
          <div className="mv-prep__value">
            <span className="mv-prep__date">{draft.target ? formatDate(draft.target) : 'Non définie'}</span>
            {editable && (
              <span className="mv-prep__actions">
                <button
                  type="button"
                  className="mv-btn mv-btn--secondary mv-btn--sm"
                  aria-expanded={picker === 'target'}
                  onClick={() => openPicker('target')}
                >
                  {draft.target ? 'Changer la date' : 'Choisir la date'}
                </button>
              </span>
            )}
          </div>
          {picker === 'target' && (
            <BoundaryCalendar
              month={month}
              onMonthChange={setMonth}
              label="Date cible de génération"
              selected={draft.target ?? ''}
              current={view.targetGenerationOn}
              optionOf={optionOf('target')}
              onSelect={(day) => choose('target', day)}
            />
          )}
        </div>

        {/* --- Rappels ------------------------------------------------------------------------- */}
        <div className="mv-prep__row">
          <div className="mv-prep__label">
            <strong>Rappels</strong>
            <span className="mv-muted mv-small">
              Envoyés uniquement aux personnes qui n’ont pas encore confirmé.
            </span>
          </div>
          <ol className="mv-prep__reminders" aria-label="Rappels programmés">
            {draft.reminders.map((r, index) => {
              const problem = reminderProblems[index] ?? duplicates[index]
              const due = dues[index]
              const hintId = `mv-prep-reminder-${r.key}`
              return (
                <li key={r.key} className="mv-prep__reminder">
                  <span className="mv-prep__reminder-name">Rappel {index + 1}</span>
                  <input
                    className={`mv-input mv-prep__number ${problem ? 'is-error' : ''}`}
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={MAXIMUM[r.unit]}
                    value={r.value}
                    aria-label={`Rappel ${index + 1} : combien avant la date cible`}
                    aria-invalid={problem ? true : undefined}
                    aria-describedby={hintId}
                    disabled={!editable}
                    onChange={(event) =>
                      updateReminder(r.key, {
                        value: event.target.value === '' ? '' : Number(event.target.value),
                      })
                    }
                  />
                  <select
                    className="mv-input mv-prep__unit"
                    value={r.unit}
                    aria-label={`Rappel ${index + 1} : unité`}
                    disabled={!editable}
                    onChange={(event) => updateReminder(r.key, { unit: event.target.value as ReminderUnit })}
                  >
                    <option value="DAY">jour(s) avant</option>
                    <option value="WEEK">semaine(s) avant</option>
                  </select>
                  {editable && (
                    <button
                      type="button"
                      className="mv-icon-btn mv-icon-btn--soft"
                      aria-label={`Supprimer le rappel ${index + 1}`}
                      onClick={() => {
                        setDraft((d) => ({ ...d, reminders: d.reminders.filter((x) => x.key !== r.key) }))
                        setSaved(false)
                      }}
                    >
                      <Icon name="close" size={16} />
                    </button>
                  )}
                  <span id={hintId} className="mv-prep__due">
                    {due && <span className="mv-field__hint">Le {formatDate(due)} à 8 h</span>}
                    {problem && <span className="mv-field__hint is-error">{problem}</span>}
                  </span>
                </li>
              )
            })}
          </ol>
          {editable && (
            <div className="mv-prep__add">
              <button
                type="button"
                className="mv-btn mv-btn--secondary mv-btn--sm"
                disabled={nextOffset === null}
                aria-describedby={noFreeReminder ? 'mv-prep-add-why' : undefined}
                onClick={() => {
                  if (nextOffset === null) return
                  setDraft((d) => ({
                    ...d,
                    reminders: [...d.reminders, { key: newKey(), value: nextOffset, unit: 'DAY' }],
                  }))
                  setSaved(false)
                }}
              >
                <Icon name="plus" size={16} /> Ajouter un rappel
              </button>
              {noFreeReminder && (
                <span id="mv-prep-add-why" className="mv-muted mv-small">
                  {noFreeReminder === 'no_free_reminder_day' && draft.target
                    ? `Aucun jour libre pour un nouveau rappel : il doit tomber ${
                        draft.announceOn
                          ? `après l’annonce (${formatDate(draft.announceOn)})`
                          : 'à partir d’aujourd’hui'
                      } et avant la date cible (${formatDate(draft.target)}), sans tomber le même jour qu’un autre rappel.`
                    : preparationErrorMessage(noFreeReminder)}
                </span>
              )}
            </div>
          )}
          {history.length > 0 && (
            <ul className="mv-prep__history" aria-label="Rappels passés">
              {history.map((r) => {
                const badge = stateBadge(r.state)
                return (
                  <li key={r.stableId}>
                    <span>
                      {r.value}{' '}
                      {r.unit === 'DAY'
                        ? `jour${r.value > 1 ? 's' : ''}`
                        : `semaine${r.value > 1 ? 's' : ''}`}{' '}
                      avant · {formatDate(r.dueOn)}
                    </span>
                    <span className={`mv-badge mv-badge--${badge.tone}`}>{badge.label}</span>
                    <span className="mv-muted mv-small">
                      {[
                        r.outcome ? (OUTCOMES[r.outcome] ?? r.outcome) : null,
                        r.state === 'executed' ? emailsLabel(r.emails) : null,
                      ]
                        .filter(Boolean)
                        .join(' · ')}
                    </span>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      </div>

      {editable && (
        <footer className="mv-prep__foot">
          {error && (
            <p role="alert" className="mv-prep__error">
              {error}
            </p>
          )}
          <div id="mv-prep-state" aria-live="polite">
            {saved && !dirty ? (
              <p className="mv-prep__saved">Préparation enregistrée.</p>
            ) : !dirty ? (
              <p className="mv-prep__idle">Aucune modification à enregistrer.</p>
            ) : invalid ? (
              <div className="mv-prep__invalid">
                <strong>Enregistrement impossible, à corriger d’abord :</strong>
                <ul>
                  {problems.map((p) => (
                    <li key={p.index}>
                      Rappel {p.index + 1}
                      {dues[p.index] ? ` (le ${formatDate(dues[p.index] as ISODate)})` : ''} : {p.message}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
          </div>
          <div className="mv-prep__buttons">
            <button
              type="button"
              className="mv-btn mv-btn--ghost"
              disabled={!dirty || saving}
              onClick={() => {
                setDraft(draftFrom(view))
                setError(null)
                setPicker(null)
              }}
            >
              Annuler les modifications
            </button>
            <button
              type="button"
              className="mv-btn mv-btn--primary"
              disabled={!dirty || invalid || saving}
              aria-busy={saving}
              aria-describedby="mv-prep-state"
              onClick={() => void save()}
            >
              {saving ? 'Enregistrement…' : 'Enregistrer la préparation'}
            </button>
          </div>
        </footer>
      )}
    </section>
  )
}
