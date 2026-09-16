const API_BASE_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

/** Exported so AuthProvider can recognize this key in cross-tab `storage` events. */
export const TOKEN_STORAGE_KEY = 'medvue.auth.token'

/**
 * Fired on the window whenever a request comes back 401 and the one-shot
 * refresh attempt also failed, so any part of the app (mainly AuthContext)
 * can react without apiClient importing React state.
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
  /** Seconds to wait before retrying, from the Retry-After header on a 429 (see nelmio_cors.yaml expose_headers). */
  readonly retryAfterSeconds: number | null

  constructor(status: number, body: unknown, message: string, retryAfterSeconds: number | null = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
    this.retryAfterSeconds = retryAfterSeconds
  }
}

type ApiFetchOptions = Omit<RequestInit, 'body'> & {
  /** Do not attach the stored JWT, and never treat a 401 as a session expiry (used by login/register/refresh themselves). */
  skipAuth?: boolean
  body?: unknown
  /** @internal set by apiFetch itself when replaying a request after a refresh; prevents infinite retry loops. */
  _isRetry?: boolean
}

/**
 * Shared across every concurrent 401: the first caller starts the refresh,
 * everyone else awaits the same promise instead of firing their own
 * request. Cleared once it settles either way.
 */
let refreshPromise: Promise<string> | null = null

/**
 * Calls /api/token/refresh directly (not through apiFetch, to avoid
 * recursing into this same 401-handling logic). The refresh token itself
 * is an HttpOnly cookie the browser attaches automatically — this code
 * never sees it, only the new access token in the JSON response.
 *
 * Exported (not just used internally by apiFetch's 401 handling) so
 * AuthProvider can reuse the same single-flight promise to check for an
 * existing session on mount, rather than duplicating this logic.
 */
export function refreshAccessToken(): Promise<string> {
  refreshPromise ??= (async () => {
    const response = await fetch(`${API_BASE_URL}/api/token/refresh`, {
      method: 'POST',
      credentials: 'include',
    })

    if (!response.ok) {
      throw new Error('refresh_failed')
    }

    const body = (await response.json()) as { token: string }
    setStoredToken(body.token)

    return body.token
  })().finally(() => {
    refreshPromise = null
  })

  return refreshPromise
}

/**
 * Thin wrapper around fetch(): attaches the JWT, serializes JSON bodies,
 * parses JSON responses, and turns non-2xx responses into ApiError.
 *
 * On a 401 (and not already a retry), it attempts exactly one silent
 * refresh and replays the original request once with the new access
 * token. If the refresh itself fails, the stored token is cleared and
 * UNAUTHORIZED_EVENT fires so the app can log the user out.
 */
export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const { skipAuth = false, body, headers, _isRetry = false, ...rest } = options

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
    // Harmless for endpoints that don't use cookies; required for
    // login/refresh/logout to send and receive the HttpOnly refresh cookie.
    credentials: 'include',
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  if (response.status === 401 && !skipAuth) {
    if (!_isRetry) {
      try {
        await refreshAccessToken()
      } catch {
        clearStoredToken()
        window.dispatchEvent(new Event(UNAUTHORIZED_EVENT))
        throw new ApiError(401, null, 'Session expired, please log in again.')
      }

      return apiFetch<T>(path, { ...options, _isRetry: true })
    }

    // Already retried once and still 401: give up.
    clearStoredToken()
    window.dispatchEvent(new Event(UNAUTHORIZED_EVENT))
  }

  const contentType = response.headers.get('content-type') ?? ''
  const responseBody = contentType.includes('application/json') ? await response.json() : null

  if (!response.ok) {
    const message =
      (responseBody as { message?: string } | null)?.message ??
      `Request failed with status ${response.status}`
    const retryAfterHeader = response.headers.get('Retry-After')
    const retryAfterSeconds = retryAfterHeader !== null ? Number(retryAfterHeader) : null
    throw new ApiError(
      response.status,
      responseBody,
      message,
      retryAfterSeconds !== null && !Number.isNaN(retryAfterSeconds) ? retryAfterSeconds : null,
    )
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
