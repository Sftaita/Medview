import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { MyAvailabilityPage } from './MyAvailabilityPage'

function jsonResponse(body: unknown, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
}

// Day 15 is always rendered as an in-month cell and never duplicated by a
// leading/trailing day from an adjacent month (the 6-week grid only ever
// spills a handful of days past either boundary) — safe to click
// regardless of which real month the suite happens to run in.
const SAFE_DAY_LABEL = '15'

describe('MyAvailabilityPage', () => {
  it('creates a full-day UNAVAILABLE period for a clicked day and reloads the calendar', async () => {
    type CreatedBody = { type: string; startsAt: string; endsAt: string }
    const createdCalls: CreatedBody[] = []

    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input)

        if (url.endsWith('/api/me/calendar') && init?.method === 'POST') {
          const body = JSON.parse(String(init.body)) as CreatedBody
          createdCalls.push(body)
          return jsonResponse(
            { stableId: 'period-1', ...body, createdAt: body.startsAt, updatedAt: body.startsAt },
            201,
          )
        }

        if (url.endsWith('/api/me/calendar')) {
          const body = createdCalls[0]
          return jsonResponse(
            body
              ? [{ stableId: 'period-1', ...body, createdAt: body.startsAt, updatedAt: body.startsAt }]
              : [],
          )
        }

        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    render(<MyAvailabilityPage />)

    await waitFor(() => expect(screen.queryByText('Chargement…')).not.toBeInTheDocument())

    fireEvent.click(screen.getByRole('button', { name: SAFE_DAY_LABEL }))

    expect(await screen.findByText('1 jour sélectionné')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(createdCalls).toHaveLength(1))
    expect(createdCalls[0].type).toBe('UNAVAILABLE')
    expect(new Date(createdCalls[0].startsAt).getDate()).toBe(15)
    expect(new Date(createdCalls[0].endsAt).getDate()).toBe(16)

    // The form closes once the create call succeeds and the list reloads.
    await waitFor(() => expect(screen.queryByText('1 jour sélectionné')).not.toBeInTheDocument())
  })

  it('shows a friendly message on a 409 overlap conflict', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input)

        if (url.endsWith('/api/me/calendar') && init?.method === 'POST') {
          return jsonResponse({ error: 'overlapping_period', message: 'conflict' }, 409)
        }

        if (url.endsWith('/api/me/calendar')) {
          return jsonResponse([])
        }

        return Promise.reject(new Error(`Unexpected fetch to ${url}`))
      }),
    )

    render(<MyAvailabilityPage />)
    await waitFor(() => expect(screen.queryByText('Chargement…')).not.toBeInTheDocument())

    fireEvent.click(screen.getByRole('button', { name: SAFE_DAY_LABEL }))
    fireEvent.click(await screen.findByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('chevauche ou touche déjà ces dates')
  })
})
