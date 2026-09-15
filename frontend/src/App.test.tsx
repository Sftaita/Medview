import { render, screen } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import App from './App'

vi.stubGlobal(
  'fetch',
  vi.fn(() =>
    Promise.resolve({
      ok: true,
      json: () => Promise.resolve({ status: 'ok', database: 'ok' }),
    }),
  ) as unknown as typeof fetch,
)

describe('App', () => {
  it('renders the dashboard by default', async () => {
    render(
      <BrowserRouter>
        <App />
      </BrowserRouter>,
    )

    expect(screen.getByText('MedVue')).toBeInTheDocument()
    expect(
      await screen.findByRole('heading', { name: 'Tableau de bord' }),
    ).toBeInTheDocument()
  })
})
