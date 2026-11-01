import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
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
    status: 'PARTICIPANT',
    lastAvailabilityChangeAt: null,
    user: { stableId: `u-${id}`, firstName: 'Camille', lastName },
    ...overrides,
  }
}

const RESPONSES = [
  row('1', 'Dupont'),
  row('2', 'Martin'),
  row('3', 'Durand', { status: 'WITHDRAWN' }),
  row('4', 'Lambert', { lastAvailabilityChangeAt: '2026-12-15T08:00:00+00:00' }),
]

const MANAGER_COLLECTION = makeCollection({
  canManage: true,
  myResponse: null,
  progress: { participants: 3 },
})

function renderPanel() {
  render(
    <MemoryRouter>
      <AvailabilityCollectionsPanel planningStableId="plan-1" />
    </MemoryRouter>,
  )
}

describe('AvailabilityCollectionsPanel — for someone who manages it (D214: nothing to confirm)', () => {
  it('shows how many people take part, the indicative deadline, and never a confirmation', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-12-16T09:00:00Z'))
    stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [MANAGER_COLLECTION],
      'GET /api/availability-collections/col-1/responses': () => RESPONSES,
    })

    renderPanel()

    expect(await screen.findByText('Janvier–mars 2027')).toBeInTheDocument()
    expect(screen.getByLabelText('Participants')).toHaveTextContent('3 participants')
    expect(
      screen.getByText(/Date limite indicative : 20 décembre — le calendrier reste modifiable après/),
    ).toBeInTheDocument()
    expect(await screen.findByText(/Dupont Camille/)).toBeInTheDocument()
    expect(screen.getAllByText('participant')).toHaveLength(3)
    expect(screen.getByText('a quitté le planning')).toBeInTheDocument()
    expect(screen.getByText(/calendrier modifié le 15\/12/)).toBeInTheDocument()
    expect(screen.queryByText(/répondu|à renseigner|confirm/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument()
  })

  it('keeps the history: closed collections stay listed with their participants', async () => {
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
      progress: { participants: 9 },
    })
    stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [MANAGER_COLLECTION, old],
      'GET /api/availability-collections/col-1/responses': () => RESPONSES,
    })
    renderPanel()

    await screen.findByText('Septembre–décembre 2026')
    expect(screen.getByText('9')).toBeInTheDocument()
    expect(screen.getByText(/Ouverte le 25\/08\/2026/)).toBeInTheDocument()
    expect(screen.getByText('Clôturée')).toBeInTheDocument()
    // Only the open one offers actions.
    expect(screen.getAllByRole('button', { name: 'Clôturer' })).toHaveLength(1)
  })

  it('changes the indicative deadline and closes a collection', async () => {
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

    fireEvent.click(screen.getByRole('button', { name: 'Modifier la date limite indicative' }))
    fireEvent.change(screen.getByLabelText('Date limite indicative'), { target: { value: '2026-12-31' } })
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la date' }))
    await waitFor(() => expect(patches).toEqual([{ deadline: '2026-12-31' }]))
    expect(await screen.findByText(/Date limite indicative 31\/12\/2026/)).toBeInTheDocument()

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

    fireEvent.click(screen.getByRole('button', { name: 'Modifier la date limite indicative' }))
    fireEvent.change(screen.getByLabelText('Date limite indicative'), { target: { value: '2020-01-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la date' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('dans le passé')
  })

  it('shows an empty state when there is no collection', async () => {
    stubApi({ 'GET /api/plannings/plan-1/availability-collections': () => [] })
    renderPanel()

    expect(await screen.findByText(/Aucune collecte de disponibilités/)).toBeInTheDocument()
  })
})

describe('AvailabilityCollectionsPanel — for a plain member', () => {
  it('shows only their own participation: no count, no list of others, nothing to confirm', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/availability-collections': () => [makeCollection()],
    })

    renderPanel()

    expect(
      await screen.findByText(/sans indisponibilité renseignée, vous êtes considéré comme disponible/),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ouvrir mon calendrier' })).toHaveAttribute(
      'href',
      '/my-availability?collection=col-1',
    )
    expect(screen.queryByLabelText('Participants')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Voir les participants/ })).not.toBeInTheDocument()
    expect(screen.queryByText(/confirm/i)).not.toBeInTheDocument()
    expect(api.calls.some((c) => c.path.endsWith('/responses'))).toBe(false)
  })
})
