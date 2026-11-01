import { apiFetch } from '../../lib/apiClient'
import type {
  AvailabilityCollection,
  CollectionResponseRow,
  ExtendPlanningInput,
  ExtendPlanningResult,
  ResponseStatus,
} from './collectionTypes'

/** The open collections the current user takes part in, once announced (docs/decisions.md D214). */
export function fetchMyCollections(): Promise<AvailabilityCollection[]> {
  return apiFetch<AvailabilityCollection[]>('/api/me/availability-collections')
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
