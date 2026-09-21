import { vi } from 'vitest'
import type { AvailabilityCollection } from '../features/availability/collectionTypes'
import type { UserAvailabilityPeriod } from '../features/availability/types'

/**
 * A tiny in-memory backend for the availability screens: the personal
 * calendar, the user's open collections and the answer endpoint, with the
 * same rules as the real API where they matter to the UI (a period edit is a
 * real PATCH, "no unavailability" is refused while absences exist).
 *
 * Two controls make the *optimistic* behaviour observable: `hold()` keeps
 * matching requests pending until released (so a test can look at the screen
 * while the server has not answered yet), and `failNext()` makes the next
 * matching request fail with a given status.
 */

export type FakeCall = { method: string; path: string; body: unknown }

type Deferred = { promise: Promise<void>; release: () => void }

function respond(body: unknown, status = 200): Promise<Response> {
  // A 204 has no body, hence no Content-Type (the real backend sends none either).
  return Promise.resolve(
    status === 204
      ? new Response(null, { status })
      : new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

export const ME = {
  id: 1,
  stableId: 'user-alice',
  email: 'alice@example.com',
  firstName: 'Alice',
  lastName: 'Martin',
  active: true,
  createdAt: '2026-01-01T00:00:00+00:00',
  updatedAt: '2026-01-01T00:00:00+00:00',
}

export function makeCollection(overrides: Partial<AvailabilityCollection> = {}): AvailabilityCollection {
  return {
    stableId: 'col-1',
    planningStableId: 'plan-1',
    planningName: 'Gardes 2026-2027',
    startsAt: '2027-01-01',
    endsAt: '2027-04-01',
    lastDay: '2027-03-31',
    openedAt: '2026-12-10T08:00:00+00:00',
    deadline: '2026-12-20',
    status: 'OPEN',
    closedAt: null,
    timezone: 'Europe/Brussels',
    canManage: false,
    myResponse: {
      stableId: 'resp-1',
      status: 'PENDING',
      acknowledgedAt: null,
      acknowledgementKind: null,
      lastAvailabilityChangeAt: null,
    },
    ...overrides,
  }
}

export type FakeBackendOptions = {
  periods?: UserAvailabilityPeriod[]
  collections?: AvailabilityCollection[]
  plannings?: unknown[]
}

export function createFakeBackend(options: FakeBackendOptions = {}) {
  let periods = [...(options.periods ?? [])]
  let collections = [...(options.collections ?? [])]
  const plannings = options.plannings ?? []
  const calls: FakeCall[] = []
  const holds = new Map<string, Deferred>()
  const failures: { key: string; status: number; body: unknown }[] = []
  let nextId = 1
  let clock = Date.parse('2026-12-14T09:00:00Z')

  const key = (method: string, path: string) => `${method} ${path}`

  async function handle(method: string, path: string, body: unknown): Promise<Response> {
    calls.push({ method, path, body })

    const hold = [...holds.entries()].find(([pattern]) => key(method, path).startsWith(pattern))
    if (hold) {
      await hold[1].promise
    }
    const failure = failures.findIndex((f) => key(method, path).startsWith(f.key))
    if (failure >= 0) {
      const [{ status, body: failureBody }] = failures.splice(failure, 1)
      return respond(failureBody, status)
    }

    if (path === '/api/token/refresh') return respond({ token: 'access' })
    if (path === '/api/me') return respond(ME)
    if (path === '/api/plannings' && method === 'GET') return respond(plannings)

    if (path === '/api/me/calendar' && method === 'GET') return respond(periods)
    if (path === '/api/me/calendar' && method === 'POST') {
      const input = body as Pick<UserAvailabilityPeriod, 'type' | 'startsAt' | 'endsAt'>
      const created: UserAvailabilityPeriod = {
        stableId: `p-${nextId++}`,
        ...input,
        createdAt: '',
        updatedAt: '',
      }
      periods.push(created)
      return respond(created, 201)
    }
    const calendarItem = path.match(/^\/api\/me\/calendar\/(.+)$/)
    if (calendarItem) {
      const id = calendarItem[1]
      if (method === 'DELETE') {
        periods = periods.filter((p) => p.stableId !== id)
        return respond(null, 204)
      }
      if (method === 'PATCH') {
        const input = body as Pick<UserAvailabilityPeriod, 'type' | 'startsAt' | 'endsAt'>
        periods = periods.map((p) => (p.stableId === id ? { ...p, ...input } : p))
        return respond(periods.find((p) => p.stableId === id))
      }
    }

    if (path === '/api/me/availability-collections') return respond(collections)
    const acknowledge = path.match(/^\/api\/availability-collections\/(.+)\/acknowledge$/)
    if (acknowledge && method === 'POST') {
      const collection = collections.find((c) => c.stableId === acknowledge[1])
      if (!collection) return respond({ error: 'not_found' }, 404)
      const noUnavailability = (body as { noUnavailability?: boolean } | null)?.noUnavailability === true
      if (noUnavailability && periods.some((p) => p.type === 'UNAVAILABLE')) {
        return respond({ error: 'unavailability_exists', unavailablePeriodCount: 1 }, 409)
      }
      clock += 60_000
      const updated: AvailabilityCollection = {
        ...collection,
        myResponse: {
          ...(collection.myResponse as NonNullable<AvailabilityCollection['myResponse']>),
          status: 'ACKNOWLEDGED',
          acknowledgedAt: new Date(clock).toISOString(),
          acknowledgementKind: noUnavailability ? 'NO_UNAVAILABILITY' : 'CONFIRMED',
        },
      }
      collections = collections.map((c) => (c.stableId === updated.stableId ? updated : c))
      return respond(updated)
    }

    return Promise.reject(new Error(`Unexpected fetch to ${method} ${path}`))
  }

  function install() {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input), 'http://localhost')
        const body = init?.body ? JSON.parse(String(init.body)) : null
        return handle((init?.method ?? 'GET').toUpperCase(), url.pathname, body)
      }),
    )
  }

  return {
    install,
    calls,
    /** Requests seen so far, e.g. `backend.requests('POST', '/api/me/calendar')`. */
    requests: (method: string, path: string) =>
      calls.filter((c) => c.method === method && c.path.startsWith(path)),
    get periods() {
      return periods
    },
    get collections() {
      return collections
    },
    /** Keeps every request starting with "METHOD /path" pending until the returned function is called. */
    hold(method: string, path: string): () => void {
      let release: () => void = () => undefined
      const promise = new Promise<void>((resolve) => {
        release = resolve
      })
      holds.set(key(method, path), { promise, release })
      return () => {
        holds.delete(key(method, path))
        release()
      }
    },
    /** The next request starting with "METHOD /path" fails with this status. */
    failNext(method: string, path: string, status: number, body: unknown = { error: 'failed' }) {
      failures.push({ key: key(method, path), status, body })
    },
  }
}
