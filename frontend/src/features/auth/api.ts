import { apiFetch } from '../../lib/apiClient'
import type { CurrentUser, RegisterInput, RegisterResult } from './types'

export function login(email: string, password: string): Promise<{ token: string }> {
  return apiFetch<{ token: string }>('/api/login', {
    method: 'POST',
    body: { email, password },
    skipAuth: true,
  })
}

export function register(input: RegisterInput): Promise<RegisterResult> {
  return apiFetch<RegisterResult>('/api/register', {
    method: 'POST',
    body: input,
    skipAuth: true,
  })
}

export function fetchMe(): Promise<CurrentUser> {
  return apiFetch<CurrentUser>('/api/me')
}

/**
 * Identified by the HttpOnly refresh cookie, not the access token — always
 * succeeds from the caller's point of view (the backend's logout endpoint
 * is idempotent, see docs/authentication.md).
 */
export function logout(): Promise<void> {
  return apiFetch<void>('/api/token/logout', { method: 'POST', skipAuth: true })
}
