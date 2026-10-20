import type {
  PlanningCycleStatus,
  PlanningLineSummary,
  PlanningPeriodSummary,
  PlanningPeriodView,
  PlanningSummary,
} from '../planning/types'
import type { ISODate, Period, PeriodStatus, PlanningRole } from './types'
import { currentIndex } from './periods'
import type { PlanningCardData } from './components/PlanningCard'

/**
 * From the API's periods (docs/planning-cycles.md §7.2) to what the
 * components of docs/Design/medvue-periodes draw. Nothing is decided here:
 * the status, the collection progress and the line states are the server's.
 */

const STATUS: Record<PlanningCycleStatus, PeriodStatus> = {
  preparation: 'preparation',
  collecting: 'collecte',
  generated: 'generee',
  partially_published: 'partielle',
  published: 'publiee',
}

/** A date of the API ("2027-01-31T08:00:00+00:00" or "2027-01-31") as a calendar date. */
function day(value: string | null): ISODate | undefined {
  return value ? value.slice(0, 10) : undefined
}

export function toPeriod(view: PlanningPeriodSummary): Period {
  const c = view.collections
  return {
    id: view.stableId,
    number: view.number,
    start: view.startsAt,
    // The API's end is exclusive; the components show the last day included.
    end: view.lastDay,
    status: STATUS[view.status],
    collecte: {
      state: c.open > 0 ? 'en-cours' : c.closed > 0 ? 'cloturee' : 'non-ouverte',
      responses: c.acknowledged,
      expected: c.expected,
      closesOn: c.deadline ?? undefined,
      uncoveredDays: c.uncoveredDays,
    },
    generatedOn: day(view.generatedAt),
    publishedOn: day(view.publishedAt),
    lastPublishedOn: day(view.lastPublishedAt),
    lineCounts: { counting: view.lineCounts.counting, published: view.lineCounts.published },
  }
}

/** Créateur → Propriétaire, droit de publier → Gestionnaire, sinon Membre (§8). */
export function roleOf(planning: Pick<PlanningSummary, 'canManage' | 'canPublish'>): PlanningRole {
  if (planning.canManage) return 'Propriétaire'
  return planning.canPublish ? 'Gestionnaire' : 'Membre'
}

/**
 * The period opened when the URL names none (§4.1): the current one, else
 * the next, else the last.
 */
export function defaultPeriodNumber(periods: Period[], today: ISODate): number | undefined {
  if (periods.length === 0) return undefined
  return periods[currentIndex(periods, today)].number
}

export type LineTone = 'ok' | 'info' | 'warn' | 'neutral'

/** One line in one period: its state, said in words, and what is missing when it is incomplete. */
export type PeriodLineState = {
  lineStableId: string
  name: string
  label: string
  tone: LineTone
  detail?: string
}

const MISSING: Record<string, string> = {
  NO_ACTIVE_RULE_SET: 'règles de génération non activées',
  NO_MEMBER_IN_PERIOD: 'aucun membre sur la période',
  CONDITIONAL_SOURCE_NOT_IN_PERIOD: 'la ligne dont elle dépend ne fait pas partie de la période',
}

/** docs/planning-cycles.md §4.3 (D194): published, generated, to generate, incomplete, or without demand. */
export function lineStates(view: PlanningPeriodView, lines: PlanningLineSummary[]): PeriodLineState[] {
  const names = new Map(lines.map((line) => [line.stableId, line.name]))
  return view.lines.flatMap((line): PeriodLineState[] => {
    const name = names.get(line.lineStableId)
    const state = line.applicability?.state
    if (!name || state === 'INACTIVE' || state === 'NOT_IN_PERIOD') return []
    const base = { lineStableId: line.lineStableId, name }
    if (line.status === 'PUBLISHED' || line.status === 'ARCHIVED') {
      return [{ ...base, label: 'Publiée', tone: 'ok' }]
    }
    if (state === 'NO_DEMAND') {
      return [
        {
          ...base,
          label: 'Aucune garde demandée',
          tone: 'neutral',
          detail: 'Ne bloque ni la génération ni la publication des autres lignes.',
        },
      ]
    }
    if (state === 'INCOMPLETE') {
      const missing = (line.applicability?.reasons ?? []).map((reason) => MISSING[reason]).filter(Boolean)
      return [
        {
          ...base,
          label: 'Configuration incomplète',
          tone: 'warn',
          detail: missing.length > 0 ? `À compléter : ${missing.join(' ; ')}.` : undefined,
        },
      ]
    }
    if (line.status === 'GENERATED' || line.status === 'VALIDATED') {
      return [{ ...base, label: 'Générée, non publiée', tone: 'info' }]
    }
    return [{ ...base, label: 'À générer', tone: 'neutral' }]
  })
}

/** Whether any line of the period has a generation (its calendar can be read). */
export function isGenerated(view: PlanningPeriodView): boolean {
  return view.lines.some((line) => line.status !== 'DRAFT')
}

/**
 * Whether the caller has a calendar to read in the period (docs/decisions.md
 * D204): a manager as soon as a line is generated, anyone else once a line is
 * published — the server gives them nothing of a draft.
 */
export function hasReadableCalendar(view: PlanningPeriodView, readsUnpublished: boolean): boolean {
  return readsUnpublished
    ? isGenerated(view)
    : view.lines.some((line) => line.status === 'PUBLISHED' || line.status === 'ARCHIVED')
}

/**
 * A planning card (§7.1): the current period and the next one; before the
 * first period, the next one alone; once every period is over, the last.
 * Null for an older server that sends no period.
 */
export function toCardData(planning: PlanningSummary): PlanningCardData | null {
  const current = planning.currentPeriod ? toPeriod(planning.currentPeriod) : undefined
  const next = planning.nextPeriod ? toPeriod(planning.nextPeriod) : undefined
  const main = current ?? next ?? (planning.latestPeriod ? toPeriod(planning.latestPeriod) : undefined)
  if (!main) return null
  return {
    id: planning.stableId,
    name: planning.name,
    role: roleOf(planning),
    lineCount: planning.lineCount,
    memberCount: planning.memberCount,
    periodCount: planning.periodCount ?? 1,
    main,
    next: current ? next : undefined,
  }
}
