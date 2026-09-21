import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeCollection } from '../../testUtils/fakeBackend'
import { status, stubApi } from '../../testUtils/stubApi'
import type { CollectionResponseRow } from '../availability/collectionTypes'
import { AvailabilityCollectionsPanel } from './AvailabilityCollectionsPanel'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

function row(
  id: string,
  lastName: string,
  overrides: Partial<CollectionResponseRow> = {},
): CollectionResponseRow {
  return {
    stableId: `r-${id}`,
    status: 'PENDING',
    acknowledgedAt: null,
    acknowledgementKind: null,
    lastAvailabilityChangeAt: null,
    user: { stableId: `u-${id}`, firstName: 'Camille', lastName },
    ...overrides,
  }
}

const answered = (at: string): Partial<CollectionResponseRow> => ({
  status: 'ACKNOWLEDGED',
  acknowledgedAt: at,
  acknowledgementKind: 'CONFIRMED',
})

const RESPONSES = [
  row('1', 'Dupont', answered('2026-12-12T08:00:00+00:00')),
  row('2', 'Martin', answered('2026-12-14T08:00:00+00:00')),
  row('3', 'Durand'),
  row('4', 'Lambert', { lastAvailabilityChangeAt: '2026-12-15T08:00:00+00:00' }),
]

const MANAGER_COLLECTION = makeCollection({
  canManage: true,
  myResponse: null,
  progress: { expected: 4, acknowledged: 2, pending: 2 },
})

function renderPanel() {
  render(
    <MemoryRouter>
      <AvailabilityCollectionsPanel planningStableId="plan-1" />
    </MemoryRouter>,
  )
}

describe('AvailabilityCollectionsPanel — for someone who manages it', () => {
  it('shows the X/Y counter, the deadline, and who has answered or is late', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-12-16T09:00:00Z'))
    stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [MANAGER_COLLECTION],
      'GET /api/availability-collections/col-1/responses': () => RESPONSES,
    })

    renderPanel()

    expect(await screen.findByText('Janvier–mars 2027')).toBeInTheDocument()
    expect(screen.getByText('2/4')).toBeInTheDocument()
    expect(screen.getByText(/Échéance : 20 décembre · 2 en attente/)).toBeInTheDocument()
    expect(await screen.findByText(/Dupont Camille/)).toBeInTheDocument()
    expect(screen.getByText(/répondu le 12\/12/)).toBeInTheDocument()
    expect(screen.getByText(/répondu le 14\/12/)).toBeInTheDocument()
    expect(screen.getAllByText(/à renseigner/)).toHaveLength(2)
    // A late person who touched their calendar is visible as such.
    expect(screen.getByText(/calendrier modifié le 15\/12/)).toBeInTheDocument()
  })

  it('filters the list: Tous, Répondu, À renseigner', async () => {
    stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [MANAGER_COLLECTION],
      'GET /api/availability-collections/col-1/responses': () => RESPONSES,
    })
    renderPanel()
    await screen.findByText(/Dupont Camille/)

    fireEvent.click(screen.getByRole('button', { name: /À renseigner \(2\)/ }))
    expect(screen.queryByText(/Dupont Camille/)).not.toBeInTheDocument()
    expect(screen.getByText(/Durand Camille/)).toBeInTheDocument()
    expect(screen.getByText(/Lambert Camille/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Répondu \(2\)/ }))
    expect(screen.getByText(/Dupont Camille/)).toBeInTheDocument()
    expect(screen.getByText(/Martin Camille/)).toBeInTheDocument()
    expect(screen.queryByText(/Durand Camille/)).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Tous \(4\)/ }))
    expect(screen.getByText(/Durand Camille/)).toBeInTheDocument()
    expect(screen.getByText(/Dupont Camille/)).toBeInTheDocument()
  })

  it('keeps the history: closed collections stay listed with their frozen counter', async () => {
    const old = makeCollection({
      stableId: 'col-0',
      startsAt: '2026-09-01',
      endsAt: '2027-01-01',
      lastDay: '2026-12-31',
      openedAt: '2026-08-25T07:00:00+00:00',
      deadline: null,
      status: 'CLOSED',
      closedAt: '2026-12-10T09:00:00+00:00',
      canManage: true,
      myResponse: null,
      progress: { expected: 9, acknowledged: 9, pending: 0 },
    })
    stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [MANAGER_COLLECTION, old],
      'GET /api/availability-collections/col-1/responses': () => RESPONSES,
    })
    renderPanel()

    await screen.findByText('Septembre–décembre 2026')
    expect(screen.getByText('9/9')).toBeInTheDocument()
    expect(screen.getByText(/Ouverte le 25\/08\/2026/)).toBeInTheDocument()
    expect(screen.getByText('Clôturée')).toBeInTheDocument()
    // Only the open one offers actions.
    expect(screen.getAllByRole('button', { name: 'Clôturer' })).toHaveLength(1)
  })

  it('changes the deadline and closes a collection', async () => {
    const patches: unknown[] = []
    const api = stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [MANAGER_COLLECTION],
      'GET /api/availability-collections/col-1/responses': () => RESPONSES,
      'PATCH /api/availability-collections/col-1': ({ body }) => {
        patches.push(body)
        return { ...MANAGER_COLLECTION, deadline: '2026-12-31' }
      },
      'POST /api/availability-collections/col-1/close': () => ({
        ...MANAGER_COLLECTION,
        status: 'CLOSED',
        closedAt: '2026-12-16T09:00:00+00:00',
      }),
    })
    renderPanel()
    await screen.findByText(/Dupont Camille/)

    fireEvent.click(screen.getByRole('button', { name: "Modifier l'échéance" }))
    fireEvent.change(screen.getByLabelText('Échéance de réponse'), { target: { value: '2026-12-31' } })
    fireEvent.click(screen.getByRole('button', { name: "Enregistrer l'échéance" }))
    await waitFor(() => expect(patches).toEqual([{ deadline: '2026-12-31' }]))
    expect(await screen.findByText(/Échéance 31\/12\/2026/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Clôturer' }))
    expect(await screen.findByText('Clôturée')).toBeInTheDocument()
    expect(api.requests('POST', '/api/availability-collections/col-1/close')).toHaveLength(1)
    expect(screen.queryByRole('button', { name: 'Clôturer' })).not.toBeInTheDocument()
  })

  it('explains a refused deadline', async () => {
    stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [MANAGER_COLLECTION],
      'GET /api/availability-collections/col-1/responses': () => RESPONSES,
      'PATCH /api/availability-collections/col-1': () => status(422, { error: 'validation_failed' }),
    })
    renderPanel()
    await screen.findByText(/Dupont Camille/)

    fireEvent.click(screen.getByRole('button', { name: "Modifier l'échéance" }))
    fireEvent.change(screen.getByLabelText('Échéance de réponse'), { target: { value: '2020-01-01' } })
    fireEvent.click(screen.getByRole('button', { name: "Enregistrer l'échéance" }))

    expect(await screen.findByRole('alert')).toHaveTextContent('dans le passé')
  })

  it('shows an empty state when there is no collection', async () => {
    stubApi({ 'GET /api/plannings/plan-1/availability-collections': () => [] })
    renderPanel()

    expect(await screen.findByText(/Aucune collecte de disponibilités/)).toBeInTheDocument()
  })
})

describe('AvailabilityCollectionsPanel — for a plain member', () => {
  it('shows only their own status: no counter, no list of others', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [makeCollection()],
    })

    renderPanel()

    expect(await screen.findByText(/Vos disponibilités sont à renseigner avant le/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Renseigner mes disponibilités' })).toHaveAttribute(
      'href',
      '/my-availability?collection=col-1',
    )
    expect(screen.queryByText(/réponses/)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Voir les réponses/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument()
    expect(api.calls.some((c) => c.path.endsWith('/responses'))).toBe(false)
  })

  it('shows their confirmation', async () => {
    stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [
        makeCollection({
          myResponse: {
            stableId: 'r',
            status: 'ACKNOWLEDGED',
            acknowledgedAt: '2026-12-12T08:00:00+00:00',
            acknowledgementKind: 'CONFIRMED',
            lastAvailabilityChangeAt: null,
          },
        }),
      ],
    })

    renderPanel()

    const item = (await screen.findByText(/Vous avez confirmé vos disponibilités le/)).closest(
      'li',
    ) as HTMLElement
    expect(within(item).getByText(/12\/12/)).toBeInTheDocument()
  })
})
