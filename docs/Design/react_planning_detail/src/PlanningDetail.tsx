import { useEffect, useMemo, useState, type ReactNode } from 'react';
import type { Collecte, Line, Member, Planning } from './types';
import { daysInclusive, formatLongDate, formatShortDate, monthSegments, monthTitles, periodState, todayISO } from './period';
import { Icon, MoreIcon, type IconName } from './icons';

type Tab = 'lignes' | 'indispos' | 'planning';

export interface PlanningDetailProps {
  planning: Planning;
  lines: Line[];
  members: Member[];
  currentUserId?: string;
  collecte: Collecte | null;
  /** Optionnel : vue du planning généré (affichée dans l'onglet Planning) */
  planningView?: (ctx: { memberId: string | 'all'; monthIndex: number }) => ReactNode;
  initialTab?: Tab;
  onGenerate: () => void;
  onRename: () => void;
  onSettings: () => void;
  onExtend: () => void;
  onAddLine: () => void;
  onManageMembers: (lineId: string) => void;
  onDeleteLine: (lineId: string) => void;
  onOpenCollecte: (deadline: string) => void;
  onRemind: () => void;
  onCloseCollecte: () => void;
}

const initials = (name: string) => {
  const parts = name.split(/\s+/);
  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
};
const lastName = (name: string) => name.split(' ').slice(-1)[0];
const plural = (n: number, w: string) => `${n} ${w}${n > 1 ? 's' : ''}`;

const NAV: { label: string; short: string; icon: IconName; href: string; active?: boolean }[] = [
  { label: 'Tableau de bord', short: 'Accueil', icon: 'home', href: '/tableau-de-bord' },
  { label: 'Plannings', short: 'Plannings', icon: 'layers', href: '/plannings', active: true },
  { label: 'Mes gardes', short: 'Gardes', icon: 'moon', href: '/mes-gardes' },
  { label: 'Mes indisponibilités', short: 'Indispos', icon: 'calendarX', href: '/mes-indisponibilites' },
];

export function PlanningDetail(props: PlanningDetailProps) {
  const { planning, lines, members, currentUserId, collecte } = props;
  const [tab, setTab] = useState<Tab>(props.initialTab ?? 'lignes');
  const [menu, setMenu] = useState<string | null>(null);
  const [sheetOpen, setSheetOpen] = useState(false);

  const activeMembers = members.filter(m => lines.some(l => l.id === m.lineId));
  const totalDays = activeMembers.reduce((a, m) => a + m.unavailableDays, 0);
  const answered = collecte ? activeMembers.filter(m => collecte.respondedIds.includes(m.id)).length : 0;

  const steps = [
    { title: 'Équipe', detail: `${plural(lines.length, 'ligne')} · ${activeMembers.length} membres`, state: 'done', tag: 'Prêt', tab: 'lignes' as Tab },
    collecte
      ? { title: 'Indisponibilités', detail: `${answered}/${activeMembers.length} réponses · échéance ${formatShortDate(collecte.deadline)}`, state: 'current', tag: 'En cours', tab: 'indispos' as Tab }
      : { title: 'Indisponibilités', detail: `${totalDays} jours déclarés par l'équipe`, state: 'done', tag: 'Facultatif', tab: 'indispos' as Tab },
    planning.hasGeneration
      ? { title: 'Génération', detail: 'Planning généré', state: 'done', tag: 'Prêt', tab: 'planning' as Tab }
      : { title: 'Génération', detail: 'Pas encore lancée', state: 'todo', tag: 'À faire', tab: 'planning' as Tab },
  ];

  return (
    <div className="pd-app">
      <aside className="pd-sidebar">
        <Brand />
        <nav className="pd-sidenav">
          {NAV.map(n => (
            <a key={n.href} href={n.href} className="pd-sidenav-item" aria-current={n.active ? 'page' : undefined}>
              <Icon name={n.icon} size={20} strokeWidth={1.9} />{n.label}
            </a>
          ))}
        </nav>
        <div className="pd-account">
          <span className="pd-avatar pd-avatar-me">SF</span>
          <div className="pd-account-text">
            <strong>Samy Ftaita</strong>
            <a href="/compte">Mon compte</a>
          </div>
          <a href="/deconnexion" className="pd-icon-btn" title="Se déconnecter"><Icon name="logout" /></a>
        </div>
      </aside>

      <div className="pd-column">
        <header className="pd-topbar">
          <Brand small />
          <span className="pd-avatar pd-avatar-me">SF</span>
        </header>

        <main className="pd-main">
          <section className="pd-head">
            <a href="/plannings" className="pd-back"><Icon name="chevronLeft" size={16} strokeWidth={2.2} />Plannings</a>
            <div className="pd-head-row">
              <div className="pd-head-title">
                <div className="pd-title-line">
                  <h1>{planning.name}</h1>
                  <span className="pd-badge"><span className="pd-dot" />{planning.status === 'brouillon' ? 'Brouillon' : 'Publié'}</span>
                </div>
                {currentUserId && activeMembers.some(m => m.id === currentUserId) && (
                  <span className="pd-me"><span className="pd-dot pd-dot-brand" />Vous en faites partie</span>
                )}
              </div>
              <div className="pd-head-actions">
                <button className="pd-btn pd-btn-primary pd-btn-lg pd-grow" onClick={() => { setTab('planning'); props.onGenerate(); }}>
                  Générer le planning<Icon name="arrowRight" strokeWidth={2.2} />
                </button>
                <div className="pd-menu-anchor">
                  <button className="pd-btn pd-btn-secondary pd-btn-lg pd-btn-square" aria-label="Plus d'actions" aria-expanded={menu === 'header'} onClick={() => setMenu(menu === 'header' ? null : 'header')}>
                    <MoreIcon />
                  </button>
                  {menu === 'header' && (
                    <Menu onClose={() => setMenu(null)} items={[
                      { label: 'Modifier le nom', icon: 'pencil', onClick: props.onRename },
                      { label: 'Paramètres', icon: 'settings', onClick: props.onSettings },
                    ]} />
                  )}
                </div>
              </div>
            </div>
          </section>

          <PeriodCard planning={planning} onExtend={props.onExtend} />

          <section className="pd-steps">
            {steps.map((s, i) => (
              <button key={s.title} className="pd-step" data-state={s.state} data-selected={tab === s.tab || undefined} onClick={() => setTab(s.tab)}>
                <span className="pd-step-mark">{s.state === 'done' ? '✓' : i + 1}</span>
                <span className="pd-step-body">
                  <span className="pd-step-top">
                    <span className="pd-step-title">{s.title}</span>
                    <span className="pd-step-tag" data-tag={s.tag}>{s.tag}</span>
                  </span>
                  <span className="pd-step-detail">{s.detail}</span>
                </span>
              </button>
            ))}
          </section>

          <section className="pd-tabs-section">
            <div className="pd-tabs" role="tablist">
              <TabButton id="lignes" tab={tab} setTab={setTab} long="Lignes de garde" short="Lignes" count={lines.length} />
              <TabButton id="indispos" tab={tab} setTab={setTab} long="Indisponibilités" short="Indispos" count={activeMembers.length} />
              <TabButton id="planning" tab={tab} setTab={setTab} long="Planning" short="Planning" />
            </div>

            {tab === 'lignes' && (
              <LinesPanel lines={lines} members={activeMembers} currentUserId={currentUserId} menu={menu} setMenu={setMenu}
                onManageMembers={props.onManageMembers} onDeleteLine={props.onDeleteLine} onAddLine={props.onAddLine} />
            )}
            {tab === 'indispos' && (
              <UnavailabilityPanel planning={planning} lines={lines} members={activeMembers} currentUserId={currentUserId} collecte={collecte}
                answered={answered} onOpen={() => setSheetOpen(true)} onRemind={props.onRemind} onClose={props.onCloseCollecte} />
            )}
            {tab === 'planning' && (
              <PlanningPanel planning={planning} members={activeMembers} onGenerate={props.onGenerate} planningView={props.planningView} />
            )}
          </section>
        </main>

        <nav className="pd-bottomnav">
          {NAV.map(n => (
            <a key={n.href} href={n.href} className="pd-bottomnav-item" aria-current={n.active ? 'page' : undefined}>
              <Icon name={n.icon} size={22} strokeWidth={1.9} />{n.short}
            </a>
          ))}
        </nav>
      </div>

      {sheetOpen && (
        <CollecteSheet memberCount={activeMembers.length} today={todayISO(planning.timeZone)}
          onCancel={() => setSheetOpen(false)}
          onConfirm={d => { setSheetOpen(false); props.onOpenCollecte(d); }} />
      )}
    </div>
  );
}

function Brand({ small }: { small?: boolean }) {
  return (
    <div className={small ? 'pd-brand pd-brand-sm' : 'pd-brand'}>
      <span className="pd-brand-mark"><Icon name="pulse" size={small ? 17 : 20} strokeWidth={2.3} /></span>
      <span className="pd-brand-name">MedVue</span>
    </div>
  );
}

function TabButton({ id, tab, setTab, long, short, count }: { id: Tab; tab: Tab; setTab: (t: Tab) => void; long: string; short: string; count?: number }) {
  return (
    <button role="tab" aria-selected={tab === id} className="pd-tab" onClick={() => setTab(id)}>
      <span className="pd-lg">{long}</span><span className="pd-sm">{short}</span>
      {count != null && <span className="pd-tab-count">{count}</span>}
    </button>
  );
}

function Menu({ items, onClose }: { items: { label: string; icon: IconName; onClick: () => void; danger?: boolean }[]; onClose: () => void }) {
  useEffect(() => {
    const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', k);
    return () => window.removeEventListener('keydown', k);
  }, [onClose]);
  return (
    <>
      <div className="pd-menu-scrim" onClick={onClose} />
      <div className="pd-menu" role="menu">
        {items.map(it => (
          <button key={it.label} role="menuitem" className={it.danger ? 'pd-menu-item pd-danger' : 'pd-menu-item'} onClick={() => { onClose(); it.onClick(); }}>
            <Icon name={it.icon} />{it.label}
          </button>
        ))}
      </div>
    </>
  );
}

function PeriodCard({ planning, onExtend }: { planning: Planning; onExtend: () => void }) {
  const total = daysInclusive(planning.start, planning.end);
  const segs = monthSegments(planning.start, planning.end);
  const state = periodState(planning.start, planning.end, todayISO(planning.timeZone));
  return (
    <section className="pd-card pd-period" aria-label="Période du planning">
      <div className="pd-period-top">
        <span className="pd-eyebrow">Période du planning</span>
        <span className="pd-chip" data-kind={state.kind}><span className="pd-dot" />{state.label}</span>
      </div>
      <div className="pd-period-row">
        <div className="pd-period-dates">
          <div className="pd-period-tile">
            <span className="pd-period-label">Du</span>
            <span className="pd-period-value">{formatLongDate(planning.start)}</span>
          </div>
          <span className="pd-period-arrow"><Icon name="arrowRight" size={20} strokeWidth={2.2} /></span>
          <div className="pd-period-tile">
            <span className="pd-period-label">Au (inclus)</span>
            <span className="pd-period-value">{formatLongDate(planning.end)}</span>
          </div>
        </div>
        <div className="pd-period-tile pd-period-duration">
          <span className="pd-period-label">Durée</span>
          <span className="pd-period-value">{total} jours</span>
        </div>
      </div>
      <div className="pd-timeline">
        <div className="pd-timeline-bar">
          {segs.map(s => <span key={s.key} style={{ flex: s.days }} />)}
          {state.kind === 'running' && <span className="pd-timeline-today" style={{ left: `${state.progress * 100}%` }} />}
        </div>
        <div className="pd-timeline-labels">
          {segs.map(s => <span key={s.key} style={{ flex: s.days }}>{s.label}</span>)}
        </div>
      </div>
      <div className="pd-period-foot">
        <span>Fuseau horaire : {planning.timeZone}</span>
        <button className="pd-link" onClick={onExtend}><Icon name="calendarPlus" size={17} />Prolonger la période</button>
      </div>
    </section>
  );
}

function LinesPanel({ lines, members, currentUserId, menu, setMenu, onManageMembers, onDeleteLine, onAddLine }: {
  lines: Line[]; members: Member[]; currentUserId?: string; menu: string | null; setMenu: (m: string | null) => void;
  onManageMembers: (id: string) => void; onDeleteLine: (id: string) => void; onAddLine: () => void;
}) {
  return (
    <div className="pd-stack">
      <p className="pd-help">Chaque ligne est un rôle de garde à couvrir chaque jour. La ligne principale est remplie en priorité.</p>
      <div className="pd-card pd-list">
        {lines.map(l => {
          const lm = members.filter(m => m.lineId === l.id);
          return (
            <div key={l.id} className="pd-line">
              <div className="pd-line-main">
                <div className="pd-line-name">
                  <strong>{l.name}</strong>
                  <span className="pd-kind" data-kind={l.kind}>{l.kind === 'principale' ? 'Principale' : 'Secondaire'}</span>
                </div>
                <div className="pd-line-meta">
                  <span className="pd-faces">
                    {lm.slice(0, 4).map(m => <span key={m.id} className={m.id === currentUserId ? 'pd-face pd-avatar-me' : 'pd-face'}>{initials(m.name)}</span>)}
                    {lm.length > 4 && <span className="pd-face pd-face-more">+{lm.length - 4}</span>}
                  </span>
                  <span className="pd-muted">{plural(lm.length, 'membre')}</span>
                </div>
              </div>
              <div className="pd-line-actions pd-menu-anchor">
                <button className="pd-btn pd-btn-secondary" onClick={() => onManageMembers(l.id)}><Icon name="users" size={17} />Membres</button>
                {l.kind !== 'principale' && (
                  <button className="pd-icon-btn pd-icon-btn-lg" aria-label={`Actions pour ${l.name}`} onClick={() => setMenu(menu === l.id ? null : l.id)}><MoreIcon /></button>
                )}
                {menu === l.id && (
                  <Menu onClose={() => setMenu(null)} items={[{ label: 'Supprimer la ligne', icon: 'trash', danger: true, onClick: () => onDeleteLine(l.id) }]} />
                )}
              </div>
            </div>
          );
        })}
        <button className="pd-add-line" onClick={onAddLine}><Icon name="plus" strokeWidth={2.2} />Ajouter une ligne</button>
      </div>
    </div>
  );
}

function UnavailabilityPanel({ planning, lines, members, currentUserId, collecte, answered, onOpen, onRemind, onClose }: {
  planning: Planning; lines: Line[]; members: Member[]; currentUserId?: string; collecte: Collecte | null; answered: number;
  onOpen: () => void; onRemind: () => void; onClose: () => void;
}) {
  const [query, setQuery] = useState('');
  const [sort, setSort] = useState<'name' | 'days'>('name');
  const total = daysInclusive(planning.start, planning.end);
  const max = Math.max(1, ...members.map(m => m.unavailableDays));
  const rows = useMemo(() => {
    const q = query.trim().toLowerCase();
    return members
      .filter(m => !q || m.name.toLowerCase().includes(q))
      .sort((a, b) => sort === 'days' ? b.unavailableDays - a.unavailableDays : lastName(a.name).localeCompare(lastName(b.name), 'fr'));
  }, [members, query, sort]);
  const pending = members.length - answered;

  return (
    <div className="pd-stack">
      {collecte ? (
        <div className="pd-card pd-collecte pd-collecte-open">
          <div className="pd-collecte-head">
            <strong>Collecte en cours · échéance {formatShortDate(collecte.deadline)}</strong>
            <span className="pd-collecte-count">{answered}/{members.length} réponses</span>
          </div>
          <div className="pd-progress"><span style={{ width: `${Math.round(answered / Math.max(1, members.length) * 100)}%` }} /></div>
          <div className="pd-row-actions">
            <button className="pd-btn pd-btn-secondary" onClick={onRemind} disabled={!!collecte.remindedAt || pending === 0}>
              {collecte.remindedAt ? 'Relance envoyée' : `Relancer les ${pending} en attente`}
            </button>
            <button className="pd-btn pd-btn-ghost" onClick={onClose}>Clôturer</button>
          </div>
        </div>
      ) : (
        <div className="pd-card pd-collecte">
          <div className="pd-collecte-text">
            <strong>Aucune collecte en cours</strong>
            <span>Les membres déclarent leurs indisponibilités quand ils veulent. Ouvrez une collecte pour leur demander de répondre avant une date précise.</span>
          </div>
          <button className="pd-btn pd-btn-secondary" onClick={onOpen}>Ouvrir une collecte</button>
        </div>
      )}

      <div className="pd-toolbar">
        <label className="pd-search">
          <Icon name="search" />
          <input value={query} onChange={e => setQuery(e.target.value)} placeholder="Rechercher un membre" aria-label="Rechercher un membre" />
        </label>
        <div className="pd-segmented" role="group" aria-label="Trier">
          <button aria-pressed={sort === 'name'} onClick={() => setSort('name')}>Nom</button>
          <button aria-pressed={sort === 'days'} onClick={() => setSort('days')}>Indispos</button>
        </div>
      </div>

      <div className="pd-card pd-list">
        {rows.map(m => {
          const me = m.id === currentUserId;
          const responded = collecte?.respondedIds.includes(m.id);
          return (
            <div key={m.id} className="pd-member">
              <span className={me ? 'pd-avatar pd-avatar-me' : 'pd-avatar'}>{initials(m.name)}</span>
              <div className="pd-member-name">
                <strong>{m.name}{me ? ' (vous)' : ''}</strong>
                <span>{lines.find(l => l.id === m.lineId)?.name}</span>
              </div>
              <div className="pd-member-days">
                <div className="pd-bar"><span style={{ width: `${Math.round(m.unavailableDays / max * 100)}%` }} /></div>
                <span className="pd-num">{m.unavailableDays} j</span>
              </div>
              {collecte && (
                <span className="pd-status" data-ok={responded || undefined}>
                  {responded ? 'Répondu' : collecte.remindedAt ? 'Relancé' : 'En attente'}
                </span>
              )}
            </div>
          );
        })}
        {rows.length === 0 && <div className="pd-empty-row">Aucun membre ne correspond à « {query} ».</div>}
      </div>
      <p className="pd-footnote">Jours d'indisponibilité déclarés sur les {total} jours de la période.</p>
    </div>
  );
}

function PlanningPanel({ planning, members, onGenerate, planningView }: {
  planning: Planning; members: Member[]; onGenerate: () => void; planningView?: PlanningDetailProps['planningView'];
}) {
  const months = monthTitles(planning.start, planning.end);
  const [month, setMonth] = useState(0);
  const [who, setWho] = useState<string | 'all'>('all');
  return (
    <div className="pd-stack">
      <div className="pd-toolbar">
        <select className="pd-select" value={who} onChange={e => setWho(e.target.value)} aria-label="Afficher">
          <option value="all">Toute l'équipe</option>
          {members.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
        <div className="pd-month-nav">
          <button className="pd-icon-btn" aria-label="Mois précédent" disabled={month === 0} onClick={() => setMonth(m => m - 1)}><Icon name="chevronLeft" strokeWidth={2.2} /></button>
          <strong>{months[month]}</strong>
          <button className="pd-icon-btn" aria-label="Mois suivant" disabled={month === months.length - 1} onClick={() => setMonth(m => m + 1)}><Icon name="chevronRight" strokeWidth={2.2} /></button>
        </div>
      </div>
      {planning.hasGeneration && planningView ? planningView({ memberId: who, monthIndex: month }) : (
        <div className="pd-card pd-empty">
          <span className="pd-empty-icon"><Icon name="calendar" size={24} strokeWidth={1.9} /></span>
          <strong>Pas encore de planning</strong>
          <span>Lancez la génération : les gardes de chaque membre s'afficheront ici, mois par mois.</span>
          <button className="pd-btn pd-btn-primary" onClick={onGenerate}>Générer le planning</button>
        </div>
      )}
    </div>
  );
}

function CollecteSheet({ memberCount, today, onCancel, onConfirm }: { memberCount: number; today: string; onCancel: () => void; onConfirm: (deadline: string) => void }) {
  const [deadline, setDeadline] = useState('');
  useEffect(() => {
    const k = (e: KeyboardEvent) => e.key === 'Escape' && onCancel();
    window.addEventListener('keydown', k);
    return () => window.removeEventListener('keydown', k);
  }, [onCancel]);
  return (
    <div className="pd-overlay" onClick={onCancel}>
      <div className="pd-sheet" role="dialog" aria-modal="true" aria-labelledby="pd-sheet-title" onClick={e => e.stopPropagation()}>
        <div className="pd-sheet-head">
          <h2 id="pd-sheet-title">Ouvrir une collecte</h2>
          <button className="pd-icon-btn" aria-label="Fermer" onClick={onCancel}><Icon name="close" size={20} strokeWidth={2.2} /></button>
        </div>
        <p className="pd-help">Les {memberCount} membres seront invités à compléter leurs indisponibilités pour la période du planning.</p>
        <label className="pd-field">
          <span>Répondre avant le</span>
          <input type="date" min={today} value={deadline} onChange={e => setDeadline(e.target.value)} />
        </label>
        <button className="pd-btn pd-btn-primary pd-btn-lg pd-full" disabled={!deadline} onClick={() => onConfirm(deadline)}>Ouvrir la collecte</button>
      </div>
    </div>
  );
}
