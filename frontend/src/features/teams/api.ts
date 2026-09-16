import { apiFetch } from '../../lib/apiClient'
import type { NonParticipationPeriod, TeamMemberSummary, UpsertNonParticipationInput } from './types'

export function fetchTeamMembers(teamStableId: string): Promise<TeamMemberSummary[]> {
  return apiFetch<TeamMemberSummary[]>(`/api/teams/${teamStableId}/members`)
}

export function fetchNonParticipationPeriods(
  teamStableId: string,
  memberStableId: string,
): Promise<NonParticipationPeriod[]> {
  return apiFetch<NonParticipationPeriod[]>(
    `/api/teams/${teamStableId}/members/${memberStableId}/non-participation`,
  )
}

export function createNonParticipationPeriod(
  teamStableId: string,
  memberStableId: string,
  input: UpsertNonParticipationInput,
): Promise<NonParticipationPeriod> {
  return apiFetch<NonParticipationPeriod>(
    `/api/teams/${teamStableId}/members/${memberStableId}/non-participation`,
    {
      method: 'POST',
      body: input,
    },
  )
}

export function deleteNonParticipationPeriod(
  teamStableId: string,
  memberStableId: string,
  stableId: string,
): Promise<void> {
  return apiFetch<void>(
    `/api/teams/${teamStableId}/members/${memberStableId}/non-participation/${stableId}`,
    {
      method: 'DELETE',
    },
  )
}
