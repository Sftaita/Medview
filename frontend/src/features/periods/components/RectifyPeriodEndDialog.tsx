import { useEffect, useRef, useState } from 'react'
import type { EndRectificationOptions } from '../api'
import { addDays, formatDate } from '../dates'
import { dateReasonMessage } from '../errors'
import type { ISODate, Period } from '../types'
import { Icon } from './Icon'

interface Props {
  open: boolean
  period: Period
  next: Period
  /** What the end may be brought back to (docs/decisions.md D211). */
  loadOptions: () => Promise<EndRectificationOptions>
  /** Rectifies to this last day (inclusive), with the reason; rejects with a message to show. */
  onConfirm: (lastDay: ISODate, reason: string) => Promise<void>
  onCancel: () => void
}

/**
 * « Corriger la fin de la période » — the exceptional correction of a
 * published period's end (docs/decisions.md D211). Only days after the last
 * duty, and only a boundary that cuts no block: the occurrence the old
 * boundary cut goes whole into the next period. Nothing of the published
 * calendar changes; the manager confirms explicitly and says why, and the
 * correction is recorded.
 */
export function RectifyPeriodEndDialog({ open, period, next, loadOptions, onConfirm, onCancel }: Props) {
  const [options, setOptions] = useState<EndRectificationOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [lastDay, setLastDay] = useState<ISODate | ''>('')
  const [reason, setReason] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const dialogRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    let cancelled = false
    dialogRef.current?.focus()
    loadOptions()
      .then((loaded) => {
        if (cancelled) return
        setOptions(loaded)
        setLastDay(loaded.proposedLastDay ?? '')
      })
      .catch(() => {
        if (!cancelled) setLoadError('Les dates possibles n’ont pas pu être chargées.')
      })
    return () => {
      cancelled = true
    }
  }, [open, loadOptions])

  if (!open) return null

  const close = () => {
    if (!saving) onCancel()
  }
  const max = options?.reasonMaxLength ?? 500
  const canConfirm =
    options?.eligible === true &&
    lastDay !== '' &&
    reason.trim().length > 0 &&
    reason.length <= max &&
    confirmed &&
    !saving

  async function confirm() {
    if (!canConfirm || lastDay === '') return
    setSaving(true)
    setError(null)
    try {
      await onConfirm(lastDay, reason.trim())
    } catch (err) {
      setError(err instanceof Error ? err.message : 'La correction n’a pas pu être enregistrée.')
      setSaving(false)
    }
  }

  return (
    <div className="mv-overlay" onMouseDown={close}>
      <div
        ref={dialogRef}
        className="mv-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mv-rectify-title"
        tabIndex={-1}
        onMouseDown={(e) => e.stopPropagation()}
        onKeyDown={(e) => {
          if (e.key === 'Escape') close()
        }}
      >
        <span className="mv-dialog__handle" aria-hidden="true" />
        <header className="mv-dialog__head">
          <div>
            <h2 id="mv-rectify-title">Corriger la fin de la période</h2>
            <p>Période {period.number} · correction exceptionnelle d’une période publiée</p>
          </div>
          <button
            type="button"
            className="mv-icon-btn mv-icon-btn--soft"
            aria-label="Fermer"
            onClick={close}
            disabled={saving}
          >
            <Icon name="close" size={20} />
          </button>
        </header>

        <div className="mv-dialog__body">
          {loadError && (
            <p role="alert" className="mv-rectify__error">
              {loadError}
            </p>
          )}
          {!options && !loadError && (
            <p role="status" className="mv-muted">
              Chargement…
            </p>
          )}
          {options && (
            <div className="mv-stack">
              <dl className="mv-rectify__facts">
                <div>
                  <dt>Dates actuelles</dt>
                  <dd>
                    du {formatDate(options.startsAt, 'card')} au {formatDate(options.currentLastDay, 'card')}
                  </dd>
                </div>
                <div>
                  <dt>Dernière garde de la période</dt>
                  <dd>{options.lastDutyDay ? formatDate(options.lastDutyDay, 'card') : 'aucune'}</dd>
                </div>
                <div>
                  <dt>Période {next.number}</dt>
                  <dd>commence aujourd’hui le {formatDate(next.start, 'card')}</dd>
                </div>
              </dl>

              {!options.eligible ? (
                <p className="mv-rectify__notice" role="note">
                  <Icon name="lock" size={16} />
                  <span>
                    {options.blockers.length > 0
                      ? options.blockers.map(dateReasonMessage).join(' ')
                      : 'Aucune nouvelle fin n’est possible : chaque jour plus tôt a une garde ou couperait un bloc.'}
                  </span>
                </p>
              ) : (
                <>
                  <fieldset className="mv-rectify__days">
                    <legend className="mv-field__label">
                      Nouveau dernier jour de la période {period.number}
                    </legend>
                    {options.days.map((day) => (
                      <label key={day.date} className={`mv-rectify__day ${day.allowed ? '' : 'is-refused'}`}>
                        <input
                          type="radio"
                          name="rectified-last-day"
                          value={day.date}
                          checked={lastDay === day.date}
                          disabled={!day.allowed || saving}
                          onChange={() => setLastDay(day.date)}
                        />
                        <span>
                          <strong>{formatDate(day.date, 'card')}</strong>
                          {day.allowed ? (
                            <span className="mv-muted mv-small">
                              {' '}
                              · la période {next.number} commencera le{' '}
                              {formatDate(addDays(day.date, 1), 'card')}
                            </span>
                          ) : (
                            <span className="mv-small mv-rectify__reason">
                              {' '}
                              · {day.reasons.map(dateReasonMessage).join(' ')}
                            </span>
                          )}
                        </span>
                      </label>
                    ))}
                  </fieldset>

                  <ul className="mv-rectify__effects">
                    <li>Aucune garde, aucune affectation ni aucune publication n’est modifiée.</li>
                    <li>
                      Les jours retirés n’ont aucune garde ; ils rejoignent la période {next.number}, encore
                      en préparation.
                    </li>
                    <li>
                      Les PDF des anciennes diffusions s’arrêteront au nouveau dernier jour, avec les mêmes
                      gardes.
                    </li>
                    <li>
                      Aucun email n’est envoyé. La correction est enregistrée avec votre nom et votre raison.
                    </li>
                  </ul>

                  <div className="mv-field">
                    <label className="mv-field__label" htmlFor="mv-rectify-reason">
                      Raison de la correction
                    </label>
                    <textarea
                      id="mv-rectify-reason"
                      className="mv-input mv-rectify__reason-input"
                      rows={3}
                      maxLength={max}
                      value={reason}
                      disabled={saving}
                      onChange={(e) => setReason(e.target.value)}
                    />
                    <span className="mv-field__hint">
                      {reason.length} / {max}
                    </span>
                  </div>

                  <label className="mv-rectify__confirm">
                    <input
                      type="checkbox"
                      checked={confirmed}
                      disabled={saving}
                      onChange={(e) => setConfirmed(e.target.checked)}
                    />
                    <span>Je confirme cette correction exceptionnelle de la fin d’une période publiée.</span>
                  </label>
                </>
              )}
              {error && (
                <p role="alert" className="mv-rectify__error">
                  {error}
                </p>
              )}
            </div>
          )}
        </div>

        <footer className="mv-dialog__foot">
          <button type="button" className="mv-btn mv-btn--secondary" onClick={close} disabled={saving}>
            Annuler
          </button>
          <button
            type="button"
            className="mv-btn mv-btn--primary"
            disabled={!canConfirm}
            aria-busy={saving}
            onClick={() => void confirm()}
          >
            {saving ? 'Correction…' : 'Corriger la fin'}
          </button>
        </footer>
      </div>
    </div>
  )
}
