import { useEffect, useMemo, useRef, useState } from 'react';
import type { ISODate, Period, Planning, Shift } from '../types';
import { addDays, formatRange } from '../lib/dates';
import { buildNextPeriod, currentIndex } from '../lib/periods';
import { PeriodSelector } from '../components/PeriodSelector';
import { PeriodDetailCard } from '../components/PeriodDetailCard';
import { PreparationSteps } from '../components/PreparationSteps';
import { PeriodTabs } from '../components/PeriodTabs';
import { CreatePeriodDialog } from '../components/CreatePeriodDialog';
import { Icon } from '../components/Icon';

interface Props {
  planning: Planning;
  today: ISODate;
  /** Gardes d’une période (API). */
  getShifts: (planning: Planning, period: Period) => Shift[];
  /** Période affichée à l’ouverture (par défaut : la dernière si en préparation, sinon l’en cours). */
  initialPeriodId?: string;
  onBack: () => void;
  /** Branchement backend : renvoie la période créée (avec son id serveur). */
  onCreatePeriod?: (draft: Period) => Promise<Period> | Period;
  onOpenCollecte?: (period: Period) => Promise<Period> | Period;
}

export function PlanningDetailPage({ planning, today, getShifts, initialPeriodId, onBack, onCreatePeriod, onOpenCollecte }: Props) {
  const [periods, setPeriods] = useState<Period[]>(planning.periods);
  const [selectedId, setSelectedId] = useState<string>(() => defaultSelection(planning.periods, today, initialPeriodId));
  const [dialogOpen, setDialogOpen] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const toastTimer = useRef<number>();

  useEffect(() => {
    setPeriods(planning.periods);
    setSelectedId(defaultSelection(planning.periods, today, initialPeriodId));
  }, [planning, today, initialPeriodId]);

  const idx = Math.max(0, periods.findIndex((p) => p.id === selectedId));
  const period = periods[idx];
  const latest = periods[periods.length - 1];
  const canManage = planning.role !== 'Membre';
  const shifts = useMemo(() => getShifts(planning, period), [getShifts, planning, period]);
  const respondedIds = planning.members.slice(0, period.collecte.responses).map((m) => m.id);

  const showToast = (msg: string) => {
    setToast(msg);
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4500);
  };

  const handleCreate = async (end: ISODate) => {
    const draft = buildNextPeriod(periods, end, `tmp-${Date.now()}`);
    const saved = onCreatePeriod ? await onCreatePeriod(draft) : draft;
    setPeriods((ps) => [...ps, saved]);
    setSelectedId(saved.id);
    setDialogOpen(false);
    showToast(`Période ${saved.number} créée · ${formatRange(saved.start, saved.end)}`);
  };

  const handleOpenCollecte = async () => {
    const opened: Period = { ...period, status: 'collecte', collecte: { state: 'en-cours', responses: 0, closesOn: addDays(today, 21) } };
    const saved = onOpenCollecte ? await onOpenCollecte(opened) : opened;
    setPeriods((ps) => ps.map((p) => (p.id === saved.id ? saved : p)));
  };

  return (
    <div className="mv-page">
      <header className="mv-detail-head">
        <button type="button" className="mv-back" onClick={onBack}><Icon name="chevronLeft" size={16} /> Plannings</button>
        <div className="mv-detail-head__row">
          <div>
            <div className="mv-detail-head__title">
              <h1>{planning.name}</h1>
              {planning.active && <span className="mv-chip mv-chip--outline"><i className="mv-live-dot" />Actif</span>}
            </div>
            <p className="mv-member-line"><i className="mv-live-dot" />Vous en faites partie</p>
          </div>
          <button type="button" className="mv-icon-btn mv-icon-btn--card" aria-label="Plus d’actions"><Icon name="more" size={22} strokeWidth={2.4} /></button>
        </div>
        <div className="mv-shared">
          <span className="mv-eyebrow">Commun à toutes les périodes</span>
          <span className="mv-pill-link"><Icon name="lines" size={16} />{planning.lines.length} lignes de garde</span>
          <span className="mv-pill-link"><Icon name="users" size={16} />{planning.members.length} membres</span>
          <span className="mv-pill-link"><Icon name="globe" size={16} />{planning.timezone}</span>
        </div>
      </header>

      <section className="mv-card mv-periods-card" aria-label="Périodes du planning">
        <div className="mv-periods-card__head">
          <div className="mv-row mv-row--baseline">
            <h2 className="mv-eyebrow mv-eyebrow--strong">Périodes du planning</h2>
            <span className="mv-muted mv-small mv-hide-mobile">Cliquez sur une période pour la consulter</span>
          </div>
          {canManage && (
            <button type="button" className="mv-btn mv-btn--primary mv-hide-mobile" onClick={() => setDialogOpen(true)}>
              <Icon name="plus" strokeWidth={2.2} /> Créer la période suivante
            </button>
          )}
        </div>

        <PeriodSelector periods={periods} selectedId={period.id} today={today} canCreate={canManage}
          onSelect={setSelectedId} onCreate={() => setDialogOpen(true)} />

        <PeriodDetailCard period={period} today={today} latestNumber={latest.number}
          lineCount={planning.lines.length} memberCount={planning.members.length}
          hasPrev={idx > 0} hasNext={idx < periods.length - 1}
          onPrev={() => setSelectedId(periods[idx - 1].id)} onNext={() => setSelectedId(periods[idx + 1].id)}
          onGoLatest={() => setSelectedId(latest.id)} />
      </section>

      <PreparationSteps period={period} memberCount={planning.members.length} canManage={canManage} onOpenCollecte={handleOpenCollecte} />

      <PeriodTabs planning={planning} period={period} today={today} shifts={shifts} respondedIds={respondedIds}
        canManage={canManage} onOpenCollecte={handleOpenCollecte} />

      {canManage && (
        <div className="mv-mobile-action">
          <button type="button" className="mv-btn mv-btn--primary mv-btn--block" onClick={() => setDialogOpen(true)}>
            <Icon name="plus" strokeWidth={2.2} /> Créer la période suivante
          </button>
        </div>
      )}

      <CreatePeriodDialog open={dialogOpen} planningName={planning.name} lineCount={planning.lines.length}
        memberCount={planning.members.length} periods={periods}
        onCancel={() => setDialogOpen(false)} onCreate={handleCreate} />

      {toast && <div className="mv-toast" role="status"><Icon name="check" strokeWidth={2.6} />{toast}</div>}
    </div>
  );
}

function defaultSelection(periods: Period[], today: ISODate, initialId?: string): string {
  if (initialId && periods.some((p) => p.id === initialId)) return initialId;
  const last = periods[periods.length - 1];
  if (last?.status === 'preparation') return last.id;
  return periods[currentIndex(periods, today)]?.id ?? '';
}
