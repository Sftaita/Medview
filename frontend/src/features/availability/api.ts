import { apiFetch } from '../../lib/apiClient'
import type {
  SavedUserAvailabilityPeriod,
  UpsertUserAvailabilityPeriodInput,
  UserAvailabilityPeriod,
} from './types'

export function fetchMyCalendar(): Promise<UserAvailabilityPeriod[]> {
  return apiFetch<UserAvailabilityPeriod[]>('/api/me/calendar')
}

export function createCalendarPeriod(
  input: UpsertUserAvailabilityPeriodInput,
): Promise<SavedUserAvailabilityPeriod> {
  return apiFetch<SavedUserAvailabilityPeriod>('/api/me/calendar', { method: 'POST', body: input })
}

export function updateCalendarPeriod(
  stableId: string,
  input: UpsertUserAvailabilityPeriodInput,
): Promise<SavedUserAvailabilityPeriod> {
  return apiFetch<SavedUserAvailabilityPeriod>(`/api/me/calendar/${stableId}`, {
    method: 'PATCH',
    body: input,
  })
}

export function deleteCalendarPeriod(stableId: string): Promise<void> {
  return apiFetch<void>(`/api/me/calendar/${stableId}`, { method: 'DELETE' })
}
