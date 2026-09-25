import { apiFetch } from '../../lib/apiClient'
import type { WeekStructurePayload } from './weeklyStructure'

/**
 * A PlanningLine's currently-configured weekly structure (docs/decisions.md
 * D136) — the same shape as `WeekStructurePayload`, read from the backend's
 * currently-active `DutyPattern`s. `null` fields never appear: the backend
 * always answers with real strings (possibly empty ones for "no family").
 */
export function fetchWeekStructure(lineStableId: string): Promise<WeekStructurePayload> {
  return apiFetch<WeekStructurePayload>(`/api/planning-lines/${lineStableId}/week-structure`)
}

/**
 * Replaces the whole weekly structure atomically (docs/decisions.md D136,
 * docs/week-structure.md §4) — never a partial patch. Only ever applies to
 * generations launched *after* this call; nothing already generated or
 * published is touched.
 */
export function saveWeekStructure(
  lineStableId: string,
  payload: WeekStructurePayload,
): Promise<WeekStructurePayload> {
  return apiFetch<WeekStructurePayload>(`/api/planning-lines/${lineStableId}/week-structure`, {
    method: 'PUT',
    body: payload,
  })
}
