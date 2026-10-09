import { ApiError, apiFetch } from '../../lib/apiClient'
import type {
  CreateSwapRequestBody,
  SwapOptions,
  SwapOverview,
  SwapRequest,
  SwapRequestDetail,
} from './types'

/** Duty swaps between members (docs/duty-swaps.md §10). Every answer comes from the server — never an optimistic guess. */

export function fetchSwapOverview(): Promise<SwapOverview> {
  return apiFetch<SwapOverview>('/api/me/duty-swaps')
}

export function fetchSwapRequest(stableId: string): Promise<SwapRequestDetail> {
  return apiFetch<SwapRequestDetail>(`/api/duty-swap-requests/${stableId}`)
}

export function fetchSwapOptions(dutyStableId: string): Promise<SwapOptions> {
  return apiFetch<SwapOptions>(`/api/duty-swaps/options?dutyStableId=${encodeURIComponent(dutyStableId)}`)
}

export function createSwapRequest(body: CreateSwapRequestBody): Promise<SwapRequestDetail> {
  return apiFetch<SwapRequestDetail>('/api/duty-swap-requests', { method: 'POST', body })
}

export function proposeSwap(requestStableId: string, dutyStableId: string): Promise<SwapRequestDetail> {
  return apiFetch<SwapRequestDetail>(`/api/duty-swap-requests/${requestStableId}/proposals`, {
    method: 'POST',
    body: { dutyStableId },
  })
}

export function cancelSwapRequest(requestStableId: string): Promise<SwapRequestDetail> {
  return apiFetch<SwapRequestDetail>(`/api/duty-swap-requests/${requestStableId}/cancel`, { method: 'POST' })
}

export function decideSwapProposal(
  proposalStableId: string,
  action: 'accept' | 'refuse' | 'withdraw',
): Promise<SwapRequestDetail> {
  return apiFetch<SwapRequestDetail>(`/api/duty-swap-proposals/${proposalStableId}/${action}`, {
    method: 'POST',
  })
}

/** A planning's swap history, read-only, for its managers. */
export function fetchPlanningSwaps(planningStableId: string): Promise<(SwapRequest & SwapRequestDetail)[]> {
  return apiFetch<{ requests: SwapRequestDetail[] }>(`/api/plannings/${planningStableId}/duty-swaps`).then(
    (body) => body.requests,
  )
}

/** The server's own message (written for the member, in French), or a generic one. */
export function swapErrorMessage(
  error: unknown,
  fallback = 'L’opération n’a pas pu aboutir. Réessayez.',
): string {
  if (error instanceof ApiError && error.body && typeof error.body === 'object') {
    const message = (error.body as { message?: unknown }).message
    if (typeof message === 'string' && message !== '') return message
  }
  return fallback
}
