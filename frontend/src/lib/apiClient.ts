const API_BASE_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

const TOKEN_STORAGE_KEY = 'medvue.auth.token'

/**
 * Fired on the window whenever a request comes back 401, so any part of the
 * app (mainly AuthContext) can react without apiClient importing React state.
 */
export const UNAUTHORIZED_EVENT = 'medvue:unauthorized'

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_STORAGE_KEY)
}

export function setStoredToken(token: string): void {
  localStorage.setItem(TOKEN_STORAGE_KEY, token)
}

export function clearStoredToken(): void {
  localStorage.removeItem(TOKEN_STORAGE_KEY)
}

export class ApiError extends Error {
  readonly status: number
  readonly body: unknown

  constructor(status: number, body: unknown, message: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }
}

type ApiFetchOptions = Omit<RequestInit, 'body'> & {
  /** Do not attach the stored JWT, and never treat a 401 as a session expiry. */
  skipAuth?: boolean
  body?: unknown
}

/**
 * Thin wrapper around fetch(): attaches the JWT, serializes JSON bodies,
 * parses JSON responses, and turns non-2xx responses into ApiError.
 */
export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const { skipAuth = false, body, headers, ...rest } = options

  const finalHeaders = new Headers(headers)
  if (body !== undefined) {
    finalHeaders.set('Content-Type', 'application/json')
  }

  const token = getStoredToken()
  if (token && !skipAuth) {
    finalHeaders.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...rest,
    headers: finalHeaders,
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  if (response.status === 401 && !skipAuth) {
    clearStoredToken()
    window.dispatchEvent(new Event(UNAUTHORIZED_EVENT))
  }

  const contentType = response.headers.get('content-type') ?? ''
  const responseBody = contentType.includes('application/json') ? await response.json() : null

  if (!response.ok) {
    const message =
      (responseBody as { message?: string } | null)?.message ??
      `Request failed with status ${response.status}`
    throw new ApiError(response.status, responseBody, message)
  }

  return responseBody as T
}

export type HealthStatus = {
  status: 'ok' | 'error'
  database: 'ok' | 'error'
}

export async function fetchHealth(): Promise<HealthStatus> {
  return apiFetch<HealthStatus>('/api/health', { skipAuth: true })
}
