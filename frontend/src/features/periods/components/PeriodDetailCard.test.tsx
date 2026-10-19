import '@testing-library/jest-dom/vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Period } from '../types'
import { PeriodDetailCard } from './PeriodDetailCard'

afterEach(cleanup)

const period = (start: string, end: string): Period => ({
  id: 'c1',
  number: 1,
  start,
  end,
  status: 'publiee',
  collecte: { state: 'cloturee', responses: 0 },
})

function renderCard(p: Period) {
  render(
    <PeriodDetailCard
      period={p}
      today="2026-10-19"
      latestNumber={3}
      lineCount={2}
      memberCount={5}
      hasPrev={false}
      hasNext
      onPrev={vi.fn()}
      onNext={vi.fn()}
      onGoLatest={vi.fn()}
    />,
  )
}

describe('PeriodDetailCard — history banner (lot L7)', () => {
  it('calls a published period that is over, with later ones, history', () => {
    renderCard(period('2026-06-01', '2026-09-30'))
    expect(screen.getByText(/Consultation de l’historique/)).toBeInTheDocument()
  })

  it('never calls the current or a future published period history', () => {
    renderCard(period('2026-10-05', '2026-11-01'))
    expect(screen.queryByText(/Consultation de l’historique/)).not.toBeInTheDocument()
    cleanup()
    renderCard(period('2026-11-02', '2026-12-06'))
    expect(screen.queryByText(/Consultation de l’historique/)).not.toBeInTheDocument()
  })
})
