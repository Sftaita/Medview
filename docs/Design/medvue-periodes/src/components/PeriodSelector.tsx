import { useEffect, useRef, useState } from 'react';
import type { ISODate, Period } from '../types';
import { formatDate, formatRange, timeState } from '../lib/dates';
import { STATUS_LABEL, friseWindow, nextPeriodStart } from '../lib/periods';
import { StatusPill } from './StatusPill';
import { TimeBar } from './TimeBar';
import { Icon } from './Icon';

interface Props {
  periods: Period[];
  selectedId: string;
  today: ISODate;
  canCreate: boolean;
  onSelect: (id: string) => void;
  onCreate: () => void;
}

/**
 * Sélecteur de périodes.
 * Ordinateur : frise proportionnelle à la durée (+ « Historique » au-delà de 4 périodes).
 * Mobile : flèches précédent / suivant + mini-frise sans défilement horizontal.
 * Les deux rendus coexistent ; le CSS affiche celui qui convient.
 */
export function PeriodSelector(props: Props) {
  return (
    <>
      <DesktopFrise {...props} />
      <MobileSwitcher {...props} />
    </>
  );
}

function DesktopFrise({ periods, selectedId, today, canCreate, onSelect, onCreate }: Props) {
  const { visible, history } = friseWindow(periods, today);
  const historySelected = history.some((p) => p.id === selectedId);

  return (
    <div className="mv-frise">
      <div className="mv-frise__row">
        {history.length > 0 && (
          <HistoryMenu history={history} selectedId={selectedId} active={historySelected} onSelect={onSelect} />
        )}
        {visible.map((p) => {
          const t = timeState(p, today);
          const selected = p.id === selectedId;
          return (
            <button key={p.id} type="button" aria-pressed={selected}
              className={`mv-frise__seg ${selected ? 'is-selected' : ''}`}
              style={{ flex: `${t.days} 1 0px` }} onClick={() => onSelect(p.id)}>
              <span className="mv-frise__head">
                <strong>Période {p.number}</strong>
                <StatusPill status={p.status} size="sm" />
              </span>
              <span className="mv-frise__range">{formatRange(p.start, p.end)}</span>
              <TimeBar kind={t.kind} elapsedPct={t.elapsedPct} thickness={6} />
              <span className="mv-frise__time">{t.label}</span>
            </button>
          );
        })}
        {canCreate && (
          <button type="button" className="mv-frise__ghost" onClick={onCreate}>
            <Icon name="plus" size={20} />
            <strong>Période suivante</strong>
            <span>dès le {formatDate(nextPeriodStart(periods), 'dm')}</span>
          </button>
        )}
      </div>
      <div className="mv-frise__legend">
        <span><i className="mv-legend-today" /> Aujourd’hui · {formatDate(today, 'dmy')}</span>
        <span><i className="mv-legend-elapsed" /> Temps écoulé de la période</span>
        <span>La largeur de chaque période suit sa durée.</span>
      </div>
    </div>
  );
}

function HistoryMenu({ history, selectedId, active, onSelect }: {
  history: Period[]; selectedId: string; active: boolean; onSelect: (id: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  const selected = history.find((p) => p.id === selectedId);

  return (
    <div className="mv-history" ref={ref}>
      <button type="button" className={`mv-history__btn ${active ? 'is-selected' : ''}`}
        aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen((o) => !o)}
        onKeyDown={(e) => { if (e.key === 'Escape') setOpen(false); }}>
        <Icon name="archive" size={20} strokeWidth={1.9} />
        <strong>Historique · {history.length}</strong>
        <span>{selected ? `Période ${selected.number}` : `P1 → P${history[history.length - 1].number}`}</span>
        <Icon name="chevronDown" size={16} />
      </button>
      {open && (
        <ul className="mv-history__list" role="listbox" aria-label="Périodes précédentes"
          onKeyDown={(e) => { if (e.key === 'Escape') setOpen(false); }}>
          {[...history].reverse().map((p) => (
            <li key={p.id} role="option" aria-selected={p.id === selectedId}>
              <button type="button" className={p.id === selectedId ? 'is-selected' : ''}
                onClick={() => { onSelect(p.id); setOpen(false); }}>
                <strong>Période {p.number}</strong>
                <span className="mv-history__range">{formatRange(p.start, p.end)}</span>
                <StatusPill status={p.status} size="sm" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function MobileSwitcher({ periods, selectedId, today, onSelect }: Props) {
  const idx = Math.max(0, periods.findIndex((p) => p.id === selectedId));
  const p = periods[idx];
  const { visible, history } = friseWindow(periods, today);
  const go = (i: number) => periods[i] && onSelect(periods[i].id);

  return (
    <div className="mv-switcher">
      <div className="mv-switcher__top">
        <button type="button" className="mv-icon-btn" aria-label="Période précédente" disabled={idx === 0} onClick={() => go(idx - 1)}>
          <Icon name="chevronLeft" size={20} />
        </button>
        <div className="mv-switcher__center">
          <span className="mv-eyebrow">Période {p.number} sur {periods.length}</span>
          <strong>{formatRange(p.start, p.end)}</strong>
          <StatusPill status={p.status} size="sm" />
        </div>
        <button type="button" className="mv-icon-btn" aria-label="Période suivante" disabled={idx === periods.length - 1} onClick={() => go(idx + 1)}>
          <Icon name="chevronRight" size={20} />
        </button>
      </div>
      <div className="mv-minifrise">
        {history.length > 0 && (
          <button type="button" className={`mv-minifrise__history ${history.some((h) => h.id === selectedId) ? 'is-selected' : ''}`}
            aria-label={`Historique, ${history.length} périodes`} onClick={() => go(Math.max(0, history.length - 1))}>
            +{history.length}
          </button>
        )}
        {visible.map((v) => {
          const t = timeState(v, today);
          const selected = v.id === selectedId;
          return (
            <button key={v.id} type="button" style={{ flex: `${t.days} 1 0px` }}
              className={`mv-minifrise__seg ${selected ? 'is-selected' : ''}`} aria-pressed={selected}
              aria-label={`Période ${v.number}, ${STATUS_LABEL[v.status]}`} onClick={() => onSelect(v.id)}>
              <span className="mv-minifrise__num">P{v.number}</span>
              <TimeBar kind={t.kind} elapsedPct={t.elapsedPct} />
            </button>
          );
        })}
      </div>
    </div>
  );
}
