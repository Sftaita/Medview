import { useCallback, useEffect, useState } from 'react'
import { fetchPlanningHistory } from './api'
import { formatMoment, formatPeriod, plural } from './format'
import type {
  HistoryAssignmentEntry,
  HistoryAvailabilityEntry,
  HistoryEntry,
  HistoryGenerationEntry,
  HistoryGenerationRef,
  HistoryPerson,
  PlanningHistory,
} from './types'

type Props = {
  planningStableId: string
  periodStableId?: string
  /** Narrows the history to one participant (the member drawer). */
  memberStableId?: string
  timezone: string
  /** The drawer shows it at once; the planning view opens it on demand. */
  compact?: boolean
}

function name(person: HistoryPerson | null): string {
  return person ? `${person.firstName} ${person.lastName}` : 'quelqu’un'
}

function days(entry: { startsAt: string; endsAt: string }, timeZone: string): string {
  // Year always shown: a history spans several years.
  const label = formatPeriod(entry.startsAt, entry.endsAt, timeZone, 0)
  return label.includes('→') || label.includes('–')
    ? `du ${label.replace('–', ' au ').replace(' → ', ' au ')}`
    : `le ${label}`
}

function kindOf(type: 'UNAVAILABLE' | 'PREFER_DUTY'): string {
  return type === 'UNAVAILABLE' ? 'une indisponibilité' : 'une préférence de garde'
}

function generationRef(ref: HistoryGenerationRef, timeZone: string): string {
  const when = ref.capturedAt ? ` du ${formatMoment(ref.capturedAt, timeZone)}` : ''
  return `la génération${when}${ref.lineName ? ` (${ref.lineName})` : ''}`
}

function AvailabilityText({ entry, timeZone }: { entry: HistoryAvailabilityEntry; timeZone: string }) {
  const who = name(entry.user)
  const imported = entry.origin === 'SURGICAL_HUB_SYNC'
  let text: string
  if (entry.action === 'CREATED' && entry.new) {
    text = imported
      ? `Congé importé de SurgicalHub pour ${who} : indisponible ${days(entry.new, timeZone)}.`
      : `${who} déclare ${kindOf(entry.new.type)} ${days(entry.new, timeZone)}.`
  } else if (entry.action === 'RESCHEDULED' && entry.previous && entry.new) {
    text = `${imported ? `Congé SurgicalHub de ${who} modifié` : `${who} modifie ${kindOf(entry.new.type)}`} : ${days(entry.previous, timeZone)} → ${days(entry.new, timeZone)}.`
  } else if (entry.previous) {
    text = `${imported ? `Congé SurgicalHub de ${who} retiré` : `${who} supprime ${kindOf(entry.previous.type)}`} ${days(entry.previous, timeZone)}.`
  } else {
    text = `${who} modifie son calendrier.`
  }
  const heldDuty = entry.impacts.some((impact) => impact.conflicts.length > 0)

  return (
    <>
      <p>{text}</p>
      {entry.afterGeneration && (
        <p className="history__note">
          Postérieure à {generationRef(entry.afterGeneration, timeZone)} : aucune modification automatique du
          planning.
        </p>
      )}
      {heldDuty && (
        <p className="history__note history__note--warn">
          Une garde lui était alors attribuée sur ces dates.
        </p>
      )}
      {entry.usedBy.map((ref) => (
        <p key={ref.generationStableId} className="history__note">
          Présente dans les données capturées par {generationRef(ref, timeZone)}.
        </p>
      ))}
    </>
  )
}

const GENERATION_STATUS: Record<string, string> = {
  COMPLETED: 'terminée',
  FAILED: 'en échec',
}

function GenerationText({
  entry,
  timeZone,
  narrowed,
}: {
  entry: HistoryGenerationEntry
  timeZone: string
  narrowed: boolean
}) {
  const status = GENERATION_STATUS[entry.status] ?? 'en cours'
  return (
    <>
      <p>
        Génération{entry.line ? ` de la ligne ${entry.line.name}` : ''}, lancée par {name(entry.launchedBy)} —{' '}
        {status}
        {entry.finishedAt ? ` le ${formatMoment(entry.finishedAt, timeZone)}` : ''}.
      </p>
      <p className="history__note">
        {entry.capturedAt
          ? `Calendriers capturés le ${formatMoment(entry.capturedAt, timeZone)}.`
          : 'Les calendriers n’ont pas été capturés.'}{' '}
        {narrowed
          ? ''
          : `${plural(entry.capturedUnavailabilityCount, 'indisponibilité présente', 'indisponibilités présentes')} dans les données capturées.`}
      </p>
      {narrowed && entry.capturedUnavailabilities && (
        <p className="history__note">
          {entry.capturedUnavailabilities.length === 0
            ? 'Aucune indisponibilité de ce membre dans les données capturées.'
            : entry.capturedUnavailabilities
                .map(
                  (period) =>
                    `L’indisponibilité ${days(period, timeZone)} était présente dans les données capturées.`,
                )
                .join(' ')}
        </p>
      )}
      {entry.failureReason && <p className="history__note history__note--warn">{entry.failureReason}</p>}
    </>
  )
}

function AssignmentText({ entry }: { entry: HistoryAssignmentEntry }) {
  const date = new Intl.DateTimeFormat('fr-BE', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(new Date(`${entry.duty.date}T00:00:00Z`))
  const duty = `la garde du ${date}${entry.duty.lineName ? ` (${entry.duty.lineName})` : ''}`
  let text: string
  if (entry.swap) {
    text = `Échange accepté par ${name(entry.author)} : ${duty} passe de ${name(entry.from)} à ${name(entry.to)}.`
  } else if (entry.from && entry.to) {
    text = `${name(entry.author)} réattribue ${duty} de ${name(entry.from)} à ${name(entry.to)}.`
  } else if (entry.to) {
    text = `${name(entry.author)} attribue ${duty} à ${name(entry.to)}.`
  } else {
    text = `${name(entry.author)} retire ${name(entry.from)} de ${duty}.`
  }
  return (
    <>
      <p>{text}</p>
      {entry.wasPublished && <p className="history__note">Sur un planning déjà publié.</p>}
    </>
  )
}

const KIND_LABEL: Record<HistoryEntry['kind'], string> = {
  availability: 'Calendrier',
  generation: 'Génération',
  assignment: 'Garde',
}

/**
 * docs/decisions.md D217 — the chronology of a period for its managers:
 * declarations (and whether they came before or after a generation read the
 * calendars), generations (who, when, what they captured), and changes of a
 * duty's holder — never mixed up. Times in the planning's timezone.
 */
export function PlanningHistoryPanel({
  planningStableId,
  periodStableId,
  memberStableId,
  timezone,
  compact = false,
}: Props) {
  const [open, setOpen] = useState(compact)
  const [history, setHistory] = useState<PlanningHistory | null>(null)
  const [error, setError] = useState(false)

  const load = useCallback(async () => {
    setError(false)
    try {
      setHistory(await fetchPlanningHistory(planningStableId, periodStableId, memberStableId))
    } catch {
      setError(true)
    }
  }, [planningStableId, periodStableId, memberStableId])

  useEffect(() => {
    if (open) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      void load()
    }
  }, [open, load])

  const titleId = `history-${memberStableId ?? 'planning'}`
  const entries = history ? [...history.entries].reverse() : []

  return (
    <section className={compact ? 'history history--compact' : 'pd-card history'} aria-labelledby={titleId}>
      <div className="history__head">
        <h3 id={titleId} className={compact ? 'drawer-section__title' : undefined}>
          Historique
        </h3>
        {!compact && (
          <button
            type="button"
            className="pd-btn pd-btn-ghost"
            aria-expanded={open}
            onClick={() => setOpen((v) => !v)}
          >
            {open ? 'Masquer' : 'Afficher l’historique'}
          </button>
        )}
      </div>
      {open && !compact && (
        <p className="pd-muted">
          Déclarations d’indisponibilité, générations et réattributions de gardes, du plus récent au plus
          ancien. Une indisponibilité ajoutée après une génération ne modifie jamais ce planning d’elle-même.
        </p>
      )}
      {open && error && (
        <p className="muted">
          L’historique n’a pas pu être chargé.{' '}
          <button type="button" className="pd-btn pd-btn-ghost" onClick={() => void load()}>
            Réessayer
          </button>
        </p>
      )}
      {open && !error && history === null && <p className="muted">Chargement…</p>}
      {open && history !== null && entries.length === 0 && (
        <p className="muted">Rien à signaler sur cette période pour le moment.</p>
      )}
      {open && entries.length > 0 && (
        <ol className="history__list" aria-label="Événements, du plus récent au plus ancien">
          {entries.map((entry) => (
            <li
              key={'eventStableId' in entry ? entry.eventStableId : entry.generationStableId}
              className={`history__item history__item--${entry.kind}`}
            >
              <div className="history__when">
                <span className="history__kind">{KIND_LABEL[entry.kind]}</span>
                <time dateTime={entry.at}>{formatMoment(entry.at, timezone)}</time>
              </div>
              <div className="history__what">
                {entry.kind === 'availability' && <AvailabilityText entry={entry} timeZone={timezone} />}
                {entry.kind === 'generation' && (
                  <GenerationText entry={entry} timeZone={timezone} narrowed={memberStableId !== undefined} />
                )}
                {entry.kind === 'assignment' && <AssignmentText entry={entry} />}
              </div>
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}
