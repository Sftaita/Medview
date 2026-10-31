import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Period } from '../types'
import { PeriodActions } from './PeriodActions'

afterEach(cleanup)

const period = (start: string, end: string, status: Period['status'] = 'publiee'): Period => ({
  id: 'c1',
  number: 1,
  start,
  end,
  status,
  collecte: { state: 'cloturee', responses: 0 },
})

function renderActions(p: Period, actions: { onEditDates?: () => void; onRectifyEnd?: () => void } = {}) {
  const onGoLatest = vi.fn()
  const { container } = render(
    <PeriodActions period={p} today="2026-10-19" latestNumber={3} onGoLatest={onGoLatest} {...actions} />,
  )
  return { container, onGoLatest }
}

describe('PeriodActions — history notice (lot L7)', () => {
  it('calls a published period that is over, with later ones, history, with the way back to the latest', () => {
    const { onGoLatest } = renderActions(period('2026-06-01', '2026-09-30'))
    expect(screen.getByText(/Consultation de l’historique/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Aller à la période 3/ }))
    expect(onGoLatest).toHaveBeenCalled()
  })

  it('never calls the current or a future published period history', () => {
    renderActions(period('2026-10-05', '2026-11-01'), { onEditDates: vi.fn() })
    expect(screen.queryByText(/Consultation de l’historique/)).not.toBeInTheDocument()
    cleanup()
    renderActions(period('2026-11-02', '2026-12-06'), { onEditDates: vi.fn() })
    expect(screen.queryByText(/Consultation de l’historique/)).not.toBeInTheDocument()
  })
})

describe('PeriodActions — what can be done, nothing repeated from the cards', () => {
  it('offers « Modifier les dates » and, when allowed, « Corriger la fin… »', () => {
    const onEditDates = vi.fn()
    const onRectifyEnd = vi.fn()
    renderActions(period('2026-10-05', '2027-01-30'), { onEditDates, onRectifyEnd })

    fireEvent.click(screen.getByRole('button', { name: /Modifier les dates/ }))
    fireEvent.click(screen.getByRole('button', { name: /Corriger la fin/ }))
    expect(onEditDates).toHaveBeenCalled()
    expect(onRectifyEnd).toHaveBeenCalled()
    // The cards above are the place for these: never repeated here.
    expect(
      screen.queryByText(/Premier jour|Dernier jour inclus|Durée|Nouvelle période/),
    ).not.toBeInTheDocument()
  })

  it('renders nothing at all when there is nothing to do', () => {
    const { container } = renderActions(period('2026-11-02', '2026-12-06', 'preparation'))
    expect(container).toBeEmptyDOMElement()
  })
})
