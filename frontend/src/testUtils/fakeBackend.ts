import { vi } from 'vitest'
import type { AvailabilityCollection } from '../features/availability/collectionTypes'
import type { UserAvailabilityPeriod } from '../features/availability/types'
import type { CalendarFeed } from '../features/duties/calendarFeed'
import type { SurgicalHubState, SurgicalHubSyncOutcome } from '../features/surgicalhub/types'

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
  /** GET /api/me/duties — "Mes gardes" (D168); null makes it fail with a 500. */
  duties?: unknown[] | null
  /** /api/me/calendar-feed — the subscription address of "Mes gardes" (D170); none by default. */
  calendarFeed?: CalendarFeed | null
  /** GET /api/duty-swaps/options — the "Échanger ma garde" dialog (D178). */
  swapOptions?: unknown
  /** /api/me/surgicalhub — no association by default (docs/surgicalhub-integration.md). */
  surgicalHub?: SurgicalHubState
  /** What POST /api/me/surgicalhub/sync answers; a SYNCED with no change by default. */
  surgicalHubSyncOutcome?: SurgicalHubSyncOutcome
}

export function createFakeBackend(options: FakeBackendOptions = {}) {
  let periods = [...(options.periods ?? [])]
  let collections = [...(options.collections ?? [])]
  const plannings = options.plannings ?? []
  let calendarFeed = options.calendarFeed ?? null
  let surgicalHub: SurgicalHubState = options.surgicalHub ?? { available: true, link: null }
  let nextFeedToken = 1
  const newFeed = (): CalendarFeed => ({
    token: String(nextFeedToken++).padStart(64, '0'),
    createdAt: '2026-12-14T09:00:00+00:00',
    lastFetchedAt: null,
  })
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
    if (path === '/api/me/duties') {
      return options.duties === null
        ? respond({ error: 'server_error' }, 500)
        : respond({ duties: options.duties ?? [] })
    }

    if (path === '/api/duty-swaps/options' && options.swapOptions !== undefined)
      return respond(options.swapOptions)
    if (path === '/api/me/surgicalhub' && method === 'GET') return respond(surgicalHub)
    if (path === '/api/me/surgicalhub/link-code' && method === 'POST') {
      return respond({ code: 'K7QM-2XPA-9DRT', expiresAt: new Date(Date.now() + 600_000).toISOString() }, 201)
    }
    if (path === '/api/me/surgicalhub/link' && method === 'DELETE') {
      if (surgicalHub.link?.status !== 'ACTIVE' && surgicalHub.link?.status !== 'SUSPENDED')
        return respond({ error: 'not_linked' }, 404)
      surgicalHub = {
        ...surgicalHub,
        link: { ...surgicalHub.link, status: 'REVOKED_LOCAL', revokedAt: new Date().toISOString() },
      }
      return respond(null, 204)
    }
    if (path === '/api/me/surgicalhub/sync' && method === 'POST') {
      return respond({
        outcome: options.surgicalHubSyncOutcome ?? {
          status: 'SYNCED',
          error: null,
          created: 0,
          updated: 0,
          removed: 0,
        },
        link: surgicalHub.link,
      })
    }
    if (path === '/api/me/calendar-feed' && method === 'GET') return respond({ feed: calendarFeed })
    if (path === '/api/me/calendar-feed' && method === 'POST') {
      calendarFeed ??= newFeed()
      return respond({ feed: calendarFeed })
    }
    if (path === '/api/me/calendar-feed/regenerate' && method === 'POST') {
      calendarFeed = newFeed()
      return respond({ feed: calendarFeed })
    }
    if (path === '/api/me/calendar-feed' && method === 'DELETE') {
      calendarFeed = null
      return respond(null, 204)
    }

    if (path === '/api/me/calendar' && method === 'GET') return respond(periods)
    if (path === '/api/me/calendar' && method === 'POST') {
      const input = body as Pick<UserAvailabilityPeriod, 'type' | 'startsAt' | 'endsAt'>
      const created: UserAvailabilityPeriod = {
        stableId: `p-${nextId++}`,
        ...input,
        source: 'MANUAL',
        editable: true,
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
