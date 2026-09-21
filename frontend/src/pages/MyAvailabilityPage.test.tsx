import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { dayIndex } from '../features/availability/calendarAxis'
import { MyAvailabilityProvider } from '../features/availability/MyAvailabilityProvider'
import type { UserAvailabilityPeriod } from '../features/availability/types'
import { createFakeBackend, makeCollection } from '../testUtils/fakeBackend'
import { MyAvailabilityPage } from './MyAvailabilityPage'

const now = new Date()
const YEAR = now.getFullYear()
const MONTH = now.getMonth()

/** Day 15+ of the current month is always an in-month cell (never a duplicated trailing day). */
function cell(day: number, year = YEAR, month = MONTH): HTMLElement {
  const element = document.querySelector<HTMLElement>(`[data-day="${dayIndex(year, month, day)}"]`)
  if (!element) throw new Error(`No cell for ${year}-${month + 1}-${day}`)
  return element
}

function storedPeriod(
  stableId: string,
  type: 'UNAVAILABLE' | 'PREFER_DUTY',
  fromDay: number,
  toDayExclusive: number,
): UserAvailabilityPeriod {
  return {
    stableId,
    type,
    startsAt: new Date(YEAR, MONTH, fromDay).toISOString(),
    endsAt: new Date(YEAR, MONTH, toDayExclusive).toISOString(),
    createdAt: '',
    updatedAt: '',
  }
}

function summary() {
  return within(screen.getByRole('region', { name: 'Récapitulatif' }))
}

function renderPage(entry = '/my-availability') {
  render(
    <MemoryRouter initialEntries={[entry]}>
      <MyAvailabilityProvider>
        <MyAvailabilityPage />
      </MyAvailabilityProvider>
    </MemoryRouter>,
  )
}

async function renderLoaded(entry?: string) {
  renderPage(entry)
  await waitFor(() => expect(screen.queryByText('Chargement…')).not.toBeInTheDocument())
}

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('MyAvailabilityPage — optimistic autosave', () => {
  it('shows the stored periods on the calendar and in the summary', async () => {
    createFakeBackend({ periods: [storedPeriod('u1', 'UNAVAILABLE', 15, 18)] }).install()
    await renderLoaded()

    expect(summary().getByText('période sélectionnée')).toBeInTheDocument()
    expect(summary().getByText('3 jours au total')).toBeInTheDocument()
    expect(cell(15)).toHaveAttribute('aria-pressed', 'true')
    expect(cell(17)).toHaveAttribute('aria-pressed', 'true')
    expect(cell(18)).toHaveAttribute('aria-pressed', 'false')
  })

  it('has no global save button: nothing to press after editing', async () => {
    createFakeBackend().install()
    await renderLoaded()

    fireEvent.click(cell(20))

    expect(screen.queryByRole('button', { name: /enregistrer/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /sauvegarder/i })).not.toBeInTheDocument()
  })

  it('shows a created period at once, before the server has answered, then persists it', async () => {
    const backend = createFakeBackend()
    backend.install()
    const release = backend.hold('POST', '/api/me/calendar')
    await renderLoaded()

    fireEvent.click(cell(20))

    // The request is still pending: the screen already shows the day.
    expect(cell(20)).toHaveAttribute('aria-pressed', 'true')
    expect(summary().getByText('date sélectionnée')).toBeInTheDocument()
    await waitFor(() => expect(backend.requests('POST', '/api/me/calendar')).toHaveLength(1))
    expect(screen.getByText('Enregistrement…')).toBeInTheDocument()

    release()

    await waitFor(() => expect(screen.getByText(/Enregistré automatiquement/)).toBeInTheDocument())
    const [{ body }] = backend.requests('POST', '/api/me/calendar')
    const created = body as { type: string; startsAt: string; endsAt: string }
    expect(created.type).toBe('UNAVAILABLE')
    expect(new Date(created.startsAt)).toEqual(new Date(YEAR, MONTH, 20))
    expect(new Date(created.endsAt)).toEqual(new Date(YEAR, MONTH, 21))
    expect(backend.periods).toHaveLength(1)
  })

  it('draws one continuous period with a press-and-drag, saved as a single API period', async () => {
    const backend = createFakeBackend()
    backend.install()
    await renderLoaded()

    fireEvent.pointerDown(cell(20), { button: 0, pointerId: 1 })
    fireEvent.pointerMove(cell(22), { pointerId: 1 })
    fireEvent.pointerUp(document.body, { pointerId: 1 })

    expect(summary().getByText('3 jours au total')).toBeInTheDocument()
    await waitFor(() => expect(backend.requests('POST', '/api/me/calendar')).toHaveLength(1))
    const created = backend.requests('POST', '/api/me/calendar')[0].body as {
      startsAt: string
      endsAt: string
    }
    expect(new Date(created.startsAt)).toEqual(new Date(YEAR, MONTH, 20))
    expect(new Date(created.endsAt)).toEqual(new Date(YEAR, MONTH, 23))
  })

  it('adds a second period instead of replacing the first one', async () => {
    const backend = createFakeBackend()
    backend.install()
    await renderLoaded()

    fireEvent.pointerDown(cell(16), { button: 0 })
    fireEvent.pointerMove(cell(17))
    fireEvent.pointerUp(document.body)
    await waitFor(() => expect(backend.periods).toHaveLength(1))
    fireEvent.pointerDown(cell(25), { button: 0 })
    fireEvent.pointerMove(cell(26))
    fireEvent.pointerUp(document.body)

    expect(summary().getByText('périodes sélectionnées')).toBeInTheDocument()
    await waitFor(() => expect(backend.periods).toHaveLength(2))
  })

  it('saves PREFER_DUTY when the preference nature is active', async () => {
    const backend = createFakeBackend()
    backend.install()
    await renderLoaded()

    fireEvent.click(screen.getByRole('button', { name: 'Préférence de garde' }))
    fireEvent.click(cell(20))

    await waitFor(() => expect(backend.requests('POST', '/api/me/calendar')).toHaveLength(1))
    expect((backend.requests('POST', '/api/me/calendar')[0].body as { type: string }).type).toBe(
      'PREFER_DUTY',
    )
  })

  it('removes a period at once and deletes it on the server', async () => {
    const backend = createFakeBackend({ periods: [storedPeriod('u1', 'UNAVAILABLE', 15, 18)] })
    backend.install()
    const release = backend.hold('DELETE', '/api/me/calendar')
    await renderLoaded()

    fireEvent.click(screen.getByRole('button', { name: /^Retirer 15/ }))

    expect(cell(15)).toHaveAttribute('aria-pressed', 'false')
    expect(summary().getByRole('heading', { name: 'Aucune date sélectionnée' })).toBeInTheDocument()
    await waitFor(() => expect(backend.requests('DELETE', '/api/me/calendar/u1')).toHaveLength(1))
    release()
    await waitFor(() => expect(backend.periods).toHaveLength(0))
    expect(backend.requests('POST', '/api/me/calendar')).toHaveLength(0)
  })

  it('edits a stored period in place with a PATCH — never delete then create', async () => {
    const backend = createFakeBackend({ periods: [storedPeriod('u1', 'UNAVAILABLE', 15, 18)] })
    backend.install()
    const release = backend.hold('PATCH', '/api/me/calendar')
    await renderLoaded()

    fireEvent.click(cell(18)) // extends 15–17 to 15–18

    expect(cell(18)).toHaveAttribute('aria-pressed', 'true')
    expect(summary().getByText('4 jours au total')).toBeInTheDocument()
    await waitFor(() => expect(backend.requests('PATCH', '/api/me/calendar/u1')).toHaveLength(1))
    release()

    await waitFor(() => expect(screen.getByText(/Enregistré automatiquement/)).toBeInTheDocument())
    const patch = backend.requests('PATCH', '/api/me/calendar/u1')[0].body as { endsAt: string }
    expect(new Date(patch.endsAt)).toEqual(new Date(YEAR, MONTH, 19))
    expect(backend.requests('DELETE', '/api/me/calendar')).toHaveLength(0)
    expect(backend.requests('POST', '/api/me/calendar')).toHaveLength(0)
    expect(backend.periods.map((p) => p.stableId)).toEqual(['u1'])
  })

  it('serializes rapid edits: a second edit made while a save is in flight is saved after it, never in parallel', async () => {
    const backend = createFakeBackend()
    backend.install()
    const release = backend.hold('POST', '/api/me/calendar')
    await renderLoaded()

    fireEvent.click(cell(20))
    await waitFor(() => expect(backend.requests('POST', '/api/me/calendar')).toHaveLength(1))
    fireEvent.click(cell(21)) // while the first POST is still pending
    expect(summary().getByText('2 jours au total')).toBeInTheDocument()
    expect(backend.calls.filter((c) => c.method !== 'GET')).toHaveLength(1)

    release()

    await waitFor(() => expect(screen.getByText(/Enregistré automatiquement/)).toBeInTheDocument())
    // The follow-up is one edit of the period the first request created.
    expect(backend.requests('PATCH', '/api/me/calendar/')).toHaveLength(1)
    expect(backend.periods).toHaveLength(1)
    expect(new Date(backend.periods[0].endsAt)).toEqual(new Date(YEAR, MONTH, 22))
  })

  it('rolls back a creation refused by the API and says so', async () => {
    const backend = createFakeBackend()
    backend.install()
    backend.failNext('POST', '/api/me/calendar', 409, { error: 'overlapping_period' })
    await renderLoaded()

    fireEvent.click(cell(20))

    expect(await screen.findByRole('alert')).toHaveTextContent('chevauche ou touche déjà ces dates')
    expect(screen.getByRole('alert')).toHaveTextContent('annulée')
    expect(cell(20)).toHaveAttribute('aria-pressed', 'false')
    expect(summary().getByRole('heading', { name: 'Aucune date sélectionnée' })).toBeInTheDocument()
    expect(screen.queryByText(/Enregistré automatiquement/)).not.toBeInTheDocument()
  })

  it('rolls back a modification refused by the API to what the server really holds', async () => {
    const backend = createFakeBackend({ periods: [storedPeriod('u1', 'UNAVAILABLE', 15, 18)] })
    backend.install()
    backend.failNext('PATCH', '/api/me/calendar', 422)
    await renderLoaded()

    fireEvent.click(cell(18))

    expect(await screen.findByRole('alert')).toHaveTextContent('invalide')
    expect(cell(18)).toHaveAttribute('aria-pressed', 'false')
    expect(cell(17)).toHaveAttribute('aria-pressed', 'true')
    expect(summary().getByText('3 jours au total')).toBeInTheDocument()
  })

  it('rolls back a deletion that never reached the server', async () => {
    const backend = createFakeBackend({ periods: [storedPeriod('u1', 'UNAVAILABLE', 15, 18)] })
    backend.install()
    backend.failNext('DELETE', '/api/me/calendar', 500)
    await renderLoaded()

    fireEvent.click(screen.getByRole('button', { name: /^Retirer 15/ }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Impossible')
    await waitFor(() => expect(cell(15)).toHaveAttribute('aria-pressed', 'true'))
    expect(backend.periods).toHaveLength(1)
  })

  it('can be edited again after a failure, and the error goes away', async () => {
    const backend = createFakeBackend()
    backend.install()
    backend.failNext('POST', '/api/me/calendar', 500)
    await renderLoaded()

    fireEvent.click(cell(20))
    await screen.findByRole('alert')

    fireEvent.click(cell(21))

    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument())
    await waitFor(() => expect(backend.periods).toHaveLength(1))
    expect(cell(21)).toHaveAttribute('aria-pressed', 'true')
  })

  it('asks for a second click before erasing everything', async () => {
    const backend = createFakeBackend({ periods: [storedPeriod('u1', 'UNAVAILABLE', 15, 18)] })
    backend.install()
    await renderLoaded()

    fireEvent.click(screen.getByRole('button', { name: 'Tout effacer' }))
    expect(cell(15)).toHaveAttribute('aria-pressed', 'true')
    expect(backend.requests('DELETE', '/api/me/calendar')).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer : tout effacer' }))

    expect(cell(15)).toHaveAttribute('aria-pressed', 'false')
    await waitFor(() => expect(backend.requests('DELETE', '/api/me/calendar/u1')).toHaveLength(1))
  })

  it('shows an error when the calendar cannot be loaded', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.reject(new Error('down'))),
    )
    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent('Impossible de charger votre calendrier.')
  })

  it('scrolls the rail one month at a time, only after the pointer has dwelt at the edge', async () => {
    createFakeBackend().install()
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
    createFakeBackend().install()
    await renderLoaded()

    expect(screen.queryByLabelText(/heure|horaire|plage horaire/i)).not.toBeInTheDocument()
    expect(document.querySelector('input[type="time"], input[type="datetime-local"]')).toBeNull()
  })
})

describe('MyAvailabilityPage — answering a collection', () => {
  function frozenDecember() {
    // Only `Date` is faked: promises and timers keep running normally.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-12-14T09:00:00Z'))
  }

  it('opens on the collection window, marks its dates and states the deadline', async () => {
    frozenDecember()
    createFakeBackend({ collections: [makeCollection()] }).install()

    await renderLoaded('/my-availability?collection=col-1')

    expect(screen.getByRole('region', { name: /Disponibilités janvier–mars 2027/ })).toBeInTheDocument()
    expect(screen.getByText('Vos disponibilités sont attendues pour janvier–mars 2027')).toBeInTheDocument()
    expect(screen.getByText('À renseigner avant le 20 décembre')).toBeInTheDocument()
    expect(document.querySelector('.cal__nav-label')?.textContent).toMatch(/Janvier/)
    expect(cell(15, 2027, 0)).toHaveClass('day--window')
    expect(cell(15, 2026, 11)).not.toHaveClass('day--window')
  })

  it('answers "no unavailability" and immediately shows the confirmation', async () => {
    frozenDecember()
    const backend = createFakeBackend({ collections: [makeCollection()] })
    backend.install()
    await renderLoaded('/my-availability?collection=col-1')

    fireEvent.click(screen.getByRole('button', { name: "Je n'ai aucune indisponibilité sur cette période" }))

    expect(await screen.findByText(/Disponibilités confirmées le/)).toHaveTextContent('14/12')
    expect(screen.getByText(/aucune indisponibilité/)).toBeInTheDocument()
    expect(
      backend.requests('POST', '/api/availability-collections/col-1/acknowledge')[0].body as object,
    ).toEqual({
      noUnavailability: true,
    })
    expect(screen.queryByRole('button', { name: /Confirmer que mes disponibilités/ })).not.toBeInTheDocument()
  })

  it('confirms that the availabilities are up to date after entering absences', async () => {
    frozenDecember()
    const backend = createFakeBackend({ collections: [makeCollection()] })
    backend.install()
    await renderLoaded('/my-availability?collection=col-1')

    fireEvent.click(cell(10, 2027, 0))
    await waitFor(() => expect(backend.periods).toHaveLength(1))
    // Absences now sit inside the window: "no unavailability" would contradict them, so it is not offered.
    expect(
      screen.queryByRole('button', { name: /aucune indisponibilité sur cette période/ }),
    ).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer que mes disponibilités sont à jour' }))

    expect(await screen.findByText(/Disponibilités confirmées le/)).toBeInTheDocument()
    expect(backend.requests('POST', '/api/availability-collections/col-1/acknowledge')[0].body).toEqual({})
  })

  it('does not treat existing absences as a confirmation', async () => {
    frozenDecember()
    createFakeBackend({
      collections: [makeCollection()],
      periods: [
        {
          stableId: 'old',
          type: 'UNAVAILABLE',
          startsAt: new Date(2027, 0, 15).toISOString(),
          endsAt: new Date(2027, 0, 18).toISOString(),
          createdAt: '',
          updatedAt: '',
        },
      ],
    }).install()

    await renderLoaded('/my-availability?collection=col-1')

    expect(screen.getByText('Vos disponibilités sont attendues pour janvier–mars 2027')).toBeInTheDocument()
    expect(screen.queryByText(/Disponibilités confirmées/)).not.toBeInTheDocument()
  })

  it('explains an answer the server refuses', async () => {
    frozenDecember()
    const backend = createFakeBackend({ collections: [makeCollection()] })
    backend.install()
    await renderLoaded('/my-availability?collection=col-1')
    backend.failNext('POST', '/api/availability-collections/col-1/acknowledge', 409, {
      error: 'collection_closed',
    })

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer que mes disponibilités sont à jour' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('clôturée')
    expect(screen.queryByText(/Disponibilités confirmées/)).not.toBeInTheDocument()
  })

  it('re-reads the calendar when the server refuses "no unavailability" because of absences the screen did not know', async () => {
    frozenDecember()
    const backend = createFakeBackend({ collections: [makeCollection()] })
    backend.install()
    await renderLoaded('/my-availability?collection=col-1')
    expect(
      screen.getByRole('button', { name: "Je n'ai aucune indisponibilité sur cette période" }),
    ).toBeInTheDocument()

    // Another tab of the same person saves an absence inside the window.
    backend.periods.push({
      stableId: 'other-tab',
      type: 'UNAVAILABLE',
      startsAt: new Date(2027, 0, 19).toISOString(),
      endsAt: new Date(2027, 0, 20).toISOString(),
      createdAt: '',
      updatedAt: '',
    })

    fireEvent.click(screen.getByRole('button', { name: "Je n'ai aucune indisponibilité sur cette période" }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Vous avez encore des indisponibilités')
    // The screen now agrees with the message: the absence is shown and the contradicting answer is no longer offered.
    await waitFor(() => expect(cell(19, 2027, 0)).toHaveAttribute('aria-pressed', 'true'))
    expect(
      screen.queryByRole('button', { name: "Je n'ai aucune indisponibilité sur cette période" }),
    ).not.toBeInTheDocument()
    expect(screen.queryByText(/Disponibilités confirmées/)).not.toBeInTheDocument()
  })
  it('does not send a double click twice', async () => {
    frozenDecember()
    const backend = createFakeBackend({ collections: [makeCollection()] })
    backend.install()
    const release = backend.hold('POST', '/api/availability-collections/')
    await renderLoaded('/my-availability?collection=col-1')

    const button = screen.getByRole('button', { name: 'Confirmer que mes disponibilités sont à jour' })
    fireEvent.click(button)
    fireEvent.click(button)
    release()

    await screen.findByText(/Disponibilités confirmées le/)
    expect(backend.requests('POST', '/api/availability-collections/col-1/acknowledge')).toHaveLength(1)
  })

  it('says so when the collection is not open anymore', async () => {
    createFakeBackend({ collections: [] }).install()

    await renderLoaded('/my-availability?collection=gone')

    expect(screen.getByText(/n'est plus ouverte/)).toBeInTheDocument()
  })
})
