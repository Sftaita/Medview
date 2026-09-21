import { apiFetch } from '../../lib/apiClient'
import type {
  AcknowledgementKind,
  AvailabilityCollection,
  CollectionResponseRow,
  ExtendPlanningInput,
  ExtendPlanningResult,
  ResponseStatus,
} from './collectionTypes'

/** The open collections the current user has to answer (or has answered). */
export function fetchMyCollections(): Promise<AvailabilityCollection[]> {
  return apiFetch<AvailabilityCollection[]>('/api/me/availability-collections')
}

/** The current user's own confirmation — never anybody else's. */
export function acknowledgeCollection(
  collectionStableId: string,
  kind: AcknowledgementKind,
): Promise<AvailabilityCollection> {
  return apiFetch<AvailabilityCollection>(`/api/availability-collections/${collectionStableId}/acknowledge`, {
    method: 'POST',
    body: kind === 'NO_UNAVAILABILITY' ? { noUnavailability: true } : {},
  })
}

export function fetchPlanningCollections(planningStableId: string): Promise<AvailabilityCollection[]> {
  return apiFetch<AvailabilityCollection[]>(`/api/plannings/${planningStableId}/availability-collections`)
}

export function fetchCollectionResponses(
  collectionStableId: string,
  status?: ResponseStatus,
): Promise<CollectionResponseRow[]> {
  const query = status ? `?status=${status}` : ''
  return apiFetch<CollectionResponseRow[]>(
    `/api/availability-collections/${collectionStableId}/responses${query}`,
  )
}

export function updateCollectionDeadline(
  collectionStableId: string,
  deadline: string | null,
): Promise<AvailabilityCollection> {
  return apiFetch<AvailabilityCollection>(`/api/availability-collections/${collectionStableId}`, {
    method: 'PATCH',
    body: { deadline },
  })
}

export function closeCollection(collectionStableId: string): Promise<AvailabilityCollection> {
  return apiFetch<AvailabilityCollection>(`/api/availability-collections/${collectionStableId}/close`, {
    method: 'POST',
    body: {},
  })
}

export function extendPlanning(
  planningStableId: string,
  input: ExtendPlanningInput,
): Promise<ExtendPlanningResult> {
  return apiFetch<ExtendPlanningResult>(`/api/plannings/${planningStableId}/extensions`, {
    method: 'POST',
    body: input,
  })
}
