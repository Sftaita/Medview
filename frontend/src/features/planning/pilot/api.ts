import { apiDownloadGet, apiFetch, type DownloadedFile } from '../../../lib/apiClient'
import { withPeriod } from '../../periods/api'
import type {
  CollectionStatus,
  GenerationPreflight,
  PlanningJob,
  PlanningJobResponse,
  MemberStatusDetail,
  ParticipantRemindersResult,
  PlanningHistory,
  PlanningSettingsResult,
  ReminderSent,
  RestPolicyChoice,
  RuleSetStatus,
} from './types'

/** The follow-up of one period's collections (`periodStableId`, docs/planning-cycles.md §7.4). */
export function fetchCollectionStatus(
  planningStableId: string,
  periodStableId?: string,
): Promise<CollectionStatus> {
  return apiFetch<CollectionStatus>(
    withPeriod(`/api/plannings/${planningStableId}/collection-status`, periodStableId),
  )
}

export function fetchMemberStatus(
  planningStableId: string,
  memberStableId: string,
  periodStableId?: string,
): Promise<MemberStatusDetail> {
  return apiFetch<MemberStatusDetail>(
    withPeriod(
      `/api/plannings/${planningStableId}/members/${memberStableId}/availability-status`,
      periodStableId,
    ),
  )
}

/** `null` clears the deadline. It is informative: it never blocks anything. */
export function updatePlanningSettings(
  planningStableId: string,
  availabilityDeadline: string | null,
  periodStableId?: string,
): Promise<PlanningSettingsResult> {
  return apiFetch<PlanningSettingsResult>(
    withPeriod(`/api/plannings/${planningStableId}/settings`, periodStableId),
    {
      method: 'PATCH',
      body: { availabilityDeadline },
    },
  )
}

export function remindMember(
  planningStableId: string,
  memberStableId: string,
  periodStableId?: string,
): Promise<ReminderSent> {
  return apiFetch<ReminderSent>(
    withPeriod(`/api/plannings/${planningStableId}/members/${memberStableId}/reminders`, periodStableId),
    { method: 'POST', body: {} },
  )
}

/** docs/decisions.md D214: every participant, never filtered on a confirmation. */
export function remindParticipants(
  planningStableId: string,
  periodStableId?: string,
): Promise<ParticipantRemindersResult> {
  return apiFetch<ParticipantRemindersResult>(
    withPeriod(`/api/plannings/${planningStableId}/reminders/participants`, periodStableId),
    { method: 'POST', body: {} },
  )
}

/**
 * Which period, and which scope: `unlocked_lines` generates only the lines not
 * locked yet (docs/decisions.md D195) — always chosen explicitly, never deduced.
 */
export type GenerationTarget = { periodStableId?: string; scope?: 'unlocked_lines' }

export function fetchGenerationPreflight(
  planningStableId: string,
  target: GenerationTarget = {},
): Promise<GenerationPreflight> {
  const path = withPeriod(`/api/plannings/${planningStableId}/generation-preflight`, target.periodStableId)
  return apiFetch<GenerationPreflight>(
    target.scope ? `${path}${path.includes('?') ? '&' : '?'}scope=${target.scope}` : path,
  )
}

/** `restPolicy` omitted (or every field left at its disabled default) means
 * both rest policies stay off, identical to the pre-D137 behavior — never a
 * value guessed by the frontend (docs/decisions.md D137). */
export function launchGeneration(
  planningStableId: string,
  restPolicy?: RestPolicyChoice,
  overrideStaleSurgicalHubData = false,
  target: GenerationTarget = {},
): Promise<PlanningJobResponse> {
  // Answers at once with a QUEUED job (docs/decisions.md D149): the worker runs the solve.
  // The SurgicalHub override is only ever sent when the creator ticked it (decision D8).
  const body: Record<string, unknown> = { ...(restPolicy ?? {}) }
  if (overrideStaleSurgicalHubData) body.overrideStaleSurgicalHubData = true
  if (target.scope) body.scope = target.scope
  return apiFetch<PlanningJobResponse>(
    withPeriod(`/api/plannings/${planningStableId}/generations`, target.periodStableId),
    { method: 'POST', body },
  )
}

/** The active engine job of the planning, or its most recent one, or null. */
export function fetchLatestJob(planningStableId: string): Promise<{ job: PlanningJob | null }> {
  return apiFetch<{ job: PlanningJob | null }>(`/api/plannings/${planningStableId}/jobs/latest`)
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

/**
 * "Exporter les absences (PDF)" (docs/decisions.md D181): everyone's declared
 * unavailabilities over the planning period, as of now. The server decides who
 * is in it and checks the right; only the planning — and, on a planning with
 * periods, the period followed (docs/decisions.md D200) — is sent.
 */
export function downloadAbsencesPdf(
  planningStableId: string,
  periodStableId?: string,
): Promise<DownloadedFile> {
  const query = periodStableId ? `?period=${encodeURIComponent(periodStableId)}` : ''
  return apiDownloadGet(`/api/plannings/${planningStableId}/availability-export.pdf${query}`)
}

/** docs/decisions.md D217 — the history of one period, optionally narrowed to one participant. */
export function fetchPlanningHistory(
  planningStableId: string,
  periodStableId?: string,
  memberStableId?: string,
): Promise<PlanningHistory> {
  const base = withPeriod(`/api/plannings/${planningStableId}/history`, periodStableId)
  const member = memberStableId
    ? `${base.includes('?') ? '&' : '?'}member=${encodeURIComponent(memberStableId)}`
    : ''
  return apiFetch<PlanningHistory>(`${base}${member}`)
}
