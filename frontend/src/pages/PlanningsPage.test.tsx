import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { stubApi } from '../testUtils/stubApi'
import { PlanningsPage } from './PlanningsPage'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function openForm() {
  const api = stubApi({
    'GET /api/plannings': () => [],
    'POST /api/plannings': () => ({ stableId: 'new' }),
  })
  render(
    <MemoryRouter>
      <PlanningsPage />
    </MemoryRouter>,
  )
  return api
}

async function fillAndSubmit() {
  fireEvent.click(await screen.findByRole('button', { name: 'Créer un planning' }))
  fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Gardes 2027' } })
  fireEvent.change(screen.getByLabelText('Début'), { target: { value: '2027-01-01' } })
  fireEvent.change(screen.getByLabelText('Fin'), { target: { value: '2027-05-01' } })
  fireEvent.change(screen.getByLabelText("Nom de l'équipe principale"), { target: { value: 'Seniors' } })
}

describe('PlanningsPage — creator participation', () => {
  it('offers "M\'inclure dans le planning", ticked, independent of the management rights', async () => {
    openForm()
    await fillAndSubmit()

    const box = screen.getByRole('checkbox', { name: /M'inclure dans le planning/ })
    expect(box).toBeChecked()
    expect(screen.getByText(/Cela ne change rien à vos droits de gestion/)).toBeInTheDocument()
  })

  it('creates the planning with the creator included by default', async () => {
    const api = openForm()
    await fillAndSubmit()

    fireEvent.click(screen.getByRole('button', { name: 'Créer' }))

    await waitFor(() => expect(api.requests('POST', '/api/plannings')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings')[0].body).toMatchObject({
      name: 'Gardes 2027',
      primaryTeam: { name: 'Seniors' },
      includeMe: true,
    })
  })

  it('creates the planning without the creator when the box is unticked', async () => {
    const api = openForm()
    await fillAndSubmit()

    fireEvent.click(screen.getByRole('checkbox', { name: /M'inclure dans le planning/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Créer' }))

    await waitFor(() => expect(api.requests('POST', '/api/plannings')).toHaveLength(1))
    expect(api.requests('POST', '/api/plannings')[0].body).toMatchObject({ includeMe: false })
  })

  it('states that the end date is exclusive', async () => {
    openForm()
    await fillAndSubmit()

    expect(screen.getByText(/Date exclue/)).toBeInTheDocument()
  })
})
