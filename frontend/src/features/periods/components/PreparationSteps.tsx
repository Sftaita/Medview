import { useState } from 'react'
import type { ISODate, Period } from '../types'
import { formatDate, formatRange } from '../dates'
import type { PeriodLineState } from '../model'
import { Icon } from './Icon'

interface Props {
  period: Period
  /** May open a collection for this period (right + MEDVUE_PERIOD_CREATION_ENABLED, never offered otherwise). */
  canOpenCollection: boolean
  /** Opens the collection; rejects with the message to show. */
  onOpenCollecte: () => Promise<void>
  /** Each line in this period (docs/decisions.md D194). */
  lines?: PeriodLineState[]
}

type StepIcon = 'num' | 'check' | 'partial'
interface Step {
  key: string
  icon: StepIcon
  num: number
  title: string
  badge: string
  tone: string
  sub: string
  active?: boolean
  cta?: string
}

const plural = (n: number, word: string) => `${n} ${word}${n > 1 ? 's' : ''}`

function todayISO(): ISODate {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** The indicative deadline, never shown as an upcoming step once past (docs/decisions.md D214). */
function deadlineText(deadline: ISODate | undefined, today: ISODate): string | undefined {
  if (!deadline) return undefined
  return deadline >= today
    ? `Date limite indicative le ${formatDate(deadline, 'dm')}`
    : `Date limite indicative passée (le ${formatDate(deadline, 'dm')}) · calendriers toujours modifiables`
}

/** Étapes propres à la période : collecte → génération → publication. Cercles numérotés, jamais une barre. */
export function PreparationSteps({ period, canOpenCollection, onOpenCollecte, lines = [] }: Props) {
  const [opening, setOpening] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const c = period.collecte
  const uncovered = c.uncoveredDays ?? 0
  const today = todayISO()

  const dispo: Step =
    c.state === 'programmee'
      ? {
          key: 'dispo',
          icon: 'num',
          num: 1,
          title: 'Disponibilités',
          badge: 'Annonce programmée',
          tone: 'neutral',
          sub: [
            c.announceOn
              ? `Annonce aux participants le ${formatDate(c.announceOn)}`
              : 'Annonce aux participants programmée',
            deadlineText(c.closesOn, today),
          ]
            .filter(Boolean)
            .join(' · '),
          active: true,
        }
      : c.state === 'en-cours'
        ? {
            key: 'dispo',
            icon: 'partial',
            num: 1,
            title: 'Disponibilités',
            badge: 'Collecte ouverte',
            tone: 'warn',
            sub: deadlineText(c.closesOn, today) ?? 'Sans indisponibilité renseignée, chacun est disponible',
            active: true,
          }
        : c.state === 'cloturee'
          ? {
              key: 'dispo',
              icon: 'check',
              num: 1,
              title: 'Disponibilités',
              badge: 'Clôturée',
              tone: 'neutral',
              sub:
                uncovered > 0
                  ? `Collecte clôturée · ${plural(uncovered, 'jour')} sans collecte`
                  : 'Collecte clôturée',
              cta: canOpenCollection && uncovered > 0 ? 'Ouvrir une collecte' : undefined,
            }
          : {
              key: 'dispo',
              icon: 'num',
              num: 1,
              title: 'Disponibilités',
              badge: 'Pas encore ouverte',
              tone: 'warn',
              sub: `Pour le ${formatRange(period.start, period.end)}`,
              active: true,
              cta: canOpenCollection && uncovered > 0 ? 'Ouvrir la collecte' : undefined,
            }

  const counts = period.lineCounts
  const steps: Step[] = [
    dispo,
    period.generatedOn
      ? {
          key: 'gen',
          icon: 'check',
          num: 2,
          title: 'Génération',
          badge: 'Générée',
          tone: 'info',
          sub: `Générée le ${formatDate(period.generatedOn)}`,
        }
      : {
          key: 'gen',
          icon: 'num',
          num: 2,
          title: 'Génération',
          badge: period.targetGenerationOn ? 'Prévue' : 'En attente',
          tone: 'neutral',
          // docs/decisions.md D214: the target date is a plan; reaching it generates nothing.
          sub: !period.targetGenerationOn
            ? 'Pas encore lancée'
            : period.targetGenerationOn >= today
              ? `Génération prévue le ${formatDate(period.targetGenerationOn)} (manuelle)`
              : `Date cible passée (le ${formatDate(period.targetGenerationOn)}) · à lancer manuellement`,
        },
    period.status === 'publiee'
      ? {
          key: 'pub',
          icon: 'check',
          num: 3,
          title: 'Publication',
          badge: 'Publiée',
          tone: 'ok',
          sub: publishedSub(period),
        }
      : period.status === 'partielle'
        ? {
            key: 'pub',
            icon: 'partial',
            num: 3,
            title: 'Publication',
            badge: 'Partiellement publiée',
            tone: 'warn',
            sub: counts
              ? `${counts.published} / ${counts.counting} lignes publiées${period.lastPublishedOn ? ` · dernière diffusion le ${formatDate(period.lastPublishedOn)}` : ''}`
              : publishedSub(period),
            active: true,
          }
        : {
            key: 'pub',
            icon: 'num',
            num: 3,
            title: 'Publication',
            badge: 'Non publiée',
            tone: 'neutral',
            sub: 'Visible par l’équipe une fois publiée',
          },
  ]

  async function open() {
    setOpening(true)
    setError(null)
    try {
      await onOpenCollecte()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'La collecte n’a pas pu être ouverte.')
    } finally {
      setOpening(false)
    }
  }

  return (
    <section className="mv-steps" aria-label={`Préparation de la période ${period.number}`}>
      <div className="mv-section-title">
        <h2>Préparation de la période {period.number}</h2>
        <span className="mv-muted">
          Chaque période a sa propre collecte, sa génération et sa publication.
        </span>
      </div>
      <ol className="mv-steps__list">
        {steps.map((s) => (
          <li key={s.key} className={`mv-step ${s.active ? 'is-active' : ''}`}>
            <span className={`mv-step__icon mv-step__icon--${s.icon}`} aria-hidden="true">
              {s.icon === 'check' ? (
                <Icon name="check" strokeWidth={3} />
              ) : s.icon === 'partial' ? (
                <Icon name="clock" strokeWidth={2.4} />
              ) : (
                s.num
              )}
            </span>
            <div className="mv-step__body">
              <div className="mv-step__top">
                <strong>{s.title}</strong>
                <span className={`mv-badge mv-badge--${s.tone}`}>{s.badge}</span>
              </div>
              <span className="mv-step__sub">{s.sub}</span>
              {s.cta && (
                <button
                  type="button"
                  className="mv-btn mv-btn--primary mv-btn--sm"
                  onClick={() => void open()}
                  disabled={opening}
                  aria-busy={opening}
                >
                  {opening ? 'Ouverture…' : s.cta}
                </button>
              )}
              {s.cta && error && (
                <span role="alert" className="mv-step__error">
                  {error}
                </span>
              )}
            </div>
          </li>
        ))}
      </ol>
      {lines.length > 0 && (
        <ul className="mv-period-lines" aria-label={`Lignes de la période ${period.number}`}>
          {lines.map((line) => (
            <li key={line.lineStableId}>
              <span className="mv-period-lines__name">{line.name}</span>
              <span className={`mv-badge mv-badge--${line.tone}`}>{line.label}</span>
              {line.detail && <span className="mv-period-lines__detail">{line.detail}</span>}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function publishedSub(period: Period): string {
  if (!period.publishedOn) return 'Publiée'
  return period.lastPublishedOn && period.lastPublishedOn !== period.publishedOn
    ? `Publiée le ${formatDate(period.publishedOn)} · dernière diffusion le ${formatDate(period.lastPublishedOn)}`
    : `Publiée le ${formatDate(period.publishedOn)}`
}
