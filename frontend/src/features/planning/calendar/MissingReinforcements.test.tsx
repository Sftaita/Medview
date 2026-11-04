import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../../../testUtils/stubApi'
import type { PublicationUncoveredUnit } from '../result/types'
import { MissingReinforcements } from './MissingReinforcements'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function unit(overrides: Partial<PublicationUncoveredUnit> = {}): PublicationUncoveredUnit {
  return {
    lineStableId: 'fell',
    lineName: 'Fell/Ass',
    sourceLineName: 'Première ligne',
    conditional: true,
    materialized: false,
    linePublished: true,
    dates: ['2027-01-05'],
    dutyStableIds: [],
    days: [
      {
        date: '2027-01-05',
        weekday: 'TUESDAY',
        reason: 'TRIGGERED',
        dutyStableId: null,
        sourceDutyStableId: 's1',
        sourceHolder: { userStableId: 'u1', firstName: 'Julien', lastName: 'Vanderplasschen' },
      },
    ],
    vacancy: {
      code: 'NOT_CREATED',
      memberCount: 0,
      assignableCount: 0,
      firstMembershipStart: null,
      reasons: [],
    },
    ...overrides,
  }
}

describe('MissingReinforcements (docs/decisions.md D233)', () => {
  it('shows a need created since publication as vacant, and creates it only on request', async () => {
    const onCreated = vi.fn()
    const api = stubApi({
      'POST /api/planning-lines/fell/missing-reinforcements': () => ({ createdDutyCount: 1 }),
    })
    render(<MissingReinforcements units={[unit()]} periodStableId="p1" canEdit onCreated={onCreated} />)

    expect(
      screen.getByText(/Fell\/Ass — un renfort devenu nécessaire n’a pas de titulaire/),
    ).toBeInTheDocument()
    expect(screen.getByText('Mardi 5 janvier')).toBeInTheDocument()
    expect(screen.getByText(/Julien Vanderplasschen/)).toBeInTheDocument()
    expect(screen.getByText(/attribuez-les, puis republiez/)).toBeInTheDocument()
    expect(api.requests('POST', '/api/planning-lines/fell/missing-reinforcements')).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: 'Créer ces postes' }))

    await vi.waitFor(() => expect(onCreated).toHaveBeenCalledTimes(1))
    expect(api.requests('POST', '/api/planning-lines/fell/missing-reinforcements')[0].body).toEqual({
      periodStableId: 'p1',
    })
  })

  it('never shows a post that already exists, and offers nothing to whoever cannot edit', () => {
    const { container } = render(
      <MissingReinforcements
        units={[unit({ materialized: true })]}
        periodStableId="p1"
        canEdit
        onCreated={vi.fn()}
      />,
    )
    expect(container).toBeEmptyDOMElement()

    render(<MissingReinforcements units={[unit()]} periodStableId="p1" canEdit={false} onCreated={vi.fn()} />)
    expect(screen.getByText(/un renfort devenu nécessaire/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Créer ces postes' })).not.toBeInTheDocument()
  })
})
