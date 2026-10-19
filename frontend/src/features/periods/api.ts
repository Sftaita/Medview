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
