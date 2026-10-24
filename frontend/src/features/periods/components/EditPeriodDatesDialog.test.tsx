import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { DateOptions, DateReason } from '../api'
import { addDays } from '../dates'
import type { Period } from '../types'
import { BoundaryCalendar } from './BoundaryCalendar'
import { EditPeriodDatesDialog } from './EditPeriodDatesDialog'

afterEach(cleanup)

/** docs/decisions.md D207 — Trauma Delta: period 1 published, period 2 from Sunday 31 January 2027. */
const P1: Period = {
  id: 'c1',
  number: 1,
  start: '2026-10-01',
  end: '2027-01-30',
  status: 'publiee',
  collecte: { state: 'cloturee', responses: 0 },
}
const P2: Period = {
  id: 'c2',
  number: 2,
  start: '2027-01-31',
  end: '2027-05-02',
  status: 'preparation',
  collecte: { state: 'non-ouverte', responses: 0 },
}

const WEEKEND_CUT: DateReason = {
  code: 'period_boundary_splits_block',
  cuts: [
    {
      lineStableId: 'sen',
      lineName: 'Première ligne',
      blockName: 'Ven · Sam · Dim',
      boundary: '2027-04-03',
      firstDay: '2027-04-02',
      lastDay: '2027-04-04',
      earlierDays: ['2027-04-02'],
      remainderDays: ['2027-04-03', '2027-04-04'],
    },
  ],
}

/** The server's answer: a last day on Friday or Saturday cuts the weekend block. */
function endOptions(from: string, to: string, blockers: DateReason[] = []): DateOptions {
  const days: string[] = []
  for (let day = from; day <= to; day = addDays(day, 1)) days.push(day)
  return {
    period: { stableId: 'c2', number: 2 },
    edge: 'end',
    fixedStart: '2027-01-31',
    current: '2027-05-02',
    editable: blockers.length === 0,
    blockers,
    historicalStartCuts: [],
    days: days.map((date) => {
      const weekday = new Date(`${date}T00:00:00Z`).getUTCDay()
      const reasons = weekday === 5 || weekday === 6 ? [WEEKEND_CUT] : []
      return { date, allowed: reasons.length === 0, reasons }
    }),
  }
}

function renderDialog(overrides: Partial<Parameters<typeof EditPeriodDatesDialog>[0]> = {}) {
  const onSave = vi.fn().mockResolvedValue(undefined)
  const loadOptions = vi.fn((_edge: 'start' | 'end', from: string, to: string) =>
    Promise.resolve(endOptions(from, to)),
  )
  render(
    <EditPeriodDatesDialog
      open
      period={P2}
      previous={P1}
      loadOptions={loadOptions}
      onSave={onSave}
      onCancel={() => {}}
      {...overrides}
    />,
  )
  return { onSave, loadOptions, dialog: screen.getByRole('dialog', { name: 'Modifier les dates' }) }
}

describe('BoundaryCalendar', () => {
  it('shows whole weeks, says which day is possible and why another is not', () => {
    const onSelect = vi.fn()
    render(
      <BoundaryCalendar
        month="2027-04"
        onMonthChange={() => {}}
        label="Dernier jour (inclus)"
        selected="2027-04-04"
        current="2027-05-02"
        optionOf={(date) => endOptions('2027-03-29', '2027-05-02').days.find((d) => d.date === date)}
        onSelect={onSelect}
      />,
    )
    const group = screen.getByRole('group', { name: 'Dernier jour (inclus) — avril 2027' })
    // Monday 29 March → Sunday 2 May: 5 weeks.
    expect(within(group).getAllByRole('button', { name: /2027/ })).toHaveLength(35)
    expect(within(group).getByRole('button', { name: /^dimanche 4 avril 2027, possible/ })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    const friday = within(group).getByRole('button', { name: /^vendredi 2 avril 2027, impossible/ })
    expect(friday).toHaveAccessibleName(
      /Couperait le bloc « Ven · Sam · Dim » \(Première ligne\), du Ven\. 2 avr\. au Dim\. 4 avr\./,
    )
    fireEvent.click(friday)
    expect(onSelect).toHaveBeenCalledWith('2027-04-02')
  })
})

describe('EditPeriodDatesDialog (docs/decisions.md D207)', () => {
  it('keeps the start of a following period and saves a possible last day', async () => {
    const { onSave, loadOptions, dialog } = renderDialog()

    expect(within(dialog).getByText('Dim. 31 janv. 2027')).toBeInTheDocument()
    expect(
      within(dialog).getByText(
        'Lendemain du dernier jour de la période 1. Pour le déplacer, modifiez la fin de la période 1.',
      ),
    ).toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: 'Dim. 31 janv. 2027' })).not.toBeInTheDocument()
    await waitFor(() => expect(loadOptions).toHaveBeenCalledWith('end', '2027-04-26', '2027-06-06'))
    expect(loadOptions.mock.calls.every(([edge]) => edge === 'end')).toBe(true)

    const save = within(dialog).getByRole('button', { name: 'Enregistrer les dates' })
    expect(save).toBeDisabled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Mois précédent' }))
    fireEvent.click(await within(dialog).findByRole('button', { name: /^dimanche 4 avril 2027, possible/ }))
    expect(within(dialog).getByRole('status')).toHaveTextContent(
      'Du dimanche 31 janvier 2027 au dimanche 4 avril 2027 inclus · 64 jours.',
    )
    expect(save).toBeEnabled()
    fireEvent.click(save)
    await waitFor(() => expect(onSave).toHaveBeenCalledWith({ start: undefined, end: '2027-04-04' }))
  })

  it('explains a refused day and never saves it', async () => {
    const { onSave, dialog } = renderDialog()
    fireEvent.click(
      await within(dialog).findByRole('button', { name: /^vendredi 30 avril 2027, impossible/ }),
    )
    expect(within(dialog).getByRole('status')).toHaveTextContent(
      'vendredi 30 avril 2027 est impossible : Couperait le bloc « Ven · Sam · Dim » (Première ligne)',
    )
    const save = within(dialog).getByRole('button', { name: 'Enregistrer les dates' })
    expect(save).toBeDisabled()
    fireEvent.click(save)
    expect(onSave).not.toHaveBeenCalled()
  })

  it('says why nothing can move when the period is locked', async () => {
    const { dialog } = renderDialog({
      period: { ...P1 },
      previous: undefined,
      next: P2,
      loadOptions: (edge, from, to) =>
        Promise.resolve({ ...endOptions(from, to, [{ code: 'period_locked' }]), edge }),
    })
    expect(
      await within(dialog).findByText('Cette période est publiée ou validée : ses dates ne changent plus.'),
    ).toBeInTheDocument()
  })

  it('chooses the start of the first period on its own calendar and shows a refusal of the server', async () => {
    const onSave = vi.fn().mockRejectedValue(new Error('Les dates de la période ont changé entre-temps.'))
    const loadOptions = vi.fn((edge: 'start' | 'end', from: string, to: string) =>
      Promise.resolve({
        ...endOptions(from, to),
        edge,
        days: endOptions(from, to).days.map((d) => ({ ...d, allowed: true, reasons: [] })),
      }),
    )
    render(
      <EditPeriodDatesDialog
        open
        period={{ ...P1, status: 'preparation' }}
        next={P2}
        loadOptions={loadOptions}
        onSave={onSave}
        onCancel={() => {}}
      />,
    )
    const dialog = screen.getByRole('dialog', { name: 'Modifier les dates' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Jeu. 1 oct. 2026' }))
    await waitFor(() => expect(loadOptions).toHaveBeenCalledWith('start', '2026-09-28', '2026-11-01'))
    fireEvent.click(await within(dialog).findByRole('button', { name: /^lundi 5 octobre 2026, possible/ }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer les dates' }))
    await waitFor(() => expect(onSave).toHaveBeenCalledWith({ start: '2026-10-05', end: undefined }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Les dates de la période ont changé entre-temps.',
    )
  })

  it('says a collection people were written to is never resized behind their back', async () => {
    const { onSave, dialog } = renderDialog({
      loadOptions: (edge, from, to) =>
        Promise.resolve({
          ...endOptions(from, to),
          edge,
          days: endOptions(from, to).days.map((d) =>
            d.date < '2027-05-01' ? { ...d, allowed: false, reasons: [{ code: 'collection_announced' }] } : d,
          ),
        }),
    })
    fireEvent.click(
      await within(dialog).findByRole('button', { name: /^mercredi 28 avril 2027, impossible/ }),
    )
    expect(within(dialog).getByRole('status')).toHaveTextContent(
      'Les participants ont déjà été sollicités pour la collecte des disponibilités (annonce ou rappel envoyé)',
    )
    expect(within(dialog).getByRole('button', { name: 'Enregistrer les dates' })).toBeDisabled()
    expect(onSave).not.toHaveBeenCalled()
  })
})
