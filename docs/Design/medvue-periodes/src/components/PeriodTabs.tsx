import { useEffect, useState } from 'react';
import type { ISODate, Period, Planning, Shift } from '../types';
import { formatDate, formatRange, timeState, toMs } from '../lib/dates';
import { Icon } from './Icon';

interface Props {
  planning: Planning;
  period: Period;
  today: ISODate;
  shifts: Shift[];
  /** Ids des membres ayant répondu à la collecte de cette période. */
  respondedIds: string[];
  canManage: boolean;
  onOpenCollecte: () => void;
}

type Tab = 'cal' | 'dispo';

/** Contenu propre à la période sélectionnée : calendrier des gardes et disponibilités. */
export function PeriodTabs({ planning, period, today, shifts, respondedIds, canManage, onOpenCollecte }: Props) {
  const hasCal = shifts.length > 0;
  const [tab, setTab] = useState<Tab>(hasCal ? 'cal' : 'dispo');
  useEffect(() => { setTab(hasCal ? 'cal' : 'dispo'); }, [period.id, hasCal]);

  const c = period.collecte;
  const total = planning.members.length;

  return (
    <section className="mv-card mv-tabs-card">
      <div className="mv-tabs" role="tablist" aria-label="Contenu de la période">
        <button type="button" role="tab" aria-selected={tab === 'cal'} className={tab === 'cal' ? 'is-selected' : ''} onClick={() => setTab('cal')}>
          Calendrier des gardes
        </button>
        <button type="button" role="tab" aria-selected={tab === 'dispo'} className={tab === 'dispo' ? 'is-selected' : ''} onClick={() => setTab('dispo')}>
          Disponibilités <span className="mv-count">{c.state === 'non-ouverte' ? '—' : `${c.responses}/${total}`}</span>
        </button>
        <span className="mv-tabs__context mv-muted">Période {period.number} · {formatRange(period.start, period.end)}</span>
      </div>

      {tab === 'cal' && (hasCal
        ? <CalendarPreview planning={planning} period={period} today={today} shifts={shifts} />
        : <Empty icon="calendar" title={`Le calendrier de la période ${period.number} n’est pas encore généré`}
            text="Il apparaîtra ici après la collecte des disponibilités et la génération. Les gardes des périodes précédentes restent consultables." />)}

      {tab === 'dispo' && (c.state === 'non-ouverte'
        ? <Empty icon="users" tone="warn" title="Collecte des disponibilités pas encore ouverte"
            text={`Les ${total} membres du planning seront invités à indiquer leurs indisponibilités du ${formatRange(period.start, period.end)}. Les réponses de la période précédente ne sont pas reprises.`}
            action={canManage ? { label: 'Ouvrir la collecte', onClick: onOpenCollecte } : undefined} />
        : <Responses planning={planning} period={period} respondedIds={respondedIds} />)}
    </section>
  );
}

function CalendarPreview({ planning, period, today, shifts }: { planning: Planning; period: Period; today: ISODate; shifts: Shift[] }) {
  const t = timeState(period, today);
  const from = t.kind === 'current' ? toMs(today) : toMs(period.start);
  const days = [...new Set(shifts.map((s) => s.date))].filter((d) => toMs(d) >= from).slice(0, 7);
  const member = (id: string) => planning.members.find((m) => m.id === id);

  return (
    <div className="mv-table-wrap">
      <table className="mv-table">
        <thead>
          <tr><th scope="col">Date</th>{planning.lines.map((l) => <th key={l.id} scope="col">{l.name}</th>)}</tr>
        </thead>
        <tbody>
          {days.map((d) => (
            <tr key={d} className={d === today ? 'is-today' : ''}>
              <td className="mv-table__date">{formatDate(d, 'wdm')}{d === today && <span className="mv-today-tag">Aujourd’hui</span>}</td>
              {planning.lines.map((l) => {
                const m = member(shifts.find((s) => s.date === d && s.lineId === l.id)?.memberId ?? '');
                return <td key={l.id}><span className={`mv-shift ${m?.isMe ? 'is-me' : ''}`}>{m ? (m.isMe ? `Vous · ${m.name}` : m.name) : '—'}</span></td>;
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Responses({ planning, period, respondedIds }: { planning: Planning; period: Period; respondedIds: string[] }) {
  const c = period.collecte;
  return (
    <div className="mv-responses">
      <p><strong>{c.responses} / {planning.members.length} réponses</strong>{' '}
        <span className="mv-muted">{c.state === 'en-cours' ? `Collecte ouverte${c.closesOn ? ` · clôture le ${formatDate(c.closesOn)}` : ''}` : 'Collecte clôturée'}</span></p>
      <ul className="mv-responses__grid">
        {planning.members.map((m) => {
          const ok = respondedIds.includes(m.id);
          const label = ok ? 'Répondu' : c.state === 'cloturee' ? 'Sans réponse' : 'En attente';
          const tone = ok ? 'ok' : c.state === 'cloturee' ? 'neutral' : 'warn';
          return (
            <li key={m.id}>
              <span className="mv-avatar" aria-hidden="true">{m.name.split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase()}</span>
              <span className="mv-responses__name">{m.name}</span>
              <span className={`mv-badge mv-badge--${tone}`}>{label}</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function Empty({ icon, tone, title, text, action }: {
  icon: 'calendar' | 'users'; tone?: 'warn'; title: string; text: string; action?: { label: string; onClick: () => void };
}) {
  return (
    <div className="mv-empty">
      <span className={`mv-empty__icon ${tone ? `mv-empty__icon--${tone}` : ''}`}><Icon name={icon} size={26} strokeWidth={1.75} /></span>
      <strong>{title}</strong>
      <p>{text}</p>
      {action && <button type="button" className="mv-btn mv-btn--primary" onClick={action.onClick}>{action.label}</button>}
    </div>
  );
}
