import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiFetch, ApiError, clearStoredToken, setStoredToken, UNAUTHORIZED_EVENT } from './apiClient'

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

describe('apiFetch', () => {
  beforeEach(() => {
    clearStoredToken()
    localStorage.clear()
  })

  it('refreshes the access token once and replays the original request after a 401', async () => {
    setStoredToken('expired-token')
    const calls: string[] = []

    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        calls.push(url)

        if (url.endsWith('/api/protected')) {
          // First call (with the expired token) fails; the replay (after
          // refresh) succeeds.
          const isReplay = calls.filter((c) => c.endsWith('/api/protected')).length > 1
          return isReplay ? jsonResponse({ data: 'secret' }) : jsonResponse({ message: 'expired' }, 401)
        }
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ token: 'new-token' })
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    const result = await apiFetch<{ data: string }>('/api/protected')

    expect(result).toEqual({ data: 'secret' })
    expect(calls.filter((c) => c.endsWith('/api/token/refresh'))).toHaveLength(1)
    expect(calls.filter((c) => c.endsWith('/api/protected'))).toHaveLength(2)
    expect(localStorage.getItem('medvue.auth.token')).toBe('new-token')
  })

  it('does not loop forever when the replayed request also returns 401', async () => {
    setStoredToken('expired-token')
    let refreshCalls = 0

    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/protected')) return jsonResponse({ message: 'still unauthorized' }, 401)
        if (url.endsWith('/api/token/refresh')) {
          refreshCalls += 1
          return jsonResponse({ token: 'new-token' })
        }
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    const unauthorizedHandler = vi.fn()
    window.addEventListener(UNAUTHORIZED_EVENT, unauthorizedHandler)

    await expect(apiFetch('/api/protected')).rejects.toThrow(ApiError)
    // Exactly one refresh attempt — the retry must not trigger a second one.
    expect(refreshCalls).toBe(1)
    expect(unauthorizedHandler).toHaveBeenCalledTimes(1)
    expect(localStorage.getItem('medvue.auth.token')).toBeNull()

    window.removeEventListener(UNAUTHORIZED_EVENT, unauthorizedHandler)
  })

  it('logs the user out when the refresh itself fails', async () => {
    setStoredToken('expired-token')

    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/api/protected')) return jsonResponse({ message: 'expired' }, 401)
        if (url.endsWith('/api/token/refresh')) return jsonResponse({ error: 'invalid_refresh_token' }, 401)
        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    const unauthorizedHandler = vi.fn()
    window.addEventListener(UNAUTHORIZED_EVENT, unauthorizedHandler)

    await expect(apiFetch('/api/protected')).rejects.toThrow(ApiError)
    expect(unauthorizedHandler).toHaveBeenCalledTimes(1)
    expect(localStorage.getItem('medvue.auth.token')).toBeNull()

    window.removeEventListener(UNAUTHORIZED_EVENT, unauthorizedHandler)
  })

  it('shares a single in-flight refresh across several concurrent 401s', async () => {
    setStoredToken('expired-token')
    let refreshCalls = 0
    let resolveRefresh: (() => void) | undefined
    const refreshGate = new Promise<void>((resolve) => {
      resolveRefresh = resolve
    })

    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input)

        if (url.endsWith('/api/token/refresh')) {
          refreshCalls += 1
          await refreshGate
          return jsonResponse({ token: 'new-token' })
        }

        if (url.endsWith('/api/a') || url.endsWith('/api/b') || url.endsWith('/api/c')) {
          const token = localStorage.getItem('medvue.auth.token')
          return token === 'new-token'
            ? jsonResponse({ ok: true })
            : jsonResponse({ message: 'expired' }, 401)
        }

        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    const requests = Promise.all([apiFetch('/api/a'), apiFetch('/api/b'), apiFetch('/api/c')])

    // Let all three initial 401s land before unblocking the refresh call.
    await new Promise((resolve) => setTimeout(resolve, 0))
    resolveRefresh?.()

    await requests

    expect(refreshCalls).toBe(1)
  })
})
