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

export type WeekTemplateLine = {
  stableId: string
  name: string
  kind: 'GENERAL' | 'CUSTOM' | 'INHERITED'
  periods: { number: number; state: 'never' | 'generated' | 'published' }[]
}

/** The general weekly structure of a planning (docs/decisions.md D227). */
export function fetchWeekTemplate(
  planningStableId: string,
): Promise<WeekStructurePayload & { lines: WeekTemplateLine[] }> {
  return apiFetch(`/api/plannings/${planningStableId}/week-template`)
}

export function saveWeekTemplate(
  planningStableId: string,
  payload: WeekStructurePayload,
): Promise<WeekStructurePayload & { lines: WeekTemplateLine[]; appliedTo: string[] }> {
  return apiFetch(`/api/plannings/${planningStableId}/week-template`, { method: 'PUT', body: payload })
}

/** What this line uses from now on: the general weekly structure, or its own. */
export function saveWeekStructureMode(lineStableId: string, mode: 'GENERAL' | 'CUSTOM'): Promise<unknown> {
  return apiFetch(`/api/planning-lines/${lineStableId}/week-structure-mode`, {
    method: 'PUT',
    body: { mode },
  })
}
