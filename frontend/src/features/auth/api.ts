import { apiFetch } from '../../lib/apiClient'
import type { CurrentUser, RegisterInput } from './types'

export function login(email: string, password: string): Promise<{ token: string }> {
  return apiFetch<{ token: string }>('/api/login', {
    method: 'POST',
    body: { email, password },
    skipAuth: true,
  })
}

export function register(input: RegisterInput): Promise<CurrentUser> {
  return apiFetch<CurrentUser>('/api/register', {
    method: 'POST',
    body: input,
    skipAuth: true,
  })
}

export function fetchMe(): Promise<CurrentUser> {
  return apiFetch<CurrentUser>('/api/me')
}
