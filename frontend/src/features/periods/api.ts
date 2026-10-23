import { apiFetch } from '../../lib/apiClient'
import type { PlanningPeriodView } from '../planning/types'

/**
 * `path` with `?period=` (or `&period=`) when a period is named — the period
 * an endpoint works on (docs/planning-cycles.md §7.4). Without one, the
 * server uses its default period, or answers 422 `period_required` where a
 * planning with several periods must name it.
 */
export function withPeriod(path: string, periodStableId?: string): string {
  if (!periodStableId) return path
  return `${path}${path.includes('?') ? '&' : '?'}period=${encodeURIComponent(periodStableId)}`
}

/** One occurrence of a block cut by the boundary of the new period (docs/decisions.md D192). */
export type BlockCut = {
  lineStableId: string | null
  lineName: string | null
  blockName: string
  boundary: string
  firstDay: string
  lastDay: string
  earlierDays: string[]
  remainderDays: string[]
}

export type CreatedPeriod = PlanningPeriodView & { historicalBlockCuts: BlockCut[] }

/**
 * "Créer la période suivante" (docs/planning-cycles.md §5.1, §7.3).
 * `endsAt` is exclusive, like every end date of the API. `previousPeriodStableId`
 * is the last period on screen: the server refuses (409
 * `period_sequence_changed`) when another one was created meanwhile.
 * `acceptHistoricalBlockCuts` is only ever sent after the manager confirmed
 * the cut blocks the server listed (docs/decisions.md D192).
 */
export function createPeriod(
  planningStableId: string,
  input: { endsAt: string; previousPeriodStableId: string; acceptHistoricalBlockCuts?: boolean },
): Promise<CreatedPeriod> {
  return apiFetch<CreatedPeriod>(`/api/plannings/${planningStableId}/periods`, {
    method: 'POST',
    body: input.acceptHistoricalBlockCuts
      ? input
      : { endsAt: input.endsAt, previousPeriodStableId: input.previousPeriodStableId },
  })
}

/** One reason a date is refused (docs/decisions.md D207): the API's error code, and the blocks a cut would split. */
export type DateReason = { code: string; cuts?: BlockCut[] }

export type DayOption = { date: string; allowed: boolean; reasons: DateReason[] }

/**
 * GET …/periods/date-options: what the date picker shows. For `edge: 'end'`,
 * each `date` is a candidate last day (inclusive); for `edge: 'start'`, a
 * first day. `blockers` are the reasons no day is possible. The write applies
 * the same rules and answers with the same codes.
 */
export type DateOptions = {
  period: { stableId: string; number: number } | null
  edge: 'start' | 'end'
  fixedStart: string | null
  current: string | null
  editable: boolean
  blockers: DateReason[]
  historicalStartCuts: BlockCut[]
  days: DayOption[]
}

export function fetchDateOptions(
  planningStableId: string,
  input: { period: string; edge: 'start' | 'end'; from: string; to: string },
): Promise<DateOptions> {
  const query = new URLSearchParams(input).toString()
  return apiFetch<DateOptions>(`/api/plannings/${planningStableId}/periods/date-options?${query}`)
}

/** New dates for a period: its end, its start (first period only), or both — each with the value it replaces. */
export type PeriodDatesInput = {
  startsAt?: string
  expectedStartsAt?: string
  endsAt?: string
  expectedEndsAt?: string
}

export function changePeriodDates(
  planningStableId: string,
  periodStableId: string,
  input: PeriodDatesInput,
): Promise<PlanningPeriodView & { generationOutdated: boolean }> {
  return apiFetch(`/api/plannings/${planningStableId}/periods/${periodStableId}`, {
    method: 'PATCH',
    body: input,
  })
}

/** "Ouvrir la collecte": the first part of the period no collection covers yet (§5.9). */
export function openPeriodCollection(
  planningStableId: string,
  periodStableId: string,
  input: { deadline?: string | null } = {},
): Promise<{ stableId: string; period: { stableId: string; number: number } }> {
  return apiFetch(`/api/plannings/${planningStableId}/periods/${periodStableId}/availability-collections`, {
    method: 'POST',
    body: input.deadline ? { deadline: input.deadline } : {},
  })
}
