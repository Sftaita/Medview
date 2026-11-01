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

/** docs/decisions.md D210 — « Préparer la période ». */
export type PreparationActionState = 'none' | 'scheduled' | 'executed' | 'skipped' | 'cancelled'
export type ReminderUnit = 'DAY' | 'WEEK'
export type PreparationEmails = {
  queued: number
  sent: number
  failed: number
  cancelled: number
  pending: number
}
export type PreparationReminder = {
  stableId: string
  value: number
  unit: ReminderUnit
  /** The day it runs (08:00 Europe/Brussels): the target date minus its offset. */
  dueOn: string
  state: Exclude<PreparationActionState, 'none'>
  outcome: string | null
  executedAt: string | null
  emails: PreparationEmails
}
export type PeriodPreparation = {
  period: { stableId: string; number: number; startsAt: string; lastDay: string }
  timezone: string
  sendTime: string
  today: string
  editable: boolean
  blockers: string[]
  announcement: {
    on: string | null
    state: PreparationActionState
    outcome: string | null
    executedAt: string | null
    emails: PreparationEmails
  }
  targetGenerationOn: string | null
  /** docs/decisions.md D214: the indicative deadline planned here (never blocking). */
  declarationDeadlineOn: string | null
  /** The deadline the participants are told: the one above, else the latest of the open collections. */
  deadlineOn: string | null
  /** The real date of the last completed generation of the period, once it happened. */
  generatedAt: string | null
  /** How many people the open collections concern — never a count of confirmations (D214). */
  collection: { state: 'not_open' | 'open' | 'closed'; participants: number }
  reminders: PreparationReminder[]
}
export type PreparationInput = {
  announceOn: string | null
  declarationDeadlineOn: string | null
  targetGenerationOn: string | null
  reminders: { stableId?: string; value: number; unit: ReminderUnit }[]
}

export function fetchPeriodPreparation(
  planningStableId: string,
  periodStableId: string,
): Promise<PeriodPreparation> {
  return apiFetch<PeriodPreparation>(
    `/api/plannings/${planningStableId}/periods/${periodStableId}/preparation`,
  )
}

export function savePeriodPreparation(
  planningStableId: string,
  periodStableId: string,
  input: PreparationInput,
): Promise<PeriodPreparation> {
  return apiFetch<PeriodPreparation>(
    `/api/plannings/${planningStableId}/periods/${periodStableId}/preparation`,
    {
      method: 'PUT',
      body: input,
    },
  )
}

/**
 * docs/decisions.md D211 — the exceptional rectification of a locked
 * period's end. `days` are candidate last days (inclusive), each allowed or
 * not with its reasons; `proposedLastDay` is the latest allowed one.
 */
export type EndRectificationOptions = {
  period: { stableId: string; number: number }
  startsAt: string
  currentLastDay: string
  lastDutyDay: string | null
  eligible: boolean
  blockers: DateReason[]
  proposedLastDay: string | null
  reasonMaxLength: number
  days: DayOption[]
}

export function fetchEndRectification(
  planningStableId: string,
  periodStableId: string,
): Promise<EndRectificationOptions> {
  return apiFetch<EndRectificationOptions>(
    `/api/plannings/${planningStableId}/periods/${periodStableId}/end-rectification`,
  )
}

/** `endsAt` and `expectedEndsAt` are exclusive, like every period end of the API. */
export function rectifyPeriodEnd(
  planningStableId: string,
  periodStableId: string,
  input: { endsAt: string; expectedEndsAt: string; reason: string },
): Promise<unknown> {
  return apiFetch(`/api/plannings/${planningStableId}/periods/${periodStableId}/end-rectification`, {
    method: 'POST',
    body: { ...input, confirm: true },
  })
}
