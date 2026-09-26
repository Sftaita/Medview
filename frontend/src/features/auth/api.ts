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

export type PasswordResetPublicResponse = { success: true; message: string }

/**
 * The response is deliberately identical whether or not the email belongs
 * to an account — never branch the UI on its content beyond "it worked",
 * see docs/authentication.md §16.
 */
export function requestPasswordReset(email: string): Promise<PasswordResetPublicResponse> {
  return apiFetch<PasswordResetPublicResponse>('/api/password-reset/request', {
    method: 'POST',
    body: { email },
    skipAuth: true,
  })
}

/**
 * $token is the raw value read from the email link's URL fragment
 * (#token=...), never a query string — see ResetPasswordPage.
 */
export function confirmPasswordReset(
  token: string,
  newPassword: string,
): Promise<PasswordResetPublicResponse> {
  return apiFetch<PasswordResetPublicResponse>('/api/password-reset/confirm', {
    method: 'POST',
    body: { token, newPassword },
    skipAuth: true,
  })
}
