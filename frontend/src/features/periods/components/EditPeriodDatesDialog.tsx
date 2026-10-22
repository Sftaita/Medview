import { useEffect, useRef, useState } from 'react'
import type { DateOptions } from '../api'
import { addDays, daysInclusive, formatDate } from '../dates'
import { dateReasonMessage } from '../errors'
import type { ISODate, Period } from '../types'
import { monthOf, useDateOptions } from '../useDateOptions'
import { BoundaryCalendar } from './BoundaryCalendar'
import { Icon } from './Icon'

type Edge = 'start' | 'end'

interface Props {
  open: boolean
  period: Period
  previous?: Period
  next?: Period
  /** The possible days of one edge, from `from` to `to` (docs/decisions.md D207). */
  loadOptions: (edge: Edge, from: ISODate, to: ISODate) => Promise<DateOptions>
  /** Saves the new first day and/or last day (inclusive); rejects with a message to show. */
  onSave: (dates: { start?: ISODate; end?: ISODate }) => Promise<void>
  onCancel: () => void
}

/**
 * « Modifier les dates » of a period (docs/decisions.md D207). The first day
 * is chosen only for the first period; a following one starts the day after
 * the previous one, always. The last day is chosen on a calendar where each
 * refused day says why — the server checks the same rules when saving.
 */
export function EditPeriodDatesDialog({
  open,
  period,
  previous,
  next,
  loadOptions,
  onSave,
  onCancel,
}: Props) {
  const [start, setStart] = useState<ISODate>(period.start)
  const [end, setEnd] = useState<ISODate>(period.end)
  const [edge, setEdge] = useState<Edge>('end')
  const [months, setMonths] = useState<Record<Edge, string>>({
    start: monthOf(period.start),
    end: monthOf(period.end),
  })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const dialogRef = useRef<HTMLDivElement>(null)
  const key = `${period.id}-${period.start}-${period.end}`
  const startOptions = useDateOptions(
    open ? (from, to) => loadOptions('start', from, to) : undefined,
    `start-${key}`,
  )
  const endOptions = useDateOptions(
    open ? (from, to) => loadOptions('end', from, to) : undefined,
    `end-${key}`,
  )
  const ensureStart = startOptions.ensure
  const ensureEnd = endOptions.ensure
  const startEditable = period.number === 1

  // Every opening starts again from the period's own dates.
  const [shown, setShown] = useState({ open, key })
  if (shown.open !== open || shown.key !== key) {
    setShown({ open, key })
    if (open) {
      setStart(period.start)
      setEnd(period.end)
      setEdge('end')
      setMonths({ start: monthOf(period.start), end: monthOf(period.end) })
      setError(null)
    }
  }

  useEffect(() => {
    if (open) dialogRef.current?.focus()
  }, [open])
  useEffect(() => {
    if (open) ensureEnd(months.end)
  }, [open, months.end, ensureEnd])
  useEffect(() => {
    if (open && startEditable && edge === 'start') ensureStart(months.start)
  }, [open, startEditable, edge, months.start, ensureStart])

  if (!open) return null

  const active = edge === 'start' ? startOptions : endOptions
  const selected = edge === 'start' ? start : end
  const chosen = active.days[selected]
  const changedStart = start !== period.start
  const changedEnd = end !== period.end
  const startRefused = changedStart && startOptions.days[start]?.allowed === false
  const endRefused = changedEnd && endOptions.days[end]?.allowed === false
  const blockedStart = changedStart && (startOptions.meta?.blockers.length ?? 0) > 0
  const blockedEnd = changedEnd && (endOptions.meta?.blockers.length ?? 0) > 0
  const days = end >= start ? daysInclusive(start, end) : 0
  const canSave =
    (changedStart || changedEnd) && !startRefused && !endRefused && !blockedStart && !blockedEnd && !saving
  const close = () => {
    if (!saving) onCancel()
  }

  function choose(day: ISODate) {
    setError(null)
    if (edge === 'start') setStart(day)
    else setEnd(day)
  }

  async function save() {
    if (!canSave) return
    setSaving(true)
    setError(null)
    try {
      await onSave({ start: changedStart ? start : undefined, end: changedEnd ? end : undefined })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Les dates n’ont pas pu être enregistrées. Réessayez.')
    } finally {
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
        aria-labelledby="mv-dates-title"
        tabIndex={-1}
        onMouseDown={(e) => e.stopPropagation()}
        onKeyDown={(e) => {
          if (e.key === 'Escape') close()
        }}
      >
        <span className="mv-dialog__handle" aria-hidden="true" />
        <header className="mv-dialog__head">
          <div>
            <h2 id="mv-dates-title">Modifier les dates</h2>
            <p>Période {period.number}</p>
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
          <div className="mv-form-grid">
            <div className="mv-field">
              <span className="mv-field__label">Premier jour</span>
              {startEditable ? (
                <button
                  type="button"
                  className={`mv-input mv-date-choice ${edge === 'start' ? 'is-active' : ''}`}
                  aria-pressed={edge === 'start'}
                  onClick={() => setEdge('start')}
                  disabled={saving}
                >
                  {formatDate(start, 'card')}
                </button>
              ) : (
                <>
                  <div className="mv-field__locked">
                    <Icon name="lock" size={18} strokeWidth={1.9} />
                    {formatDate(start, 'card')}
                  </div>
                  <span className="mv-field__hint">
                    {previous
                      ? `Lendemain du dernier jour de la période ${previous.number}. Pour le déplacer, modifiez la fin de la période ${previous.number}.`
                      : null}
                  </span>
                </>
              )}
            </div>
            <div className="mv-field">
              <span className="mv-field__label">Dernier jour (inclus)</span>
              <button
                type="button"
                className={`mv-input mv-date-choice ${edge === 'end' ? 'is-active' : ''}`}
                aria-pressed={edge === 'end'}
                onClick={() => setEdge('end')}
                disabled={saving}
              >
                {formatDate(end, 'card')}
              </button>
            </div>
          </div>

          <BoundaryCalendar
            month={months[edge]}
            onMonthChange={(month) => setMonths((current) => ({ ...current, [edge]: month }))}
            label={edge === 'start' ? 'Premier jour' : 'Dernier jour (inclus)'}
            selected={selected}
            current={edge === 'start' ? period.start : period.end}
            fixed={edge === 'end' ? start : null}
            optionOf={(day) => active.days[day]}
            onSelect={choose}
            disabled={saving}
          />

          <div role="status" className="mv-date-status">
            {active.meta && active.meta.blockers.length > 0 ? (
              <p className="mv-field__hint is-error">
                {active.meta.blockers.map(dateReasonMessage).join(' ')}
              </p>
            ) : chosen && !chosen.allowed ? (
              <p className="mv-field__hint is-error">
                {formatDate(selected, 'long')} est impossible :{' '}
                {chosen.reasons.map(dateReasonMessage).join(' ')}
              </p>
            ) : (
              <p className="mv-field__hint">
                Du {formatDate(start, 'long')} au {formatDate(end, 'long')} inclus · {days} jours.
                {next && changedEnd
                  ? ` La période ${next.number} commencera le ${formatDate(addDays(end, 1), 'long')}.`
                  : ''}
              </p>
            )}
            {active.failed && (
              <p className="mv-muted mv-small">
                Les jours possibles n’ont pas pu être chargés : les dates seront vérifiées à l’enregistrement.
              </p>
            )}
          </div>

          {error && (
            <div role="alert" className="mv-banner mv-banner--error">
              <Icon name="info" size={20} strokeWidth={1.9} />
              <span>{error}</span>
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
            disabled={!canSave}
            aria-busy={saving}
            onClick={() => void save()}
          >
            {saving ? 'Enregistrement…' : 'Enregistrer les dates'}
          </button>
        </footer>
      </div>
    </div>
  )
}
