import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { EndRectificationOptions } from '../api'
import { formatDate } from '../dates'
import type { Period } from '../types'
import { RectifyPeriodEndDialog } from './RectifyPeriodEndDialog'

afterEach(cleanup)

/** docs/decisions.md D211 — Trauma Delta: period 1 published to Saturday 30 January, last duty on Thursday 28. */
const P1: Period = {
  id: 'c1',
  number: 1,
  start: '2026-10-01',
  end: '2027-01-30',
  status: 'publiee',
  collecte: { state: 'cloturee' },
}
const P2: Period = {
  id: 'c2',
  number: 2,
  start: '2027-01-31',
  end: '2027-05-02',
  status: 'preparation',
  collecte: { state: 'non-ouverte' },
}

const WEEKEND_CUT = {
  code: 'period_boundary_splits_block',
  cuts: [
    {
      lineStableId: 'l1',
      lineName: 'Première ligne',
      blockName: 'Ven · Sam · Dim',
      boundary: '2027-01-30',
      firstDay: '2027-01-29',
      lastDay: '2027-01-31',
      earlierDays: ['2027-01-29'],
      remainderDays: ['2027-01-30', '2027-01-31'],
    },
  ],
}

function options(overrides: Partial<EndRectificationOptions> = {}): EndRectificationOptions {
  return {
    period: { stableId: 'c1', number: 1 },
    startsAt: '2026-10-01',
    currentLastDay: '2027-01-30',
    lastDutyDay: '2027-01-28',
    eligible: true,
    blockers: [],
    proposedLastDay: '2027-01-28',
    reasonMaxLength: 500,
    days: [
      { date: '2027-01-28', allowed: true, reasons: [] },
      { date: '2027-01-29', allowed: false, reasons: [WEEKEND_CUT] },
    ],
    ...overrides,
  }
}

function renderDialog(loaded: EndRectificationOptions, onConfirm = vi.fn().mockResolvedValue(undefined)) {
  const loadOptions = vi.fn().mockResolvedValue(loaded)
  render(
    <RectifyPeriodEndDialog
      open
      period={P1}
      next={P2}
      loadOptions={loadOptions}
      onConfirm={onConfirm}
      onCancel={vi.fn()}
    />,
  )
  return { loadOptions, onConfirm }
}

describe('RectifyPeriodEndDialog (docs/decisions.md D211)', () => {
  it('proposes Thursday 28, says why Friday 29 is refused, and confirms only with a reason and the checkbox', async () => {
    const { onConfirm, loadOptions } = renderDialog(options())

    const thursday = await screen.findByRole('radio', { name: new RegExp(formatDate('2027-01-28', 'card')) })
    expect(thursday).toBeChecked()
    expect(
      screen.getByText(new RegExp(`la période 2 commencera le ${formatDate('2027-01-29', 'card')}`)),
    ).toBeInTheDocument()
    const friday = screen.getAllByRole('radio')[1]
    expect(friday).toHaveAttribute('value', '2027-01-29')
    expect(friday).toBeDisabled()
    expect(screen.getByText(/Couperait le bloc/)).toBeInTheDocument()
    expect(
      screen.getByText(/Aucune garde, aucune affectation ni aucune publication n’est modifiée/),
    ).toBeInTheDocument()
    expect(loadOptions).toHaveBeenCalledTimes(1)

    const button = screen.getByRole('button', { name: 'Corriger la fin' })
    expect(button).toBeDisabled()
    fireEvent.change(screen.getByLabelText('Raison de la correction'), {
      target: { value: 'Week-end entier en période 2.' },
    })
    expect(button).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox', { name: /Je confirme cette correction exceptionnelle/ }))
    expect(button).toBeEnabled()

    fireEvent.click(button)
    expect(onConfirm).toHaveBeenCalledWith('2027-01-28', 'Week-end entier en période 2.')
  })

  it('says why nothing can be corrected', async () => {
    renderDialog(
      options({
        eligible: false,
        proposedLastDay: null,
        blockers: [{ code: 'next_period_not_blank' }],
        days: [],
      }),
    )

    expect(await screen.findByRole('note')).toHaveTextContent('La période suivante a déjà une génération')
    expect(screen.queryByRole('radio')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Corriger la fin' })).toBeDisabled()
  })

  it('shows the refusal of the server', async () => {
    const onConfirm = vi
      .fn()
      .mockRejectedValue(
        new Error('Les dates de la période ont changé entre-temps. Rechargez avant de continuer.'),
      )
    renderDialog(options(), onConfirm)
    await screen.findByRole('radio', { name: new RegExp(formatDate('2027-01-28', 'card')) })
    fireEvent.change(screen.getByLabelText('Raison de la correction'), { target: { value: 'Raison' } })
    fireEvent.click(screen.getByRole('checkbox'))
    fireEvent.click(screen.getByRole('button', { name: 'Corriger la fin' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Les dates de la période ont changé entre-temps.',
    )
  })
})
