import { useEffect, useRef, useState } from 'react'
import type { ISODate, Period } from '../types'
import { addDays, daysInclusive, endAfterMonths, formatDate, formatRange, isValidIso } from '../dates'
import { MIN_PERIOD_DAYS, nextPeriodStart, validateEnd } from '../periods'
import { PeriodCreationError } from '../errors'
import { StatusPill } from './StatusPill'
import { Icon } from './Icon'

interface Props {
  open: boolean
  planningName: string
  lineCount: number
  memberCount: number
  periods: Period[]
  /** Date de fin pré-remplie (facultatif). */
  initialEnd?: ISODate
  onCancel: () => void
  /**
   * Creates the period ending on `end` (last day included). Rejects with a
   * PeriodCreationError; `acceptHistoricalBlockCuts` is true only once the
   * manager ticked the confirmation of the cut blocks (docs/decisions.md D192).
   */
  onCreate: (end: ISODate, acceptHistoricalBlockCuts: boolean) => Promise<void>
  /** The periods changed meanwhile (409): reload them. */
  onReload?: () => void
}

const PRESETS = [3, 4, 6]

/**
 * « Créer la période suivante ».
 * Fenêtre centrée sur ordinateur, feuille depuis le bas sur mobile (même composant, CSS responsive).
 * Le dernier jour est toujours affiché « inclus », avec le premier jour de la période qui suivrait :
 * l'API compte la fin exclue, l'écran jamais.
 */
export function CreatePeriodDialog({
  open,
  planningName,
  lineCount,
  memberCount,
  periods,
  initialEnd = '',
  onCancel,
  onCreate,
  onReload,
}: Props) {
  const [end, setEnd] = useState<ISODate | ''>(initialEnd)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<PeriodCreationError | null>(null)
  const [confirmed, setConfirmed] = useState(false)
  const dialogRef = useRef<HTMLDivElement>(null)

  // À chaque ouverture, la date de fin repart de la valeur pré-remplie.
  const [shown, setShown] = useState({ open, initialEnd })
  if (shown.open !== open || shown.initialEnd !== initialEnd) {
    setShown({ open, initialEnd })
    if (open) {
      setEnd(initialEnd)
      setError(null)
      setConfirmed(false)
    }
  }

  useEffect(() => {
    if (open) dialogRef.current?.focus()
  }, [open])

  if (!open || periods.length === 0) return null

  const previous = periods[periods.length - 1]
  const start = nextPeriodStart(periods)
  const valid = end && isValidIso(end) ? validateEnd(start, end) : { ok: false as const }
  const days = valid.ok && end ? daysInclusive(start, end) : 0
  const prevDays = daysInclusive(previous.start, previous.end)
  const newNumber = previous.number + 1
  const historical = error?.kind === 'historical'
  const close = () => {
    if (!submitting) onCancel()
  }

  function changeEnd(value: ISODate | '') {
    setEnd(value)
    // Another date: the server's answer no longer describes it.
    setError(null)
    setConfirmed(false)
  }

  async function submit() {
    if (!end || !valid.ok || submitting) return
    setSubmitting(true)
    try {
      await onCreate(end, historical && confirmed)
    } catch (err) {
      setError(
        err instanceof PeriodCreationError
          ? err
          : new PeriodCreationError('other', 'La période n’a pas pu être créée. Réessayez.'),
      )
      setConfirmed(false)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="mv-overlay" onMouseDown={close}>
      <div
        ref={dialogRef}
        className="mv-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mv-create-title"
        tabIndex={-1}
        onMouseDown={(e) => e.stopPropagation()}
        onKeyDown={(e) => {
          if (e.key === 'Escape') close()
        }}
      >
        <span className="mv-dialog__handle" aria-hidden="true" />
        <header className="mv-dialog__head">
          <div>
            <h2 id="mv-create-title">Créer la période suivante</h2>
            <p>
              Planning : <strong>{planningName}</strong>
            </p>
          </div>
          <button
            type="button"
            className="mv-icon-btn mv-icon-btn--soft"
            aria-label="Fermer"
            onClick={close}
            disabled={submitting}
          >
            <Icon name="close" size={20} />
          </button>
        </header>

        <div className="mv-dialog__body">
          <div className="mv-prev-summary">
            <div>
              <span className="mv-eyebrow">Période précédente · Période {previous.number}</span>
              <strong>{formatRange(previous.start, previous.end)}</strong>
              <span className="mv-muted mv-small">
                Dernier jour inclus : {formatDate(previous.end, 'long')}
              </span>
            </div>
            <StatusPill status={previous.status} size="sm" />
          </div>

          <span className="mv-eyebrow mv-eyebrow--brand">Nouvelle période · Période {newNumber}</span>
          <div className="mv-form-grid">
            <div className="mv-field">
              <span className="mv-field__label">Premier jour (inclus)</span>
              <div className="mv-field__locked">
                <Icon name="lock" size={18} strokeWidth={1.9} />
                {formatDate(start, 'card')}
              </div>
              <span className="mv-field__hint">
                Lendemain du dernier jour de la période {previous.number}
              </span>
            </div>
            <div className="mv-field">
              <label className="mv-field__label" htmlFor="mv-end">
                Dernier jour (inclus)
              </label>
              <input
                id="mv-end"
                type="date"
                className={`mv-input ${valid.error ? 'is-error' : valid.ok ? 'is-valid' : ''}`}
                value={end}
                min={addDays(start, MIN_PERIOD_DAYS - 1)}
                onChange={(e) => changeEnd(e.target.value)}
                aria-invalid={!!valid.error}
                aria-describedby="mv-end-hint"
                disabled={submitting}
              />
              <span id="mv-end-hint" className={`mv-field__hint ${valid.error ? 'is-error' : ''}`}>
                {valid.error ??
                  (valid.ok && end
                    ? `Gardes jusqu’au ${formatDate(end, 'long')} inclus. Une période suivante commencerait le ${formatDate(addDays(end, 1), 'long')}.`
                    : 'Choisissez le dernier jour de la période')}
              </span>
            </div>
          </div>

          <div className="mv-presets" role="group" aria-label="Durées usuelles">
            {PRESETS.map((k) => {
              const e = endAfterMonths(start, k)
              return (
                <button
                  key={k}
                  type="button"
                  aria-pressed={e === end}
                  className={e === end ? 'is-selected' : ''}
                  onClick={() => changeEnd(e)}
                  disabled={submitting}
                >
                  {k} mois <span>→ {formatDate(e, 'dm')}</span>
                </button>
              )
            })}
          </div>

          <div className="mv-duration">
            <div className="mv-duration__top">
              <span>Durée calculée</span>
              <strong>
                {valid.ok ? `${days} jours` : '—'}
                <small>
                  {valid.ok
                    ? `≈ ${(days / 30.44).toFixed(1).replace('.', ',')} mois`
                    : 'en attente du dernier jour'}
                </small>
              </strong>
            </div>
            <div className="mv-duration__bars" aria-hidden="true">
              <span className="mv-duration__prev" style={{ flex: `${prevDays} 1 0px` }} />
              <span
                className={`mv-duration__new ${valid.ok ? '' : 'is-pending'}`}
                style={{ flex: `${valid.ok ? days : 90} 1 0px` }}
              />
            </div>
            <div className="mv-duration__legend">
              <span>Période {previous.number} · inchangée</span>
              <span>Période {newNumber} · nouvelle</span>
            </div>
          </div>

          {error && (
            <div role="alert" className="mv-banner mv-banner--error">
              <Icon name="info" size={20} strokeWidth={1.9} />
              <div className="mv-banner__body">
                <span>{error.message}</span>
                {error.cuts.length > 0 && (
                  <ul className="mv-cuts">
                    {error.cuts.map((cut, index) => (
                      <li key={`${cut.lineStableId}-${cut.firstDay}-${index}`}>
                        <strong>
                          {cut.blockName}
                          {cut.lineName ? ` · ${cut.lineName}` : ''}
                        </strong>{' '}
                        — du {formatDate(cut.firstDay, 'wdm')} au {formatDate(cut.lastDay, 'wdm')}
                        {historical && cut.remainderDays.length > 0 && (
                          <>
                            {' '}
                            : {cut.remainderDays.length === 1 ? 'le jour' : 'les jours'}{' '}
                            {cut.remainderDays.map((d) => formatDate(d, 'wdm')).join(', ')}{' '}
                            {cut.remainderDays.length === 1 ? 'devient' : 'deviennent'} un bloc de reste de la
                            période {newNumber}
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
                {historical && (
                  <label className="mv-confirm">
                    <input
                      type="checkbox"
                      checked={confirmed}
                      onChange={(e) => setConfirmed(e.target.checked)}
                      disabled={submitting}
                    />
                    <span>
                      Je confirme : ces jours forment un bloc de reste de la période {newNumber}, attribué
                      d’un seul tenant. Rien ne change dans la période {previous.number}.
                    </span>
                  </label>
                )}
                {error.kind === 'stale' && onReload && (
                  <button type="button" className="mv-btn mv-btn--secondary mv-btn--sm" onClick={onReload}>
                    Recharger les périodes
                  </button>
                )}
              </div>
            </div>
          )}

          <ul className="mv-checklist">
            <li>
              <Icon name="check" strokeWidth={2.4} />
              La période {previous.number} et ses gardes ne sont pas modifiées.
            </li>
            <li>
              <Icon name="check" strokeWidth={2.4} />
              Une nouvelle période « En préparation » est créée.
            </li>
            <li>
              <Icon name="check" strokeWidth={2.4} />
              Elle reste rattachée à {planningName}, à ses {lineCount} lignes et à ses {memberCount} membres.
            </li>
            <li>
              <Icon name="check" strokeWidth={2.4} />
              Vous pourrez ensuite ouvrir une collecte des disponibilités pour ces dates.
            </li>
          </ul>
        </div>

        <footer className="mv-dialog__foot">
          <button type="button" className="mv-btn mv-btn--secondary" onClick={close} disabled={submitting}>
            Annuler
          </button>
          <button
            type="button"
            className="mv-btn mv-btn--primary"
            disabled={!valid.ok || submitting || error?.kind === 'stale' || (historical && !confirmed)}
            aria-busy={submitting}
            onClick={() => void submit()}
          >
            {submitting ? 'Création…' : historical ? 'Confirmer et créer la période' : 'Créer la période'}
          </button>
        </footer>
      </div>
    </div>
  )
}
