import type { ISODate, Period, Planning } from '../types';
import { formatRange, timeState } from '../lib/dates';
import { currentAndNext } from '../lib/periods';
import { StatusPill } from './StatusPill';
import { TimeBar } from './TimeBar';
import { Icon } from './Icon';

interface Props {
  planning: Planning;
  today: ISODate;
  onOpen: (planningId: string) => void;
}

/**
 * Carte « Mes plannings » : une seule carte par planning.
 * Montre uniquement la période en cours et la suivante si elle existe — jamais l’historique.
 */
export function PlanningCard({ planning, today, onOpen }: Props) {
  const { current, next } = currentAndNext(planning.periods, today);
  const lines = planning.lines.length;

  return (
    <article className="mv-card mv-planning-card">
      <header className="mv-planning-card__head">
        <div>
          <div className="mv-planning-card__title">
            <h2>{planning.name}</h2>
            <span className={`mv-chip ${planning.role === 'Propriétaire' ? 'mv-chip--brand' : ''}`}>{planning.role}</span>
          </div>
          <p className="mv-planning-card__meta">
            {lines} {lines > 1 ? 'lignes de garde' : 'ligne de garde'} · {planning.members.length} membres
          </p>
        </div>
        <button type="button" className="mv-btn mv-btn--secondary" onClick={() => onOpen(planning.id)}>
          Ouvrir le planning <Icon name="chevronRight" />
        </button>
      </header>

      {current && (
        <div className={`mv-planning-card__periods ${next ? 'is-split' : ''}`}>
          <CurrentBlock period={current} today={today} />
          {next && <NextBlock period={next} today={today} />}
        </div>
      )}
    </article>
  );
}

function CurrentBlock({ period, today }: { period: Period; today: ISODate }) {
  const t = timeState(period, today);
  const eyebrow = t.kind === 'current' ? 'en cours' : t.kind === 'past' ? 'terminée' : 'à venir';
  return (
    <section className="mv-period-block">
      <div className="mv-period-block__top">
        <span className="mv-eyebrow">Période {period.number} · {eyebrow}</span>
        <StatusPill status={period.status} size="sm" />
      </div>
      <div className="mv-period-block__range">{formatRange(period.start, period.end)}</div>
      <TimeBar kind={t.kind} elapsedPct={t.elapsedPct} />
      <div className="mv-period-block__foot">
        <span>{t.kind === 'current' ? `Jour ${t.dayIndex} sur ${t.days}` : t.label}</span>
        {t.kind === 'current' && <span className="mv-muted">{t.days - (t.dayIndex ?? 0)} jours restants</span>}
      </div>
    </section>
  );
}

function NextBlock({ period, today }: { period: Period; today: ISODate }) {
  const t = timeState(period, today);
  const nextAction =
    period.collecte.state === 'non-ouverte' ? 'Prochaine étape : ouvrir la collecte des disponibilités.'
    : period.collecte.state === 'en-cours' ? `${period.collecte.responses} membres ont répondu à la collecte.`
    : 'Collecte clôturée.';
  return (
    <section className="mv-period-block mv-period-block--next">
      <div className="mv-period-block__top">
        <span className="mv-eyebrow">Période {period.number} · suivante</span>
        <StatusPill status={period.status} size="sm" />
      </div>
      <div className="mv-period-block__range">{formatRange(period.start, period.end)}</div>
      <div className="mv-period-block__soon"><Icon name="clock" size={16} /> {t.label}</div>
      <div className="mv-muted mv-small">{nextAction}</div>
    </section>
  );
}
