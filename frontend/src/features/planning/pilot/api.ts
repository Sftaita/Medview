import { apiFetch } from '../../../lib/apiClient'
import type {
  CollectionStatus,
  GenerationPreflight,
  LaunchResult,
  MemberStatusDetail,
  PendingRemindersResult,
  PlanningSettingsResult,
  ReminderSent,
  RestPolicyChoice,
  RuleSetStatus,
} from './types'

export function fetchCollectionStatus(planningStableId: string): Promise<CollectionStatus> {
  return apiFetch<CollectionStatus>(`/api/plannings/${planningStableId}/collection-status`)
}

export function fetchMemberStatus(
  planningStableId: string,
  memberStableId: string,
): Promise<MemberStatusDetail> {
  return apiFetch<MemberStatusDetail>(
    `/api/plannings/${planningStableId}/members/${memberStableId}/availability-status`,
  )
}

/** `null` clears the deadline. It is informative: it never blocks anything. */
export function updatePlanningSettings(
  planningStableId: string,
  availabilityDeadline: string | null,
): Promise<PlanningSettingsResult> {
  return apiFetch<PlanningSettingsResult>(`/api/plannings/${planningStableId}/settings`, {
    method: 'PATCH',
    body: { availabilityDeadline },
  })
}

export function remindMember(planningStableId: string, memberStableId: string): Promise<ReminderSent> {
  return apiFetch<ReminderSent>(`/api/plannings/${planningStableId}/members/${memberStableId}/reminders`, {
    method: 'POST',
    body: {},
  })
}

export function remindPendingMembers(planningStableId: string): Promise<PendingRemindersResult> {
  return apiFetch<PendingRemindersResult>(`/api/plannings/${planningStableId}/reminders/pending`, {
    method: 'POST',
    body: {},
  })
}

export function fetchGenerationPreflight(planningStableId: string): Promise<GenerationPreflight> {
  return apiFetch<GenerationPreflight>(`/api/plannings/${planningStableId}/generation-preflight`)
}

/** `restPolicy` omitted (or every field left at its disabled default) means
 * both rest policies stay off, identical to the pre-D137 behavior — never a
 * value guessed by the frontend (docs/decisions.md D137). */
export function launchGeneration(
  planningStableId: string,
  restPolicy?: RestPolicyChoice,
): Promise<LaunchResult> {
  return apiFetch<LaunchResult>(`/api/plannings/${planningStableId}/generations`, {
    method: 'POST',
    body: restPolicy ?? {},
  })
}

export function fetchRuleSetStatus(lineStableId: string): Promise<RuleSetStatus> {
  return apiFetch<RuleSetStatus>(`/api/planning-lines/${lineStableId}/rule-set`)
}

/** Always sends an empty configuration body: every `PlanningRuleSetConfiguration`
 * field is unread by the real solver today (see `PlanningRuleSetController`'s
 * docblock) — this is a pure activation gate, never a settings form. */
export function activateRuleSet(lineStableId: string): Promise<RuleSetStatus> {
  return apiFetch<RuleSetStatus>(`/api/planning-lines/${lineStableId}/rule-set/activate`, {
    method: 'POST',
    body: {},
  })
}
