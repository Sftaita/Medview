import { Link } from 'react-router-dom'
import type { ISODate, Period, PlanningRole } from '../types'
import { formatRange, timeState } from '../dates'
import { StatusPill } from './StatusPill'
import { TimeBar } from './TimeBar'
import { Icon } from './Icon'

/** What a card shows of a planning (GET /api/plannings, docs/planning-cycles.md §7.1). */
export interface PlanningCardData {
  id: string
  name: string
  role: PlanningRole
  lineCount?: number
  memberCount?: number
  periodCount: number
  /** The period that contains today — else the next one, else the last (never empty). */
  main: Period
  /** The period after `main`, when it is the current one and a next one exists. */
  next?: Period
}

interface Props {
  planning: PlanningCardData
  today: ISODate
}

const plural = (n: number, one: string, many: string) => `${n} ${n > 1 ? many : one}`

/**
 * Carte « Mes plannings » : une seule carte par planning, quel que soit son nombre de périodes.
 * Montre uniquement la période en cours (ou à venir, ou la dernière) et la suivante — jamais l’historique,
 * ni un statut « Publié » global : chaque période a le sien.
 */
export function PlanningCard({ planning, today }: Props) {
  const single = planning.periodCount === 1
  const meta = [
    planning.lineCount !== undefined ? plural(planning.lineCount, 'ligne de garde', 'lignes de garde') : null,
    planning.memberCount !== undefined ? plural(planning.memberCount, 'membre', 'membres') : null,
    single ? null : plural(planning.periodCount, 'période', 'périodes'),
  ].filter(Boolean)

  return (
    <article className="mv-card mv-planning-card">
      <header className="mv-planning-card__head">
        <div>
          <div className="mv-planning-card__title">
            <h2>{planning.name}</h2>
            <span className={`mv-chip ${planning.role === 'Propriétaire' ? 'mv-chip--brand' : ''}`}>
              {planning.role}
            </span>
          </div>
          <p className="mv-planning-card__meta">{meta.join(' · ')}</p>
        </div>
        <Link
          to={`/plannings/${planning.id}`}
          className="mv-btn mv-btn--secondary"
          aria-label={`Ouvrir le planning ${planning.name}`}
        >
          Ouvrir le planning <Icon name="chevronRight" />
        </Link>
      </header>

      <div className={`mv-planning-card__periods ${planning.next ? 'is-split' : ''}`}>
        <CurrentBlock period={planning.main} today={today} single={single} />
        {planning.next && <NextBlock period={planning.next} today={today} />}
      </div>
    </article>
  )
}

function CurrentBlock({ period, today, single }: { period: Period; today: ISODate; single: boolean }) {
  const t = timeState(period, today)
  const when = t.kind === 'current' ? 'en cours' : t.kind === 'past' ? 'terminée' : 'à venir'
  return (
    <section className="mv-period-block">
      <div className="mv-period-block__top">
        <span className="mv-eyebrow">
          {single ? `Période ${when}` : `Période ${period.number} · ${when}`}
        </span>
        <StatusPill status={period.status} size="sm" />
      </div>
      <div className="mv-period-block__range">{formatRange(period.start, period.end)}</div>
      <TimeBar kind={t.kind} elapsedPct={t.elapsedPct} />
      <div className="mv-period-block__foot">
        <span>{t.kind === 'current' ? `Jour ${t.dayIndex} sur ${t.days}` : t.label}</span>
        {t.kind === 'current' && (
          <span className="mv-muted">{t.days - (t.dayIndex ?? 0)} jours restants</span>
        )}
      </div>
    </section>
  )
}

function NextBlock({ period, today }: { period: Period; today: ISODate }) {
  const t = timeState(period, today)
  const c = period.collecte
  const nextAction =
    period.status === 'publiee'
      ? 'Gardes publiées.'
      : period.status === 'partielle'
        ? 'Certaines lignes sont publiées.'
        : period.status === 'generee'
          ? 'Gardes générées, pas encore publiées.'
          : c.state === 'en-cours'
            ? `${c.responses} / ${c.expected ?? 0} réponses à la collecte des disponibilités.`
            : c.state === 'cloturee'
              ? 'Collecte clôturée.'
              : 'Prochaine étape : ouvrir la collecte des disponibilités.'
  return (
    <section className="mv-period-block mv-period-block--next">
      <div className="mv-period-block__top">
        <span className="mv-eyebrow">Période {period.number} · suivante</span>
        <StatusPill status={period.status} size="sm" />
      </div>
      <div className="mv-period-block__range">{formatRange(period.start, period.end)}</div>
      <div className="mv-period-block__soon">
        <Icon name="clock" size={16} /> {t.label}
      </div>
      <div className="mv-muted mv-small">{nextAction}</div>
    </section>
  )
}
