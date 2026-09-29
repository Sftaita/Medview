import { useMemo } from 'react';
import { Icon, type IconName } from './icons';
import type { DashboardHrefs, PlanningSummary, Unavailability, User } from './types';
import {
  cap, daysBetween, formatDay, formatLongDate, formatUnavailability, inclusiveDays,
  monthSegments, monthShort, parseISO, relativeDays, startOfToday,
} from './dates';

export const defaultHrefs: DashboardHrefs = {
  dashboard: '/',
  plannings: '/plannings',
  planning: id => `/plannings/${id}`,
  gardes: '/mes-gardes',
  indispos: '/mes-indisponibilites',
  indispo: id => `/mes-indisponibilites/${id}`,
  calendar: '/mes-indisponibilites/calendrier',
  account: '/compte',
};

export interface DashboardProps {
  user: User;
  plannings: PlanningSummary[];
  unavailabilities: Unavailability[];
  /** Date de référence (tests, storybook). Par défaut : aujourd'hui. */
  today?: Date;
  hrefs?: Partial<DashboardHrefs>;
  onDeclare: () => void;
  onLogout: () => void;
}

type NavKey = 'dashboard' | 'plannings' | 'gardes' | 'indispos';
const NAV: { key: NavKey; label: string; short: string; icon: IconName }[] = [
  { key: 'dashboard', label: 'Tableau de bord', short: 'Accueil', icon: 'home' },
  { key: 'plannings', label: 'Plannings', short: 'Plannings', icon: 'layers' },
  { key: 'gardes', label: 'Mes gardes', short: 'Gardes', icon: 'moon' },
  { key: 'indispos', label: 'Mes indisponibilités', short: 'Indispos', icon: 'calendarX' },
];

const plural = (n: number, s: string, p = s + 's') => `${n} ${n > 1 ? p : s}`;

export function Dashboard({ user, plannings, unavailabilities, today: todayProp, hrefs: hrefsProp, onDeclare, onLogout }: DashboardProps) {
  const today = todayProp ?? startOfToday();
  const hrefs = { ...defaultHrefs, ...hrefsProp };
  const initials = (user.firstName[0] + user.lastName[0]).toUpperCase();

  const upcoming = useMemo(() =>
    unavailabilities
      .map(u => ({ ...u, a: parseISO(u.start), b: parseISO(u.end) }))
      .filter(u => u.b >= today)
      .sort((x, y) => x.a.getTime() - y.a.getTime()),
  [unavailabilities, today.getTime()]);

  return (
    <div className="db">
      <aside className="db-side">
        <Brand />
        <nav className="db-side-nav" aria-label="Navigation principale">
          {NAV.map(n => (
            <a key={n.key} href={hrefs[n.key]} className="db-side-link" aria-current={n.key === 'dashboard' ? 'page' : undefined}>
              <Icon name={n.icon} size={20} strokeWidth={1.9} />{n.label}
            </a>
          ))}
        </nav>
        <div className="db-side-user">
          <span className="db-avatar">{initials}</span>
          <div className="db-side-user-text">
            <div className="db-side-user-name">{user.firstName} {user.lastName}</div>
            <a href={hrefs.account}>Mon compte</a>
          </div>
          <button type="button" className="db-icon-btn" onClick={onLogout} aria-label="Se déconnecter" title="Se déconnecter">
            <Icon name="logout" size={18} />
          </button>
        </div>
      </aside>

      <div className="db-body">
        <header className="db-topbar">
          <Brand compact />
          <a href={hrefs.account} className="db-avatar db-avatar-sm" aria-label="Mon compte">{initials}</a>
        </header>

        <main className="db-main">
          <section className="db-hello">
            <h1>Bonjour, {user.firstName} !</h1>
            <p>{formatLongDate(today)}</p>
          </section>

          <div className="db-grid">
            <PlanningsCard plannings={plannings} upcoming={upcoming} today={today} hrefs={hrefs} />
            <UnavailabilityCard upcoming={upcoming} today={today} hrefs={hrefs} onDeclare={onDeclare} />
          </div>
        </main>

        <nav className="db-bottom" aria-label="Navigation principale">
          {NAV.map(n => (
            <a key={n.key} href={hrefs[n.key]} className="db-bottom-link" aria-current={n.key === 'dashboard' ? 'page' : undefined}>
              <Icon name={n.icon} size={22} strokeWidth={1.9} />{n.short}
            </a>
          ))}
        </nav>
      </div>
    </div>
  );
}

function Brand({ compact = false }: { compact?: boolean }) {
  return (
    <div className={compact ? 'db-brand db-brand-sm' : 'db-brand'}>
      <span className="db-brand-mark"><Icon name="pulse" size={compact ? 17 : 20} strokeWidth={2.3} /></span>
      <span className="db-brand-name">MedVue</span>
    </div>
  );
}

type Upcoming = Unavailability & { a: Date; b: Date };

function CardHeader({ title, sub, link }: { title: string; sub?: string; link?: { href: string; long: string; short?: string } }) {
  return (
    <div className="db-card-head">
      <div className="db-card-head-text">
        <h2>{title}</h2>
        {sub && <span className="db-muted db-num">{sub}</span>}
      </div>
      {link && (
        <a href={link.href} className="db-head-link">
          <span className={link.short ? 'db-long' : undefined}>{link.long}</span>
          {link.short && <span className="db-short">{link.short}</span>}
          <Icon name="chevronRight" size={16} strokeWidth={2.2} />
        </a>
      )}
    </div>
  );
}

function PlanningsCard({ plannings, upcoming, today, hrefs }: { plannings: PlanningSummary[]; upcoming: Upcoming[]; today: Date; hrefs: DashboardHrefs }) {
  return (
    <section className="db-card" aria-label="Mes plannings">
      <CardHeader title="Mes plannings" link={{ href: hrefs.plannings, long: 'Tous les plannings', short: 'Tout voir' }} />
      {plannings.length === 0 && (
        <div className="db-empty">
          <div className="db-empty-title">Aucun planning pour l'instant</div>
          <div className="db-muted">Vous serez notifié dès qu'une équipe vous ajoute à un planning.</div>
        </div>
      )}
      {plannings.map(p => <PlanningRow key={p.id} p={p} upcoming={upcoming} today={today} href={hrefs.planning(p.id)} gardesHref={hrefs.gardes} />)}
    </section>
  );
}

function PlanningRow({ p, upcoming, today, href, gardesHref }: { p: PlanningSummary; upcoming: Upcoming[]; today: Date; href: string; gardesHref: string }) {
  const s = parseISO(p.start), e = parseISO(p.end);
  const len = inclusiveDays(s, e);
  const until = daysBetween(today, s);
  const status = until > 0
    ? { label: `Commence dans ${plural(until, 'jour')}`, tone: 'info' }
    : today <= e
      ? { label: `En cours · jour ${daysBetween(s, today) + 1} sur ${len}`, tone: 'live' }
      : { label: 'Terminé', tone: 'done' };
  const months = monthSegments(s, e);
  const inside = upcoming.filter(u => u.b >= s && u.a <= e);

  return (
    <a href={href} className="db-planning">
      <div className="db-planning-top">
        <div className="db-planning-info">
          <div className="db-planning-title">
            <span className="db-planning-name">{p.name}</span>
            <span className={`db-pill db-pill-${status.tone}`}><span className="db-dot" />{status.label}</span>
          </div>
          <span className="db-planning-range db-num">
            <span className="db-long">{cap(formatDay(s, { year: true }))} → {formatDay(e, { year: true })}</span>
            <span className="db-short">{formatDay(s, { year: true, weekday: false })} → {formatDay(e, { year: true, weekday: false })}</span>
          </span>
          <span className="db-muted db-num">
            {len} jours · {p.role}<span className="db-long"> · {plural(p.memberCount, 'membre')}</span>
          </span>
        </div>
        <span className="db-chevron"><Icon name="chevronRight" size={20} strokeWidth={2.2} /></span>
      </div>

      <div className="db-timeline" aria-hidden="true">
        <div className="db-timeline-bar">
          {months.map((m, i) => (
            <span key={m.key} className="db-timeline-seg" style={{
              flex: m.days,
              borderTopLeftRadius: i === 0 ? 999 : 0, borderBottomLeftRadius: i === 0 ? 999 : 0,
              borderTopRightRadius: i === months.length - 1 ? 999 : 0, borderBottomRightRadius: i === months.length - 1 ? 999 : 0,
            }} />
          ))}
          {inside.map(u => {
            const a = Math.max(0, daysBetween(s, u.a)), b = Math.min(len - 1, daysBetween(s, u.b));
            return <span key={u.id} className="db-timeline-mark" title={`Indisponible : ${formatUnavailability(u.a, u.b)}`}
              style={{ left: `${(a / len) * 100}%`, width: `max(4px, ${((b - a + 1) / len) * 100}%)` }} />;
          })}
        </div>
        <div className="db-timeline-labels">
          {months.map(m => <span key={m.key} style={{ flex: m.days }}>{m.label}</span>)}
        </div>
      </div>

      {inside.length > 0 && (
        <div className="db-legend"><span className="db-legend-swatch" />
          {inside.length === 1 ? '1 de vos indisponibilités tombe' : `${inside.length} de vos indisponibilités tombent`} dans cette période
        </div>
      )}

      <div className="db-note">
        <Icon name="moon" size={18} className="db-note-icon" />
        {p.published
          ? <span>Planning publié. <span className="db-note-link" onClick={ev => { ev.preventDefault(); window.location.href = gardesHref; }}>Voir mes gardes</span></span>
          : <span>Planning en préparation. Vos gardes apparaîtront ici dès sa publication.</span>}
      </div>
    </a>
  );
}

function UnavailabilityCard({ upcoming, today, hrefs, onDeclare }: { upcoming: Upcoming[]; today: Date; hrefs: DashboardHrefs; onDeclare: () => void }) {
  const total = upcoming.reduce((n, u) => n + inclusiveDays(u.a, u.b), 0);
  const has = upcoming.length > 0;
  return (
    <section className="db-card" aria-label="Mes indisponibilités">
      <CardHeader
        title="Mes indisponibilités"
        sub={has ? `${plural(upcoming.length, 'période')} · ${total} jours au total` : undefined}
        link={has ? { href: hrefs.calendar, long: 'Calendrier' } : undefined}
      />

      {has ? (
        <ul className="db-list">
          {upcoming.map((u, i) => {
            const n = inclusiveDays(u.a, u.b);
            return (
              <li key={u.id}>
                <a href={hrefs.indispo(u.id)} className="db-row">
                  <span className="db-date-tile" aria-hidden="true">
                    <span className="db-date-tile-day">{u.a.getDate()}</span>
                    <span className="db-date-tile-mon">{monthShort(u.a)}</span>
                  </span>
                  <span className="db-row-text">
                    <span className="db-row-title db-num">{formatUnavailability(u.a, u.b)}</span>
                    <span className="db-muted db-num">{n === 1 ? 'Journée entière' : `${n} jours`}</span>
                  </span>
                  {i === 0 && <span className="db-pill db-pill-warn">{relativeDays(daysBetween(today, u.a))}</span>}
                  <span className="db-chevron"><Icon name="chevronRight" size={18} strokeWidth={2.2} /></span>
                </a>
              </li>
            );
          })}
        </ul>
      ) : (
        <div className="db-empty db-empty-center">
          <span className="db-empty-icon"><Icon name="calendarCheck" size={24} strokeWidth={1.9} /></span>
          <div className="db-empty-title">Aucune indisponibilité à venir</div>
          <div className="db-muted">Vous êtes considéré disponible tous les jours. Déclarez vos absences avant la génération des plannings.</div>
        </div>
      )}

      <div className="db-card-foot">
        <button type="button" className="db-btn" onClick={onDeclare}>
          <Icon name="plus" size={18} strokeWidth={2.2} />Déclarer une indisponibilité
        </button>
        <div className="db-hint">
          <Icon name="users" size={16} />
          <span>Partagées avec toutes vos équipes : une seule déclaration suffit.</span>
        </div>
      </div>
    </section>
  );
}
