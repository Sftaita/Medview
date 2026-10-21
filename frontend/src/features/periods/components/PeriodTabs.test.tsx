import '@testing-library/jest-dom/vitest'
import { cleanup, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { PeriodTabs } from './PeriodTabs'

afterEach(cleanup)

describe('PeriodTabs labels', () => {
  it('keeps the full word where it fits and its narrow form only for a phone', () => {
    render(
      <PeriodTabs
        tabs={[
          { id: 'lines', label: 'Lignes de garde', short: 'Lignes', count: 2 },
          {
            id: 'availability',
            label: 'Indisponibilités',
            short: 'Indisponibilités',
            narrow: 'Indispos',
            count: 3,
          },
        ]}
        selected="lines"
        onSelect={vi.fn()}
      >
        <p>contenu</p>
      </PeriodTabs>,
    )

    // One accessible name per tab, whatever width the CSS picks.
    const availability = screen.getByRole('tab', { name: 'Indisponibilités (3)' })
    expect(
      within(availability).getByText('Indisponibilités', { selector: '.mv-tabs__short' }),
    ).toBeInTheDocument()
    expect(within(availability).getByText('Indispos', { selector: '.mv-tabs__narrow' })).toBeInTheDocument()
    // Without a narrow form, the short label is kept down to a phone.
    const lines = screen.getByRole('tab', { name: 'Lignes de garde (2)' })
    expect(within(lines).getByText('Lignes', { selector: '.mv-tabs__narrow' })).toBeInTheDocument()
  })
})
