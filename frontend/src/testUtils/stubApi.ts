import { vi } from 'vitest'

type Context = { body: unknown; query: URLSearchParams }
type Reply = unknown | { __status: number; body?: unknown }

export type Route = (context: Context) => Reply | Promise<Reply>

/** Make a route answer with a given HTTP status. */
export function status(code: number, body: unknown = {}): Reply {
  return { __status: code, body }
}

export type RecordedCall = { method: string; path: string; body: unknown; query: URLSearchParams }

function json(body: unknown, statusCode = 200): Response {
  return new Response(JSON.stringify(body), {
    status: statusCode,
    headers: { 'Content-Type': 'application/json' },
  })
}

/**
 * A minimal `fetch` stub: routes are keyed "METHOD /path" (query string
 * ignored for matching, but handed to the route). An unknown route fails the
 * test loudly instead of silently returning nothing.
 */
export function stubApi(routes: Record<string, Route>) {
  const calls: RecordedCall[] = []

  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = new URL(String(input), 'http://localhost')
      const method = (init?.method ?? 'GET').toUpperCase()
      const body = init?.body ? JSON.parse(String(init.body)) : null
      calls.push({ method, path: url.pathname, body, query: url.searchParams })

      if (url.pathname === '/api/token/refresh') {
        return Promise.resolve(json({ token: 'access' }))
      }
      const route = routes[`${method} ${url.pathname}`]
      if (!route) {
        return Promise.reject(new Error(`Unexpected fetch to ${method} ${url.pathname}`))
      }
      // A route may return a promise, to hold the response while a test looks at the "in progress" screen.
      return Promise.resolve(route({ body, query: url.searchParams })).then((value) => {
        const reply = value as { __status?: number; body?: unknown }
        if (reply && typeof reply === 'object' && '__status' in reply) {
          return json(reply.body, reply.__status)
        }
        return json(reply)
      })
    }),
  )

  return {
    calls,
    requests: (method: string, path: string) => calls.filter((c) => c.method === method && c.path === path),
  }
}
