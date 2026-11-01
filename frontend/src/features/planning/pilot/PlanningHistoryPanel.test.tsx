import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../../../testUtils/stubApi'
import { formatMoment } from './format'
import { PlanningHistoryPanel } from './PlanningHistoryPanel'
import type { HistoryEntry, PlanningHistory } from './types'

beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

const X = { stableId: 'u-x', firstName: 'Camille', lastName: 'Dupont' }
const Y = { stableId: 'u-y', firstName: 'Alex', lastName: 'Martin' }
const ADMIN = { stableId: 'u-a', firstName: 'Sam', lastName: 'Admin' }
const GEN = { generationStableId: 'g1', lineName: 'Seniors', capturedAt: '2027-01-24T08:15:00+00:00' }

/** The example of the specification, in Europe/Brussels (UTC+1 in winter). */
const ENTRIES: HistoryEntry[] = [
  {
    kind: 'availability',
    at: '2027-01-10T13:32:00+00:00',
    eventStableId: 'e1',
    action: 'CREATED',
    origin: 'USER',
    source: 'MANUAL',
    user: X,
    actor: X,
    availabilityStableId: 'a1',
    previous: null,
    new: { type: 'UNAVAILABLE', startsAt: '2027-03-11T23:00:00+00:00', endsAt: '2027-03-12T23:00:00+00:00' },
    afterGeneration: null,
    usedBy: [GEN],
    impacts: [],
  },
  {
    kind: 'generation',
    at: '2027-01-24T08:15:00+00:00',
    generationStableId: 'g1',
    line: { stableId: 'l1', name: 'Seniors' },
    launchedBy: ADMIN,
    requestedAt: '2027-01-24T08:14:50+00:00',
    capturedAt: '2027-01-24T08:15:00+00:00',
    finishedAt: '2027-01-24T08:16:10+00:00',
    status: 'COMPLETED',
    coverage: 'COMPLETE',
    failureReason: null,
    capturedUnavailabilityCount: 1,
  },
  {
    kind: 'availability',
    at: '2027-02-02T17:43:00+00:00',
    eventStableId: 'e2',
    action: 'CREATED',
    origin: 'USER',
    source: 'MANUAL',
    user: X,
    actor: X,
    availabilityStableId: 'a2',
    previous: null,
    new: { type: 'UNAVAILABLE', startsAt: '2027-03-14T23:00:00+00:00', endsAt: '2027-03-15T23:00:00+00:00' },
    afterGeneration: GEN,
    usedBy: [],
    impacts: [{ conflicts: [{}] }],
  },
  {
    kind: 'assignment',
    at: '2027-02-03T09:20:00+00:00',
    eventStableId: 'e3',
    author: ADMIN,
    swap: false,
    wasPublished: true,
    duty: { stableId: 'd1', date: '2027-03-15', lineName: 'Seniors' },
    from: X,
    to: Y,
  },
]

function history(overrides: Partial<PlanningHistory> = {}): PlanningHistory {
  return {
    period: { stableId: 'c2', number: 2 },
    timezone: 'Europe/Brussels',
    member: null,
    entries: ENTRIES,
    ...overrides,
  }
}

describe('PlanningHistoryPanel (docs/decisions.md D217)', () => {
  it('formats every instant in Europe/Brussels', () => {
    expect(formatMoment('2027-01-10T13:32:00+00:00', 'Europe/Brussels')).toBe('10 janvier 2027 à 14 h 32')
    expect(formatMoment('2027-07-10T13:32:00+00:00', 'Europe/Brussels')).toBe('10 juillet 2027 à 15 h 32')
  })

  it('opens on demand and tells who did what and when, newest first, without mixing declarations and reassignments', async () => {
    const api = stubApi({ 'GET /api/plannings/plan-1/history': () => history() })
    render(<PlanningHistoryPanel planningStableId="plan-1" periodStableId="c2" timezone="Europe/Brussels" />)
    expect(api.calls).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: 'Afficher l’historique' }))

    const list = await screen.findByRole('list', { name: 'Événements, du plus récent au plus ancien' })
    const items = within(list).getAllByRole('listitem')
    expect(items).toHaveLength(4)
    expect(api.calls[0].query.get('period')).toBe('c2')

    // Newest first: the reassignment, the late declaration, the generation, the first declaration.
    expect(items[0]).toHaveTextContent('3 février 2027 à 10 h 20')
    expect(items[0]).toHaveTextContent(
      'Sam Admin réattribue la garde du 15 mars 2027 (Seniors) de Camille Dupont à Alex Martin.',
    )
    expect(items[0]).not.toHaveTextContent('déclare')

    expect(items[1]).toHaveTextContent('2 février 2027 à 18 h 43')
    expect(items[1]).toHaveTextContent('Camille Dupont déclare une indisponibilité le 15 mars 2027.')
    expect(items[1]).toHaveTextContent(
      'Postérieure à la génération du 24 janvier 2027 à 09 h 15 (Seniors) : aucune modification automatique du planning.',
    )
    expect(items[1]).toHaveTextContent('Une garde lui était alors attribuée sur ces dates.')

    expect(items[2]).toHaveTextContent('24 janvier 2027 à 09 h 15')
    expect(items[2]).toHaveTextContent(
      'Génération de la ligne Seniors, lancée par Sam Admin — terminée le 24 janvier 2027 à 09 h 16.',
    )
    expect(items[2]).toHaveTextContent('1 indisponibilité présente dans les données capturées.')

    expect(items[3]).toHaveTextContent('10 janvier 2027 à 14 h 32')
    expect(items[3]).toHaveTextContent('Camille Dupont déclare une indisponibilité le 12 mars 2027.')
    expect(items[3]).toHaveTextContent(
      'Présente dans les données capturées par la génération du 24 janvier 2027 à 09 h 15 (Seniors).',
    )
  })

  it('narrowed to one participant (the drawer), lists what the generation captured of them', async () => {
    const api = stubApi({
      'GET /api/plannings/plan-1/history': () =>
        history({
          member: { memberStableId: 'm-x', userStableId: 'u-x', firstName: 'Camille', lastName: 'Dupont' },
          entries: [
            {
              ...(ENTRIES[1] as Extract<HistoryEntry, { kind: 'generation' }>),
              capturedUnavailabilities: [
                {
                  stableId: 'a1',
                  startsAt: '2027-03-11T23:00:00+00:00',
                  endsAt: '2027-03-12T23:00:00+00:00',
                  source: 'MANUAL',
                },
              ],
            },
          ],
        }),
    })
    render(
      <PlanningHistoryPanel
        planningStableId="plan-1"
        periodStableId="c2"
        memberStableId="m-x"
        timezone="Europe/Brussels"
        compact
      />,
    )

    expect(
      await screen.findByText('L’indisponibilité le 12 mars 2027 était présente dans les données capturées.'),
    ).toBeInTheDocument()
    expect(api.calls[0].query.get('member')).toBe('m-x')
  })

  it('says so when there is nothing yet, and never blocks the page when it cannot load', async () => {
    stubApi({ 'GET /api/plannings/plan-1/history': () => history({ entries: [] }) })
    render(<PlanningHistoryPanel planningStableId="plan-1" timezone="Europe/Brussels" compact />)
    expect(await screen.findByText('Rien à signaler sur cette période pour le moment.')).toBeInTheDocument()
  })
})
