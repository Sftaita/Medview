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

function renderCard(p: Period, counts = { lines: 2, members: 5 }) {
  render(
    <PeriodDetailCard
      period={p}
      today="2026-10-19"
      latestNumber={3}
      lineCount={counts.lines}
      memberCount={counts.members}
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

describe('PeriodDetailCard — dates and wording', () => {
  it('names the first and the last included day, with the full long date', () => {
    renderCard(period('2026-11-02', '2027-01-30'))
    expect(screen.getByText('Premier jour')).toBeInTheDocument()
    expect(screen.getByText('Dernier jour inclus')).toBeInTheDocument()
    expect(screen.getByText('Sam. 30 janv. 2027')).toBeInTheDocument()
    expect(screen.queryByText('Du')).not.toBeInTheDocument()
    expect(screen.queryByText('Au (inclus)')).not.toBeInTheDocument()
  })

  it('counts lines and members of a new period in correct French, singular and plural', () => {
    const fresh: Period = { ...period('2026-11-02', '2026-12-06'), number: 2, status: 'preparation' }
    renderCard(fresh)
    expect(
      screen.getByText(/2 lignes de garde et 5 membres du planning s’y appliquent déjà/),
    ).toBeInTheDocument()
    cleanup()
    renderCard(fresh, { lines: 1, members: 1 })
    expect(
      screen.getByText(/1 ligne de garde et 1 membre du planning s’y appliquent déjà/),
    ).toBeInTheDocument()
  })
})
