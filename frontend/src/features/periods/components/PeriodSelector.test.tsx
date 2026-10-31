import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Period } from '../types'
import { PeriodSelector } from './PeriodSelector'

afterEach(cleanup)

const period = (number: number, start: string, end: string, status: Period['status']): Period => ({
  id: `c${number}`,
  number,
  start,
  end,
  status,
  collecte: { state: 'cloturee' },
})

const PERIODS = [
  period(1, '2026-06-01', '2026-09-30', 'publiee'),
  period(2, '2026-10-01', '2027-01-31', 'collecte'),
  period(3, '2027-02-01', '2027-04-30', 'preparation'),
]

function renderSelector(periods: Period[], selectedId = 'c2') {
  const onSelect = vi.fn()
  const { container } = render(
    <PeriodSelector
      periods={periods}
      selectedId={selectedId}
      today="2026-10-19"
      canCreate={false}
      onSelect={onSelect}
      onCreate={vi.fn()}
    />,
  )
  const cards = () => [...container.querySelectorAll<HTMLElement>('.mv-frise__seg')]
  return { container, cards, onSelect }
}

describe('PeriodSelector — the cards carry everything useful about each period', () => {
  it('shows on every card its number, status, dates with the last day included, duration and time', () => {
    const { cards } = renderSelector(PERIODS)
    const [past, current, future] = cards()

    expect(past).toHaveTextContent('Période 1')
    expect(past).toHaveTextContent('1 juin → 30 sept. 2026 122 jours')
    expect(within(past).getByText('Publiée')).toBeInTheDocument()
    expect(past).toHaveTextContent('Terminée le')

    expect(current).toHaveAttribute('aria-pressed', 'true')
    expect(current).toHaveTextContent('1 oct. 2026 → 31 janv. 2027 123 jours')
    expect(current).toHaveTextContent('En cours · jour 19 sur 123')

    expect(future).toHaveTextContent('1 févr. → 30 avr. 2027 89 jours')
    expect(future).toHaveTextContent('Commence dans')
  })

  it('no longer shows the legend under the cards', () => {
    const { container } = renderSelector(PERIODS)
    expect(container.querySelector('.mv-frise__legend')).toBeNull()
    expect(screen.queryByText(/Temps écoulé de la période/)).not.toBeInTheDocument()
    expect(screen.queryByText(/La largeur de chaque période suit sa durée/)).not.toBeInTheDocument()
  })

  it('selects a period from its card', () => {
    const { cards, onSelect } = renderSelector(PERIODS)
    fireEvent.click(cards()[2])
    expect(onSelect).toHaveBeenCalledWith('c3')
  })

  it('on a phone, says where the selected period stands in time, its duration only when not already told', () => {
    const { container } = renderSelector(PERIODS)
    const switcher = container.querySelector('.mv-switcher') as HTMLElement
    expect(within(switcher).getByText('Période 2 sur 3')).toBeInTheDocument()
    expect(within(switcher).getByText('En cours · jour 19 sur 123')).toBeInTheDocument()

    cleanup()
    const past = renderSelector(PERIODS, 'c1').container.querySelector('.mv-switcher') as HTMLElement
    expect(past.querySelector('.mv-switcher__time')).toHaveTextContent(/^Terminée le .* · 122 jours$/)
  })

  it('offers no previous / next arrows for a single period', () => {
    renderSelector([PERIODS[1]])
    expect(screen.queryByRole('button', { name: 'Période précédente' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Période suivante' })).not.toBeInTheDocument()
  })
})
