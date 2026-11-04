import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../../lib/apiClient'
import { stubApi } from '../../../testUtils/stubApi'
import { CoverageStartSection } from './CoverageStartSection'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

const URL = '/api/planning-lines/l1/coverage-start'

const PLAN = {
  previousStartsOn: null,
  newStartsOn: '2026-11-01',
  changesAnything: true,
  confirmationRequired: true,
  confirmation: 'hash-1',
  retired: [
    { dutyStableId: 'd1', date: '2026-10-02' },
    { dutyStableId: 'd2', date: '2026-10-03' },
  ],
  kept: [{ dutyStableId: 'd3', date: '2026-10-30', holder: { firstName: 'Rabih', lastName: 'Kortbawi' } }],
}

function pick(date: string) {
  fireEvent.change(screen.getByLabelText('Date de début'), { target: { value: date } })
}

describe('CoverageStartSection (docs/decisions.md D233)', () => {
  it('explains the date, independent of memberships, and shows the saved one', async () => {
    stubApi({ [`GET ${URL}`]: () => ({ coverageStartsOn: '2026-11-01' }) })
    render(<CoverageStartSection lineStableId="l1" />)

    expect(screen.getByRole('heading', { name: 'Début de couverture de cette ligne' })).toBeInTheDocument()
    expect(screen.getByText(/indépendamment de l’inscription des membres/)).toBeInTheDocument()
    await vi.waitFor(() => expect(screen.getByLabelText('Date de début')).toHaveValue('2026-11-01'))
    expect(screen.queryByRole('button', { name: 'Vérifier l’effet' })).not.toBeInTheDocument()
  })

  it('shows what the date does before anything is written, then sends the very plan that was read', async () => {
    const onSaved = vi.fn()
    const api = stubApi({
      [`GET ${URL}`]: () => ({ coverageStartsOn: null }),
      [`POST ${URL}/preview`]: () => PLAN,
      [`PUT ${URL}`]: () => ({
        coverageStartsOn: '2026-11-01',
        changed: true,
        retiredDutyCount: 2,
        keptDutyCount: 1,
      }),
    })
    render(<CoverageStartSection lineStableId="l1" onSaved={onSaved} />)
    await vi.waitFor(() => expect(screen.getByLabelText('Date de début')).toBeEnabled())

    pick('2026-11-01')
    fireEvent.click(screen.getByRole('button', { name: 'Vérifier l’effet' }))

    expect(
      await screen.findByText(/2 gardes sans titulaire seront retirées du planning actif/),
    ).toBeInTheDocument()
    expect(screen.getByText('Vendredi 2 octobre')).toBeInTheDocument()
    expect(screen.getByText(/1 garde avec titulaire reste telle quelle/)).toBeInTheDocument()
    expect(screen.getByText(/Rabih Kortbawi/)).toBeInTheDocument()
    expect(screen.getByText(/Aucune date d’adhésion n’est modifiée/)).toBeInTheDocument()
    expect(api.requests('PUT', URL)).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByText(/2 gardes sans titulaire ont été retirées/)).toBeInTheDocument()
    expect(api.requests('PUT', URL)[0].body).toEqual({
      coverageStartsOn: '2026-11-01',
      confirmation: 'hash-1',
    })
    expect(onSaved).toHaveBeenCalledTimes(1)
  })

  it('cancelling sends nothing', async () => {
    const api = stubApi({
      [`GET ${URL}`]: () => ({ coverageStartsOn: null }),
      [`POST ${URL}/preview`]: () => PLAN,
    })
    render(<CoverageStartSection lineStableId="l1" />)
    await vi.waitFor(() => expect(screen.getByLabelText('Date de début')).toBeEnabled())

    pick('2026-11-01')
    fireEvent.click(screen.getByRole('button', { name: 'Vérifier l’effet' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Annuler' }))

    expect(screen.queryByRole('button', { name: 'Confirmer' })).not.toBeInTheDocument()
    expect(api.requests('PUT', URL)).toHaveLength(0)
  })

  it('a calendar that changed since the check asks to check again', async () => {
    stubApi({
      [`GET ${URL}`]: () => ({ coverageStartsOn: null }),
      [`POST ${URL}/preview`]: () => PLAN,
      [`PUT ${URL}`]: () => {
        throw new ApiError(409, { error: 'coverage_start_confirmation_required' }, 'conflict')
      },
    })
    render(<CoverageStartSection lineStableId="l1" />)
    await vi.waitFor(() => expect(screen.getByLabelText('Date de début')).toBeEnabled())

    pick('2026-11-01')
    fireEvent.click(screen.getByRole('button', { name: 'Vérifier l’effet' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Le calendrier a changé depuis cette vérification',
    )
  })

  it('removing the date is a choice like any other', async () => {
    const api = stubApi({
      [`GET ${URL}`]: () => ({ coverageStartsOn: '2026-11-01' }),
      [`POST ${URL}/preview`]: () => ({
        ...PLAN,
        newStartsOn: null,
        retired: [],
        kept: [],
        confirmationRequired: false,
      }),
      [`PUT ${URL}`]: () => ({
        coverageStartsOn: null,
        changed: true,
        retiredDutyCount: 0,
        keptDutyCount: 0,
      }),
    })
    render(<CoverageStartSection lineStableId="l1" />)
    await vi.waitFor(() => expect(screen.getByLabelText('Date de début')).toHaveValue('2026-11-01'))

    fireEvent.click(screen.getByRole('button', { name: 'Sans date' }))
    fireEvent.click(screen.getByRole('button', { name: 'Vérifier l’effet' }))
    expect(await screen.findByText('Aucune garde existante n’est touchée.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByText('Date enregistrée.')).toBeInTheDocument()
    expect(api.requests('PUT', URL)[0].body).toEqual({ coverageStartsOn: null, confirmation: 'hash-1' })
  })
})
