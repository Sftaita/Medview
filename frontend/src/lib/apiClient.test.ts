import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  apiDownload,
  apiFetch,
  ApiError,
  clearStoredToken,
  filenameFromDisposition,
  setStoredToken,
  UNAUTHORIZED_EVENT,
} from './apiClient'

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

describe('filenameFromDisposition', () => {
  it('reads the plain, quoted and RFC 6266 forms', () => {
    expect(filenameFromDisposition('attachment; filename=Gardes_2026-10.pdf')).toBe('Gardes_2026-10.pdf')
    expect(filenameFromDisposition('attachment; filename="a b.xlsx"')).toBe('a b.xlsx')
    expect(
      filenameFromDisposition("attachment; filename=x.pdf; filename*=UTF-8''Gardes%20%C3%A9t%C3%A9.pdf"),
    ).toBe('Gardes été.pdf')
    expect(filenameFromDisposition(null)).toBeNull()
    expect(filenameFromDisposition('inline')).toBeNull()
  })
})

describe('apiDownload', () => {
  beforeEach(() => {
    clearStoredToken()
  })

  it('POSTs the JSON body with the token and returns the file and its name', async () => {
    setStoredToken('access')
    const fetchMock = vi.fn(
      async () =>
        new Response('%PDF', {
          status: 200,
          headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename=P.pdf' },
        }),
    )
    vi.stubGlobal('fetch', fetchMock)

    const file = await apiDownload('/api/x/export', { format: 'pdf' })

    expect(file.filename).toBe('P.pdf')
    expect(await file.blob.text()).toBe('%PDF')
    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(init.method).toBe('POST')
    expect(init.body).toBe('{"format":"pdf"}')
    expect(new Headers(init.headers).get('Authorization')).toBe('Bearer access')
    vi.unstubAllGlobals()
  })

  it('turns a JSON error into an ApiError carrying its body', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        async () =>
          new Response(JSON.stringify({ error: 'not_yet_published', message: 'No.' }), {
            status: 409,
            headers: { 'Content-Type': 'application/json' },
          }),
      ),
    )

    const error = await apiDownload('/api/x/export', {}).catch((err: unknown) => err)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(409)
    expect((error as ApiError).body).toEqual({ error: 'not_yet_published', message: 'No.' })
    vi.unstubAllGlobals()
  })
})
