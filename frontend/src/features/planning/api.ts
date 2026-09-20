import { apiFetch } from '../../lib/apiClient'
import type {
  AddPlanningTeamMemberInput,
  CreatePlanningInput,
  CreatePlanningLineInput,
  InviteResult,
  InviteToTeamInput,
  PlanningDetail,
  PlanningLineSummary,
  PlanningSummary,
  PlanningTeamMember,
  TeamInvitation,
} from './types'

export function fetchPlannings(): Promise<PlanningSummary[]> {
  return apiFetch<PlanningSummary[]>('/api/plannings')
}

export function fetchPlanning(planningStableId: string): Promise<PlanningDetail> {
  return apiFetch<PlanningDetail>(`/api/plannings/${planningStableId}`)
}

export function createPlanning(input: CreatePlanningInput): Promise<PlanningSummary> {
  return apiFetch<PlanningSummary>('/api/plannings', {
    method: 'POST',
    body: input,
  })
}

export function renamePlanning(planningStableId: string, name: string): Promise<PlanningDetail> {
  return apiFetch<PlanningDetail>(`/api/plannings/${planningStableId}`, {
    method: 'PATCH',
    body: { name },
  })
}

export function createPlanningLine(
  planningStableId: string,
  input: CreatePlanningLineInput,
): Promise<PlanningLineSummary> {
  return apiFetch<PlanningLineSummary>(`/api/plannings/${planningStableId}/lines`, {
    method: 'POST',
    body: input,
  })
}

export function deletePlanningLine(planningStableId: string, lineStableId: string): Promise<void> {
  return apiFetch<void>(`/api/plannings/${planningStableId}/lines/${lineStableId}`, {
    method: 'DELETE',
  })
}

export function fetchTeamMembers(
  planningStableId: string,
  teamStableId: string,
): Promise<PlanningTeamMember[]> {
  return apiFetch<PlanningTeamMember[]>(`/api/plannings/${planningStableId}/teams/${teamStableId}/members`)
}

export function addTeamMember(
  planningStableId: string,
  teamStableId: string,
  input: AddPlanningTeamMemberInput,
): Promise<PlanningTeamMember> {
  return apiFetch<PlanningTeamMember>(`/api/plannings/${planningStableId}/teams/${teamStableId}/members`, {
    method: 'POST',
    body: input,
  })
}

export function endTeamMembership(
  planningStableId: string,
  teamStableId: string,
  memberStableId: string,
): Promise<PlanningTeamMember> {
  return apiFetch<PlanningTeamMember>(
    `/api/plannings/${planningStableId}/teams/${teamStableId}/members/${memberStableId}/end`,
    { method: 'POST', body: {} },
  )
}

export function fetchTeamInvitations(
  planningStableId: string,
  teamStableId: string,
): Promise<TeamInvitation[]> {
  return apiFetch<TeamInvitation[]>(`/api/plannings/${planningStableId}/teams/${teamStableId}/invitations`)
}

/** "Ajouter une personne": adds an existing user at once, or creates an invitation for a new email. */
export function inviteToTeam(
  planningStableId: string,
  teamStableId: string,
  input: InviteToTeamInput,
): Promise<InviteResult> {
  return apiFetch<InviteResult>(`/api/plannings/${planningStableId}/teams/${teamStableId}/invitations`, {
    method: 'POST',
    body: input,
  })
}

export function revokeTeamInvitation(
  planningStableId: string,
  teamStableId: string,
  invitationStableId: string,
): Promise<TeamInvitation> {
  return apiFetch<TeamInvitation>(
    `/api/plannings/${planningStableId}/teams/${teamStableId}/invitations/${invitationStableId}/revoke`,
    { method: 'POST', body: {} },
  )
}
