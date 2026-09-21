import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { status, stubApi } from '../../testUtils/stubApi'
import { ExtendPlanningForm } from './ExtendPlanningForm'
import type { PlanningDetail } from './types'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

const PLANNING: PlanningDetail = {
  stableId: 'plan-1',
  name: 'Gardes',
  creatorStableId: 'u1',
  canManage: true,
  startsAt: '2026-09-01',
  endsAt: '2027-01-01',
  timezone: 'Europe/Brussels',
  createdAt: '',
  updatedAt: '',
  lines: [],
}

function open(onExtended = vi.fn()) {
  render(<ExtendPlanningForm planning={PLANNING} onExtended={onExtended} />)
  fireEvent.click(screen.getByRole('button', { name: /^Prolonger$/ }))
  return onExtended
}

describe('ExtendPlanningForm', () => {
  it('sends the new end and the deadline, then says which window is now expected', async () => {
    const api = stubApi({
      'POST /api/plannings/plan-1/extensions': () => ({
        planning: { stableId: 'plan-1', startsAt: '2026-09-01', endsAt: '2027-04-01' },
        collections: [
          { stableId: 'c2', startsAt: '2027-01-01', endsAt: '2027-04-01', deadline: '2026-12-20' },
        ],
      }),
    })
    const onExtended = open()

    fireEvent.change(screen.getByLabelText('Nouvelle fin'), { target: { value: '2027-04-01' } })
    fireEvent.change(screen.getByLabelText('Échéance de réponse (facultatif)'), {
      target: { value: '2026-12-20' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Prolonger et ouvrir la collecte' }))

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Planning prolongé. Disponibilités attendues pour janvier–mars 2027.',
    )
    expect(api.requests('POST', '/api/plannings/plan-1/extensions')[0].body).toEqual({
      endsAt: '2027-04-01',
      deadline: '2026-12-20',
    })
    expect(onExtended).toHaveBeenCalledTimes(1)
  })

  it('cannot be submitted without a new date beyond the current range', () => {
    stubApi({})
    open()
    const submit = screen.getByRole('button', { name: 'Prolonger et ouvrir la collecte' })
    expect(submit).toBeDisabled()

    // Same end as today: nothing new to collect.
    fireEvent.change(screen.getByLabelText('Nouvelle fin'), { target: { value: '2027-01-01' } })
    expect(submit).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Nouvelle fin'), { target: { value: '2027-01-02' } })
    expect(submit).toBeEnabled()
  })

  it('accepts an earlier start too', async () => {
    const api = stubApi({
      'POST /api/plannings/plan-1/extensions': () => ({
        planning: { stableId: 'plan-1', startsAt: '2026-08-01', endsAt: '2027-01-01' },
        collections: [{ stableId: 'c2', startsAt: '2026-08-01', endsAt: '2026-09-01', deadline: null }],
      }),
    })
    open()

    fireEvent.change(screen.getByLabelText('Nouveau début (facultatif)'), { target: { value: '2026-08-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Prolonger et ouvrir la collecte' }))

    expect(await screen.findByRole('status')).toHaveTextContent('août 2026')
    expect(api.requests('POST', '/api/plannings/plan-1/extensions')[0].body).toEqual({
      startsAt: '2026-08-01',
    })
  })

  it.each([
    ['no_new_range', 422, "n'ajoutent aucune nouvelle journée"],
    ['range_shrink_not_supported', 422, 'ne peut qu’être prolongé'],
    ['planning_period_locked', 409, 'déjà validée ou publiée'],
  ])('explains the refusal %s', async (code, httpStatus, message) => {
    stubApi({ 'POST /api/plannings/plan-1/extensions': () => status(httpStatus, { error: code }) })
    const onExtended = open()

    fireEvent.change(screen.getByLabelText('Nouvelle fin'), { target: { value: '2027-04-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Prolonger et ouvrir la collecte' }))

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(message))
    expect(onExtended).not.toHaveBeenCalled()
  })

  it('states that the end date is exclusive', () => {
    stubApi({})
    open()

    expect(screen.getByText(/la date de fin est exclue/)).toBeInTheDocument()
  })
})
