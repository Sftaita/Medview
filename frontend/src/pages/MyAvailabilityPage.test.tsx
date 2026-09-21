import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { dayIndex } from '../features/availability/calendarAxis'
import { MyAvailabilityPage } from './MyAvailabilityPage'

function jsonResponse(body: unknown, status = 200) {
  // A 204 has no body, hence no Content-Type (the real backend sends none either).
  return Promise.resolve(
    status === 204
      ? new Response(null, { status })
      : new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

type Stored = { stableId: string; type: string; startsAt: string; endsAt: string }
type Created = { type: string; startsAt: string; endsAt: string }

const now = new Date()
const YEAR = now.getFullYear()
const MONTH = now.getMonth()

/** Day 15+ of the current month is always an in-month cell (never a duplicated trailing day). */
function cell(day: number): HTMLElement {
  const element = document.querySelector<HTMLElement>(`[data-day="${dayIndex(YEAR, MONTH, day)}"]`)
  if (!element) throw new Error(`No cell for day ${day}`)
  return element
}

function storedPeriod(stableId: string, type: string, fromDay: number, toDayExclusive: number): Stored {
  return {
    stableId,
    type,
    startsAt: new Date(YEAR, MONTH, fromDay).toISOString(),
    endsAt: new Date(YEAR, MONTH, toDayExclusive).toISOString(),
  }
}

/** A tiny in-memory backend for /api/me/calendar. */
function stubCalendar(initial: Stored[], options: { failCreateWith?: number } = {}) {
  let stored = [...initial]
  const created: Created[] = []
  const deleted: string[] = []

  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)

      if (url.endsWith('/api/me/calendar') && init?.method === 'POST') {
        if (options.failCreateWith) {
          return jsonResponse({ error: 'overlapping_period', message: 'conflict' }, options.failCreateWith)
        }
        const body = JSON.parse(String(init.body)) as Created
        created.push(body)
        stored.push({ stableId: `new-${created.length}`, ...body })
        return jsonResponse(stored[stored.length - 1], 201)
      }
      if (url.endsWith('/api/me/calendar')) {
        return jsonResponse(stored)
      }
      if (init?.method === 'DELETE' && url.includes('/api/me/calendar/')) {
        const id = url.split('/').pop() as string
        deleted.push(id)
        stored = stored.filter((period) => period.stableId !== id)
        return jsonResponse(null, 204)
      }
      return Promise.reject(new Error(`Unexpected fetch to ${url}`))
    }),
  )

  return { created, deleted }
}

async function renderLoaded() {
  render(<MyAvailabilityPage />)
  await waitFor(() => expect(screen.queryByText('Chargement…')).not.toBeInTheDocument())
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('MyAvailabilityPage', () => {
  it('shows the stored periods on the calendar and in the summary', async () => {
    stubCalendar([storedPeriod('u1', 'UNAVAILABLE', 15, 18)])
    await renderLoaded()

    const summary = screen.getByRole('region', { name: 'Récapitulatif' })
    expect(within(summary).getByText('période sélectionnée')).toBeInTheDocument()
    expect(within(summary).getByText('3 jours au total')).toBeInTheDocument()
    expect(cell(15)).toHaveAttribute('aria-pressed', 'true')
    expect(cell(17)).toHaveAttribute('aria-pressed', 'true')
    expect(cell(18)).toHaveAttribute('aria-pressed', 'false')
  })

  it('keeps Enregistrer disabled until something changes', async () => {
    stubCalendar([storedPeriod('u1', 'UNAVAILABLE', 15, 18)])
    await renderLoaded()

    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled()

    fireEvent.click(cell(20))
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeEnabled()

    // Undoing the change makes the screen equal to the stored data again.
    fireEvent.click(cell(20))
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled()
  })

  it('creates a whole-day UNAVAILABLE period for a tapped day', async () => {
    const { created } = stubCalendar([])
    await renderLoaded()

    // detail === 0: a click without a pointer, i.e. keyboard activation of a focused day.
    fireEvent.click(cell(20))
    expect(
      within(screen.getByRole('region', { name: 'Récapitulatif' })).getByText('date sélectionnée'),
    ).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(created).toHaveLength(1))
    expect(created[0].type).toBe('UNAVAILABLE')
    expect(new Date(created[0].startsAt)).toEqual(new Date(YEAR, MONTH, 20))
    expect(new Date(created[0].endsAt)).toEqual(new Date(YEAR, MONTH, 21))
    expect(await screen.findByText('Modifications enregistrées.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled()
  })

  it('draws one continuous period with a press-and-drag, sent as a single API period', async () => {
    const { created } = stubCalendar([])
    await renderLoaded()

    fireEvent.pointerDown(cell(20), { button: 0, pointerId: 1 })
    fireEvent.pointerMove(cell(22), { pointerId: 1 })
    fireEvent.pointerUp(document.body, { pointerId: 1 })

    const summary = screen.getByRole('region', { name: 'Récapitulatif' })
    expect(within(summary).getByText('3 jours au total')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(created).toHaveLength(1))
    expect(new Date(created[0].startsAt)).toEqual(new Date(YEAR, MONTH, 20))
    expect(new Date(created[0].endsAt)).toEqual(new Date(YEAR, MONTH, 23))
  })

  it('adds a second period instead of replacing the first one', async () => {
    const { created } = stubCalendar([])
    await renderLoaded()

    fireEvent.pointerDown(cell(16), { button: 0 })
    fireEvent.pointerMove(cell(17))
    fireEvent.pointerUp(document.body)
    fireEvent.pointerDown(cell(25), { button: 0 })
    fireEvent.pointerMove(cell(26))
    fireEvent.pointerUp(document.body)

    expect(
      within(screen.getByRole('region', { name: 'Récapitulatif' })).getByText('périodes sélectionnées'),
    ).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() => expect(created).toHaveLength(2))
  })

  it('sends PREFER_DUTY when the preference nature is active', async () => {
    const { created } = stubCalendar([])
    await renderLoaded()

    fireEvent.click(screen.getByRole('button', { name: 'Préférence de garde' }))
    fireEvent.click(cell(20))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(created).toHaveLength(1))
    expect(created[0].type).toBe('PREFER_DUTY')
  })

  it('deletes a stored period removed from the summary', async () => {
    const { deleted, created } = stubCalendar([storedPeriod('u1', 'UNAVAILABLE', 15, 18)])
    await renderLoaded()

    fireEvent.click(screen.getByRole('button', { name: /^Retirer 15/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(deleted).toEqual(['u1']))
    expect(created).toHaveLength(0)
  })

  it('replaces a stored period whose days changed (delete, then create)', async () => {
    const { deleted, created } = stubCalendar([storedPeriod('u1', 'UNAVAILABLE', 15, 18)])
    await renderLoaded()

    fireEvent.click(cell(18)) // extends 15–17 to 15–18

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(created).toHaveLength(1))
    expect(deleted).toEqual(['u1'])
    expect(new Date(created[0].endsAt)).toEqual(new Date(YEAR, MONTH, 19))
  })

  it('shows a friendly message on a 409 overlap conflict', async () => {
    stubCalendar([], { failCreateWith: 409 })
    await renderLoaded()

    fireEvent.click(cell(20))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('chevauche ou touche déjà ces dates')
  })

  it('shows an error when the calendar cannot be loaded', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.reject(new Error('down'))),
    )
    render(<MyAvailabilityPage />)

    expect(await screen.findByRole('alert')).toHaveTextContent('Impossible de charger votre calendrier.')
  })

  it('scrolls the rail one month at a time, only after the pointer has dwelt at the edge', async () => {
    stubCalendar([])
    await renderLoaded()
    const label = () => document.querySelector('.cal__nav-label')?.textContent

    vi.useFakeTimers()
    try {
      const before = label()
      fireEvent.pointerDown(cell(20), { button: 0, pointerId: 1, clientX: 10 })
      // Far to the right of the (zero-sized, in jsdom) rail: the pointer is past the right edge.
      fireEvent.pointerMove(cell(21), { pointerId: 1, clientX: 5000 })

      act(() => {
        vi.advanceTimersByTime(300)
      })
      expect(label()).toBe(before) // not before ~450 ms

      act(() => {
        vi.advanceTimersByTime(300)
      })
      const afterFirst = label()
      expect(afterFirst).not.toBe(before)

      act(() => {
        vi.advanceTimersByTime(500)
      })
      expect(label()).toBe(afterFirst) // one month only: the next advance waits ~900 ms

      fireEvent.pointerUp(document.body, { pointerId: 1 })
    } finally {
      vi.useRealTimers()
    }
  })

  it('never offers a time of day: availability is whole-day only', async () => {
    stubCalendar([])
    await renderLoaded()

    expect(screen.queryByLabelText(/heure|horaire|plage horaire/i)).not.toBeInTheDocument()
    expect(document.querySelector('input[type="time"], input[type="datetime-local"]')).toBeNull()
  })
})
