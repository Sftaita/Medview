import type { Period } from '../types';
import { formatDate, formatRange } from '../lib/dates';
import { STATUS_LABEL } from '../lib/periods';
import { Icon } from './Icon';

interface Props {
  period: Period;
  memberCount: number;
  canManage: boolean;
  onOpenCollecte: () => void;
}

type StepIcon = 'num' | 'check' | 'partial';
interface Step { key: string; icon: StepIcon; num: number; title: string; badge: string; tone: string; sub: string; active?: boolean; cta?: boolean }

/** Étapes propres à la période : collecte → génération → publication. Cercles numérotés, jamais une barre. */
export function PreparationSteps({ period, memberCount, canManage, onOpenCollecte }: Props) {
  const c = period.collecte;
  const steps: Step[] = [
    c.state === 'non-ouverte'
      ? { key: 'dispo', icon: 'num', num: 1, title: 'Disponibilités', badge: 'À ouvrir', tone: 'warn', sub: `Pour le ${formatRange(period.start, period.end)}`, active: true, cta: canManage }
      : c.state === 'en-cours'
        ? { key: 'dispo', icon: 'partial', num: 1, title: 'Disponibilités', badge: 'Collecte en cours', tone: 'warn', sub: `${c.responses} / ${memberCount} réponses${c.closesOn ? ` · clôture le ${formatDate(c.closesOn, 'dm')}` : ''}`, active: true }
        : { key: 'dispo', icon: 'check', num: 1, title: 'Disponibilités', badge: 'Clôturée', tone: 'neutral', sub: `${c.responses} / ${memberCount} réponses (${Math.round((c.responses / memberCount) * 100)} %)` },
    period.generatedOn
      ? { key: 'gen', icon: 'check', num: 2, title: 'Génération', badge: 'Générée', tone: 'info', sub: `Le ${formatDate(period.generatedOn)}` }
      : { key: 'gen', icon: 'num', num: 2, title: 'Génération', badge: 'En attente', tone: 'neutral', sub: 'Après la clôture de la collecte' },
    period.publishedOn
      ? { key: 'pub', icon: 'check', num: 3, title: 'Publication', badge: STATUS_LABEL[period.status], tone: period.status === 'archivee' ? 'archive' : 'ok', sub: `Publiée le ${formatDate(period.publishedOn)}` }
      : { key: 'pub', icon: 'num', num: 3, title: 'Publication', badge: 'Non publiée', tone: 'neutral', sub: 'Visible par l’équipe une fois publiée' },
  ];

  return (
    <section className="mv-steps" aria-label={`Préparation de la période ${period.number}`}>
      <div className="mv-section-title">
        <h2>Préparation de la période {period.number}</h2>
        <span className="mv-muted">Chaque période a sa propre collecte, sa génération et sa publication.</span>
      </div>
      <ol className="mv-steps__list">
        {steps.map((s) => (
          <li key={s.key} className={`mv-step ${s.active ? 'is-active' : ''}`}>
            <span className={`mv-step__icon mv-step__icon--${s.icon}`} aria-hidden="true">
              {s.icon === 'check' ? <Icon name="check" strokeWidth={3} /> : s.icon === 'partial' ? <Icon name="clock" strokeWidth={2.4} /> : s.num}
            </span>
            <div className="mv-step__body">
              <div className="mv-step__top">
                <strong>{s.title}</strong>
                <span className={`mv-badge mv-badge--${s.tone}`}>{s.badge}</span>
              </div>
              <span className="mv-step__sub">{s.sub}</span>
              {s.cta && (
                <button type="button" className="mv-btn mv-btn--primary mv-btn--sm" onClick={onOpenCollecte}>Ouvrir la collecte</button>
              )}
            </div>
          </li>
        ))}
      </ol>
    </section>
  );
}
