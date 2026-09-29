import { Icon, type IconName } from './icons';
import type { Planning, PlanningStep, PlanningsHrefs, User } from './types';
import { cap, daysBetween, formatDay, inclusiveDays, monthSegments, parseISO, startOfToday } from './dates';

export const defaultHrefs: PlanningsHrefs = {
  dashboard: '/',
  plannings: '/plannings',
  planning: id => `/plannings/${id}`,
  gardes: '/mes-gardes',
  indispos: '/mes-indisponibilites',
  account: '/compte',
};

export interface MesPlanningsProps {
  user: User;
  plannings: Planning[];
  /** Date de référence (tests, storybook). Par défaut : aujourd'hui. */
  today?: Date;
  hrefs?: Partial<PlanningsHrefs>;
  onCreate: () => void;
  onLogout: () => void;
}

type NavKey = 'dashboard' | 'plannings' | 'gardes' | 'indispos';
const NAV: { key: NavKey; label: string; short: string; icon: IconName }[] = [
  { key: 'dashboard', label: 'Tableau de bord', short: 'Accueil', icon: 'home' },
  { key: 'plannings', label: 'Plannings', short: 'Plannings', icon: 'layers' },
  { key: 'gardes', label: 'Mes gardes', short: 'Gardes', icon: 'moon' },
  { key: 'indispos', label: 'Mes indisponibilités', short: 'Indispos', icon: 'calendarX' },
];

type Phase = 'live' | 'upcoming' | 'done';
const GROUPS: { phase: Phase; title: string }[] = [
  { phase: 'live', title: 'En cours' },
  { phase: 'upcoming', title: 'À venir' },
  { phase: 'done', title: 'Terminés' },
];
const STEP_LABEL: Record<PlanningStep, string> = {
  draft: 'À générer',
  collect: 'Collecte des indispos ouverte',
  published: 'Publié',
};

const plural = (n: number, s: string, p = s + 's') => `${n} ${n > 1 ? p : s}`;

function phaseOf(p: Planning, today: Date): Phase {
  if (parseISO(p.start) > today) return 'upcoming';
  if (parseISO(p.end) >= today) return 'live';
  return 'done';
}

export function MesPlannings({ user, plannings, today: todayProp, hrefs: hrefsProp, onCreate, onLogout }: MesPlanningsProps) {
  const today = todayProp ?? startOfToday();
  const hrefs = { ...defaultHrefs, ...hrefsProp };
  const initials = (user.firstName[0] + user.lastName[0]).toUpperCase();

  const groups = GROUPS.map(g => ({
    ...g,
    items: plannings
      .filter(p => phaseOf(p, today) === g.phase)
      .sort((a, b) => (g.phase === 'done' ? -1 : 1) * a.start.localeCompare(b.start)),
  })).filter(g => g.items.length > 0);
  const showGroupTitles = plannings.length > 1;

  return (
    <div className="mp">
      <aside className="mp-side">
        <Brand />
        <nav className="mp-side-nav" aria-label="Navigation principale">
          {NAV.map(n => (
            <a key={n.key} href={hrefs[n.key]} className="mp-side-link" aria-current={n.key === 'plannings' ? 'page' : undefined}>
              <Icon name={n.icon} size={20} strokeWidth={1.9} />{n.label}
            </a>
          ))}
        </nav>
        <div className="mp-side-user">
          <span className="mp-avatar">{initials}</span>
          <div className="mp-side-user-text">
            <div className="mp-side-user-name">{user.firstName} {user.lastName}</div>
            <a href={hrefs.account}>Mon compte</a>
          </div>
          <button type="button" className="mp-icon-btn" onClick={onLogout} aria-label="Se déconnecter" title="Se déconnecter">
            <Icon name="logout" size={18} />
          </button>
        </div>
      </aside>

      <div className="mp-body">
        <header className="mp-topbar">
          <Brand compact />
          <a href={hrefs.account} className="mp-avatar mp-avatar-sm" aria-label="Mon compte">{initials}</a>
        </header>

        <main className="mp-main">
          <section className="mp-head">
            <div className="mp-head-text">
              <h1>Mes plannings</h1>
              <p>Une période de garde et ses lignes. Chaque ligne appartient à une équipe.</p>
            </div>
            <button type="button" className="mp-btn" onClick={onCreate}>
              <Icon name="plus" size={18} strokeWidth={2.4} />Créer un planning
            </button>
          </section>

          {plannings.length === 0 ? (
            <section className="mp-empty">
              <span className="mp-empty-icon"><Icon name="layers" size={26} strokeWidth={1.9} /></span>
              <div className="mp-empty-title">Aucun planning</div>
              <div className="mp-empty-text">Créez un planning pour définir la période de garde, ajouter les lignes et inviter les membres.</div>
            </section>
          ) : (
            <div className="mp-groups">
              {groups.map(g => (
                <section key={g.phase} className="mp-group" aria-label={g.title}>
                  {showGroupTitles && (
                    <h2 className="mp-group-title">{g.title}<span className="mp-count">{g.items.length}</span></h2>
                  )}
                  <div className="mp-list">
                    {g.items.map(p => <PlanningCard key={p.id} p={p} today={today} href={hrefs.planning(p.id)} />)}
                  </div>
                </section>
              ))}
            </div>
          )}
        </main>

        <nav className="mp-bottom" aria-label="Navigation principale">
          {NAV.map(n => (
            <a key={n.key} href={hrefs[n.key]} className="mp-bottom-link" aria-current={n.key === 'plannings' ? 'page' : undefined}>
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
    <div className={compact ? 'mp-brand mp-brand-sm' : 'mp-brand'}>
      <span className="mp-brand-mark"><Icon name="pulse" size={compact ? 17 : 20} strokeWidth={2.3} /></span>
      <span className="mp-brand-name">MedVue</span>
    </div>
  );
}

function PlanningCard({ p, today, href }: { p: Planning; today: Date; href: string }) {
  const s = parseISO(p.start), e = parseISO(p.end);
  const len = inclusiveDays(s, e);
  const until = daysBetween(today, s);
  const left = daysBetween(today, e);
  const phase = phaseOf(p, today);
  const elapsed = phase === 'done' ? len : phase === 'live' ? daysBetween(s, today) + 1 : 0;

  const status =
    phase === 'upcoming' ? (until === 1 ? 'Commence demain' : `Commence dans ${until} jours`)
    : phase === 'live' ? `En cours · jour ${elapsed} sur ${len}`
    : 'Terminé';
  const [bigNum, bigLabel] =
    phase === 'upcoming' ? [`J-${until}`, 'avant le début']
    : phase === 'live' ? [String(left + 1), left === 0 ? 'dernier jour' : 'jours restants']
    : [String(len), 'jours'];

  const months = monthSegments(s, e);
  let acc = 0;

  return (
    <a href={href} className={`mp-card mp-card-${phase}`}>
      <div className="mp-card-top">
        <div className="mp-card-info">
          <div className="mp-card-title">
            <span className="mp-card-name">{p.name}</span>
            <span className={`mp-pill mp-pill-${phase}`}><span className="mp-dot" />{status}</span>
          </div>
          <div className="mp-card-range">
            <Icon name="calendar" size={17} className="mp-muted-icon" />
            <span className="mp-long">{cap(formatDay(s, { year: true }))} → {formatDay(e, { year: true })}</span>
            <span className="mp-short">{formatDay(s, { year: true, weekday: false })} → {formatDay(e, { year: true, weekday: false })}</span>
          </div>
        </div>
        <div className="mp-card-aside">
          <div className="mp-stat">
            <span className="mp-stat-num">{bigNum}</span>
            <span className="mp-stat-label">{bigLabel}</span>
          </div>
          <span className="mp-chevron"><Icon name="chevronRight" size={20} strokeWidth={2.2} /></span>
        </div>
      </div>

      <div className="mp-timeline" aria-hidden="true">
        <div className="mp-timeline-bar">
          {months.map((m, i) => {
            const from = acc; acc += m.days;
            const pct = Math.max(0, Math.min(1, (elapsed - from) / m.days)) * 100;
            const first = i === 0, last = i === months.length - 1;
            return (
              <span key={m.key} className="mp-seg" style={{
                flex: m.days,
                ['--fill' as string]: `${pct}%`,
                borderRadius: `${first ? 999 : 2}px ${last ? 999 : 2}px ${last ? 999 : 2}px ${first ? 999 : 2}px`,
              }} />
            );
          })}
          {phase === 'live' && <span className="mp-today" title="Aujourd'hui" style={{ left: `calc(${(elapsed / len) * 100}% - 1.5px)` }} />}
        </div>
        <div className="mp-timeline-labels">
          {months.map(m => <span key={m.key} style={{ flex: m.days }}>{m.label}</span>)}
        </div>
      </div>

      <div className="mp-card-foot">
        <span className="mp-meta"><Icon name="clock" size={16} />{len} jours</span>
        <span className="mp-meta"><Icon name="rows" size={16} />{plural(p.lineCount, 'ligne')}</span>
        <span className="mp-meta"><Icon name="users" size={16} />{plural(p.memberCount, 'membre')}</span>
        <span className={`mp-step mp-step-${p.step}`}><span className="mp-step-dot" />{STEP_LABEL[p.step]}</span>
      </div>
    </a>
  );
}
