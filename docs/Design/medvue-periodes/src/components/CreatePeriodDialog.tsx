import { useEffect, useRef, useState } from 'react';
import type { ISODate, Period } from '../types';
import { addDays, daysInclusive, endAfterMonths, formatDate, formatRange, isValidIso } from '../lib/dates';
import { MIN_PERIOD_DAYS, nextPeriodStart, validateEnd } from '../lib/periods';
import { StatusPill } from './StatusPill';
import { Icon } from './Icon';

interface Props {
  open: boolean;
  planningName: string;
  lineCount: number;
  memberCount: number;
  periods: Period[];
  /** Date de fin pré-remplie (facultatif). */
  initialEnd?: ISODate;
  onCancel: () => void;
  onCreate: (end: ISODate) => void;
}

const PRESETS = [3, 4, 6];

/**
 * « Créer la période suivante ».
 * Fenêtre centrée sur ordinateur, feuille depuis le bas sur mobile (même composant, CSS responsive).
 */
export function CreatePeriodDialog({ open, planningName, lineCount, memberCount, periods, initialEnd = '', onCancel, onCreate }: Props) {
  const [end, setEnd] = useState<ISODate | ''>(initialEnd);
  const dialogRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (open) {
      setEnd(initialEnd);
      dialogRef.current?.focus();
    }
  }, [open, initialEnd]);

  if (!open || periods.length === 0) return null;

  const previous = periods[periods.length - 1];
  const start = nextPeriodStart(periods);
  const valid = end && isValidIso(end) ? validateEnd(start, end) : { ok: false as const };
  const days = valid.ok && end ? daysInclusive(start, end) : 0;
  const prevDays = daysInclusive(previous.start, previous.end);
  const newNumber = previous.number + 1;

  return (
    <div className="mv-overlay" onMouseDown={onCancel}>
      <div ref={dialogRef} className="mv-dialog" role="dialog" aria-modal="true" aria-labelledby="mv-create-title" tabIndex={-1}
        onMouseDown={(e) => e.stopPropagation()} onKeyDown={(e) => { if (e.key === 'Escape') onCancel(); }}>
        <span className="mv-dialog__handle" aria-hidden="true" />
        <header className="mv-dialog__head">
          <div>
            <h2 id="mv-create-title">Créer la période suivante</h2>
            <p>Planning : <strong>{planningName}</strong></p>
          </div>
          <button type="button" className="mv-icon-btn mv-icon-btn--soft" aria-label="Fermer" onClick={onCancel}><Icon name="close" size={20} /></button>
        </header>

        <div className="mv-dialog__body">
          <div className="mv-prev-summary">
            <div>
              <span className="mv-eyebrow">Période précédente · Période {previous.number}</span>
              <strong>{formatRange(previous.start, previous.end)}</strong>
            </div>
            <StatusPill status={previous.status} size="sm" />
          </div>

          <span className="mv-eyebrow mv-eyebrow--brand">Nouvelle période · Période {newNumber}</span>
          <div className="mv-form-grid">
            <div className="mv-field">
              <span className="mv-field__label">Début</span>
              <div className="mv-field__locked"><Icon name="lock" size={18} strokeWidth={1.9} />{formatDate(start, 'card')}</div>
              <span className="mv-field__hint">Lendemain de la fin de la période {previous.number}</span>
            </div>
            <div className="mv-field">
              <label className="mv-field__label" htmlFor="mv-end">Fin (incluse)</label>
              <input id="mv-end" type="date" className={`mv-input ${valid.error ? 'is-error' : valid.ok ? 'is-valid' : ''}`}
                value={end} min={addDays(start, MIN_PERIOD_DAYS - 1)} onChange={(e) => setEnd(e.target.value)}
                aria-invalid={!!valid.error} aria-describedby="mv-end-hint" />
              <span id="mv-end-hint" className={`mv-field__hint ${valid.error ? 'is-error' : ''}`}>
                {valid.error ?? (valid.ok && end ? formatDate(end, 'long') : 'Choisissez la date de fin')}
              </span>
            </div>
          </div>

          <div className="mv-presets" role="group" aria-label="Durées usuelles">
            {PRESETS.map((k) => {
              const e = endAfterMonths(start, k);
              return (
                <button key={k} type="button" aria-pressed={e === end} className={e === end ? 'is-selected' : ''} onClick={() => setEnd(e)}>
                  {k} mois <span>→ {formatDate(e, 'dm')}</span>
                </button>
              );
            })}
          </div>

          <div className="mv-duration">
            <div className="mv-duration__top">
              <span>Durée calculée</span>
              <strong>{valid.ok ? `${days} jours` : '—'}
                <small>{valid.ok ? `≈ ${(days / 30.44).toFixed(1).replace('.', ',')} mois` : 'en attente de la date de fin'}</small>
              </strong>
            </div>
            <div className="mv-duration__bars" aria-hidden="true">
              <span className="mv-duration__prev" style={{ flex: `${prevDays} 1 0px` }} />
              <span className={`mv-duration__new ${valid.ok ? '' : 'is-pending'}`} style={{ flex: `${valid.ok ? days : 90} 1 0px` }} />
            </div>
            <div className="mv-duration__legend"><span>Période {previous.number} · inchangée</span><span>Période {newNumber} · nouvelle</span></div>
          </div>

          <ul className="mv-checklist">
            <li><Icon name="check" strokeWidth={2.4} />La période {previous.number} et ses gardes ne sont pas modifiées.</li>
            <li><Icon name="check" strokeWidth={2.4} />Une nouvelle période « En préparation » est créée.</li>
            <li><Icon name="check" strokeWidth={2.4} />Elle reste rattachée à {planningName}, à ses {lineCount} lignes et à ses {memberCount} membres.</li>
            <li><Icon name="check" strokeWidth={2.4} />Vous pourrez ensuite ouvrir une collecte des disponibilités pour ces dates.</li>
          </ul>
        </div>

        <footer className="mv-dialog__foot">
          <button type="button" className="mv-btn mv-btn--secondary" onClick={onCancel}>Annuler</button>
          <button type="button" className="mv-btn mv-btn--primary" disabled={!valid.ok} onClick={() => end && valid.ok && onCreate(end)}>
            Créer la période
          </button>
        </footer>
      </div>
    </div>
  );
}
