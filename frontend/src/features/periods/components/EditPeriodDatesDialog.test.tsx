import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { DateChangePreview, DateOptions, DateReason } from '../api'
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

/** docs/decisions.md D225: new dates that touch no calendar — saved at once, nothing to confirm. */
function preview(overrides: Partial<DateChangePreview> = {}): DateChangePreview {
  return {
    allowed: true,
    reasons: [],
    changes: [],
    retiredDutyCount: 0,
    retiredAssignedCount: 0,
    retiredPublishedCount: 0,
    retiredUnits: [],
    holders: [],
    additions: [],
    collections: [],
    swapRequestCount: 0,
    confirmationRequired: false,
    republishPeriods: [],
    recipients: [],
    canNotify: false,
    notifyBlock: null,
    pendingChanges: 0,
    impactHash: 'hash-1',
    ...overrides,
  }
}

function renderDialog(overrides: Partial<Parameters<typeof EditPeriodDatesDialog>[0]> = {}) {
  const onSave = vi.fn().mockResolvedValue(undefined)
  const loadPreview = vi.fn(() => Promise.resolve(preview()))
  const onCancel = vi.fn()
  const loadOptions = vi.fn((_edge: 'start' | 'end', from: string, to: string) =>
    Promise.resolve(endOptions(from, to)),
  )
  render(
    <EditPeriodDatesDialog
      open
      period={P2}
      previous={P1}
      loadOptions={loadOptions}
      loadPreview={loadPreview}
      onSave={onSave}
      onCancel={onCancel}
      {...overrides}
    />,
  )
  return {
    onSave,
    loadOptions,
    loadPreview,
    onCancel,
    dialog: screen.getByRole('dialog', { name: 'Modifier les dates' }),
  }
}

/** The month shown is the one of the last day (May 2027): one month back, then the last day chosen. */
async function chooseSunday4April(dialog: HTMLElement) {
  fireEvent.click(within(dialog).getByRole('button', { name: 'Mois précédent' }))
  fireEvent.click(await within(dialog).findByRole('button', { name: /^dimanche 4 avril 2027, possible/ }))
}

/** Period 2 shortened to 4 April: what the server answers once the choice touches a calendar. */
const SHORTENING = preview({
  changes: [
    {
      periodStableId: 'c2',
      periodNumber: 2,
      selected: true,
      generated: true,
      published: true,
      from: { startsAt: '2027-01-31', lastDay: '2027-05-02' },
      to: { startsAt: '2027-01-31', lastDay: '2027-04-04' },
    },
  ],
  retiredDutyCount: 3,
  retiredAssignedCount: 2,
  retiredPublishedCount: 2,
  retiredUnits: [
    {
      periodNumber: 2,
      line: 'Première ligne',
      block: 'Week-end',
      dates: ['2027-04-09', '2027-04-10'],
      holder: 'Alice Bernard',
      published: true,
    },
    {
      periodNumber: 2,
      line: 'Première ligne',
      block: null,
      dates: ['2027-04-20'],
      holder: null,
      published: false,
    },
  ],
  holders: [{ name: 'Alice Bernard', dutyCount: 2 }],
  confirmationRequired: true,
  republishPeriods: [2],
  recipients: ['Alice Bernard'],
  canNotify: true,
  impactHash: 'plan-of-the-server',
})

/** Up to the confirmation, nothing written. */
async function confirmationOf(answer: DateChangePreview, overrides = {}) {
  const context = renderDialog({ loadPreview: vi.fn(() => Promise.resolve(answer)), ...overrides })
  await chooseSunday4April(context.dialog)
  fireEvent.click(within(context.dialog).getByRole('button', { name: 'Enregistrer les dates' }))
  await within(context.dialog).findByRole('heading', { name: 'Confirmer les nouvelles dates' })
  return context
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

describe('EditPeriodDatesDialog — a date that cuts a block, and what a date change touches (D229, D230)', () => {
  const period: Period = {
    id: 'p1',
    number: 1,
    start: '2027-01-04',
    end: '2027-01-31',
    status: 'publiee',
  } as Period
  const plan = (over: Partial<DateChangePreview>): DateChangePreview =>
    ({
      allowed: true,
      reasons: [],
      alternatives: { start: null, end: null },
      blockCuts: [],
      preparation: [],
      changes: [],
      retiredDutyCount: 0,
      retiredAssignedCount: 0,
      retiredPublishedCount: 0,
      retiredUnits: [],
      holders: [],
      additions: [],
      collections: [],
      swapRequestCount: 0,
      confirmationRequired: false,
      republishPeriods: [],
      recipients: [],
      canNotify: false,
      notifyBlock: 'nobody_to_notify',
      pendingChanges: 0,
      impactHash: 'hash',
      ...over,
    }) as DateChangePreview
  const options = (): DateOptions =>
    ({
      period: { stableId: 'p1', number: 1 },
      edge: 'end',
      fixedStart: '2027-01-04',
      current: '2027-01-31',
      editable: true,
      blockers: [],
      historicalStartCuts: [],
      days: [
        { date: '2027-01-14', allowed: true, reasons: [], warnings: [] },
        {
          date: '2027-01-15',
          allowed: true,
          reasons: [],
          warnings: [{ code: 'period_boundary_splits_block' }],
        },
      ],
    }) as DateOptions

  async function open(preview: DateChangePreview) {
    const onSave = vi.fn(() => Promise.resolve())
    render(
      <EditPeriodDatesDialog
        open
        period={period}
        loadOptions={() => Promise.resolve(options())}
        loadPreview={() => Promise.resolve(preview)}
        onSave={onSave}
        onCancel={() => undefined}
      />,
    )
    fireEvent.click(
      await screen.findByRole('button', { name: /vendredi 15 janvier 2027, possible, coupe un bloc/ }),
    )
    return onSave
  }

  it('says the day cuts a block, proposes the dates that keep it whole, and the proposal replaces the date', async () => {
    await open(
      plan({
        confirmationRequired: true,
        alternatives: { start: null, end: { before: '2027-01-14', after: '2027-01-17' } },
      }),
    )
    expect(await screen.findByText(/Cette date coupe un bloc de gardes/)).toBeInTheDocument()
    const notice = screen
      .getByText(/Cette date coupe un bloc de gardes/)
      .closest('.mv-cut-notice') as HTMLElement
    fireEvent.click(within(notice).getByRole('button', { name: /14 janv/ }))
    expect(document.querySelector('.mv-date-choice.is-active')).toHaveTextContent('Jeu. 14 janv. 2027')
  })

  it('keeps the chosen date on confirmation: the days kept, their holder, the collection and the planned dates are all said', async () => {
    const onSave = await open(
      plan({
        confirmationRequired: true,
        alternatives: { start: null, end: { before: '2027-01-14', after: '2027-01-17' } },
        retiredDutyCount: 2,
        retiredAssignedCount: 2,
        retiredUnits: [
          {
            periodNumber: 1,
            line: 'Première ligne',
            block: 'Week-end',
            dates: ['2027-01-16', '2027-01-17'],
            keptDates: ['2027-01-15'],
            holder: 'Basile Leroy',
            published: true,
          },
        ],
        blockCuts: [
          {
            lineStableId: 'l',
            lineName: 'Première ligne',
            blockName: 'Week-end',
            boundary: '2027-01-16',
            firstDay: '2027-01-15',
            lastDay: '2027-01-17',
            earlierDays: ['2027-01-15'],
            remainderDays: ['2027-01-16', '2027-01-17'],
          },
        ],
        collections: [
          {
            periodNumber: 1,
            startsAt: '2027-01-04',
            lastDay: '2027-01-31',
            newStartsAt: '2027-01-04',
            newLastDay: '2027-01-15',
            contacted: true,
            answered: true,
            kind: 'reduced',
            open: true,
            expected: 6,
            responses: 2,
            messagesSent: 3,
            deadline: '2027-01-20',
            newDeadline: '2027-01-15',
            uncoveredAddedDays: 0,
          },
        ],
        preparation: [
          { periodNumber: 1, kind: 'target', from: '2027-01-25', to: '2027-01-15' },
          { periodNumber: 1, kind: 'announcement', from: '2027-01-20', to: null },
        ],
      }),
    )
    await waitFor(() => expect(screen.getByRole('button', { name: 'Enregistrer les dates' })).toBeEnabled())
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer les dates' }))

    expect(await screen.findByText(/Ces dates coupent 1 bloc/)).toBeInTheDocument()
    expect(screen.getByText(/Bloc coupé/)).toHaveTextContent('reste attribué à Basile Leroy')
    const collection = screen.getByText(/sera réduite/)
    expect(collection).toHaveTextContent('Elle reste ouverte')
    expect(collection).toHaveTextContent('2 réponses sur 6 personnes attendues')
    expect(collection).toHaveTextContent('3 messages ont déjà été envoyés')
    expect(collection).toHaveTextContent('aucun nouveau message')
    expect(screen.getByText(/date cible de génération/)).toBeInTheDocument()
    expect(screen.getByText(/L’annonce prévue/)).toHaveTextContent('annulée')

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }))
    await waitFor(() => expect(onSave).toHaveBeenCalled())
    expect(onSave).toHaveBeenCalledWith(
      expect.objectContaining({ end: '2027-01-15', confirmation: 'hash', notify: false }),
    )
  })
})

describe('EditPeriodDatesDialog (docs/decisions.md D207)', () => {
  it('says the first day of a following period moves with the end of the previous one, and saves a possible last day', async () => {
    const { onSave, loadOptions, dialog } = renderDialog()

    expect(within(dialog).getByRole('button', { name: 'Dim. 31 janv. 2027' })).toBeInTheDocument()
    expect(
      within(dialog).getByText('Le lendemain du dernier jour de la période 1 : les deux bougent ensemble.'),
    ).toBeInTheDocument()
    await waitFor(() => expect(loadOptions).toHaveBeenCalledWith('end', '2027-04-26', '2027-06-06'))
    // The other edge is read only once it is chosen.
    expect(loadOptions.mock.calls.every(([edge]) => edge === 'end')).toBe(true)

    const save = within(dialog).getByRole('button', { name: 'Enregistrer les dates' })
    expect(save).toBeDisabled()
    await chooseSunday4April(dialog)
    expect(within(dialog).getByRole('status')).toHaveTextContent(
      'Du dimanche 31 janvier 2027 au dimanche 4 avril 2027 inclus · 64 jours.',
    )
    expect(save).toBeEnabled()
    fireEvent.click(save)
    await waitFor(() => expect(onSave).toHaveBeenCalledWith({ start: undefined, end: '2027-04-04' }))
  })

  it('the first day of a following period is editable too: on its own calendar, read when chosen', async () => {
    const onSave = vi.fn().mockResolvedValue(undefined)
    const loadOptions = vi.fn((edge: 'start' | 'end', from: string, to: string) =>
      Promise.resolve({
        ...endOptions(from, to),
        edge,
        days: endOptions(from, to).days.map((d) => ({ ...d, allowed: true, reasons: [] })),
      }),
    )
    const { dialog } = renderDialog({ onSave, loadOptions })

    fireEvent.click(within(dialog).getByRole('button', { name: 'Dim. 31 janv. 2027' }))
    await waitFor(() => expect(loadOptions).toHaveBeenCalledWith('start', '2026-12-28', '2027-01-31'))
    fireEvent.click(await within(dialog).findByRole('button', { name: /^lundi 25 janvier 2027, possible/ }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer les dates' }))

    await waitFor(() => expect(onSave).toHaveBeenCalledWith({ start: '2027-01-25', end: undefined }))
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
        loadPreview={() => Promise.resolve(preview())}
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

describe('EditPeriodDatesDialog — what the new dates do (docs/decisions.md D225)', () => {
  it('nothing to confirm: reads the plan again on save, then saves directly', async () => {
    const { onSave, loadPreview, dialog } = renderDialog()
    await chooseSunday4April(dialog)
    await waitFor(() => expect(loadPreview).toHaveBeenCalledWith({ start: undefined, end: '2027-04-04' }))
    const reads = loadPreview.mock.calls.length

    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer les dates' }))

    await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1))
    expect(loadPreview.mock.calls.length).toBe(reads + 1)
    expect(onSave.mock.calls[0][0].confirmation).toBeUndefined()
    expect(onSave.mock.calls[0][0].notify).toBeUndefined()
    expect(screen.queryByRole('heading', { name: 'Confirmer les nouvelles dates' })).not.toBeInTheDocument()
  })

  it('never saves on what the screen last knew: a plan that now touches a calendar is confirmed first', async () => {
    const loadPreview = vi
      .fn<(dates: { start?: string; end?: string }) => Promise<DateChangePreview>>()
      .mockResolvedValueOnce(preview())
      .mockResolvedValue(SHORTENING)
    const { onSave, dialog } = renderDialog({ loadPreview })
    await chooseSunday4April(dialog)
    await waitFor(() => expect(loadPreview).toHaveBeenCalledTimes(1))

    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer les dates' }))

    expect(
      await within(dialog).findByRole('heading', { name: 'Confirmer les nouvelles dates' }),
    ).toBeInTheDocument()
    expect(onSave).not.toHaveBeenCalled()
  })

  it('says so, and saves nothing, when the consequences could not be checked', async () => {
    const loadPreview = vi.fn(() => Promise.reject(new Error('down')))
    const { onSave, dialog } = renderDialog({ loadPreview })
    await chooseSunday4April(dialog)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer les dates' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Les conséquences de ces dates n’ont pas pu être vérifiées.',
    )
    expect(onSave).not.toHaveBeenCalled()
  })

  it('a shortening: the exact sentence, the count and the holders, before anything is saved', async () => {
    const { onSave, dialog } = await confirmationOf(SHORTENING)

    expect(
      within(dialog).getByText(
        'Cette période se terminera désormais le dimanche 4 avril 2027. Les gardes situées après cette date ne feront plus partie de la période.',
      ),
    ).toBeInTheDocument()
    expect(
      within(dialog).getByRole('heading', { name: '3 gardes retirées du planning, dont 2 attribuées' }),
    ).toBeInTheDocument()
    const units = within(within(dialog).getByRole('list', { name: 'Gardes retirées' })).getAllByRole(
      'listitem',
    )
    expect(units).toHaveLength(2)
    expect(units[0]).toHaveTextContent('Ven. 9 avr. 2027 → Sam. 10 avr. 2027 · Week-end, bloc entier')
    expect(units[0]).toHaveTextContent('Première ligne · Alice Bernard · publiée')
    expect(units[1]).toHaveTextContent('Première ligne · non attribuée')
    expect(within(dialog).getByText(/Rien n’est effacé/)).toBeInTheDocument()
    expect(onSave).not.toHaveBeenCalled()
  })

  it('offers exactly three choices', async () => {
    const { dialog } = await confirmationOf(SHORTENING)

    const foot = dialog.querySelector('.mv-dialog__foot') as HTMLElement
    expect(
      within(foot)
        .getAllByRole('button')
        .map((button) => button.textContent),
    ).toEqual(['Annuler', 'Confirmer', 'Confirmer et avertir'])
  })

  it('« Annuler » goes back to the dates, nothing saved, the dialog still open', async () => {
    const { onSave, onCancel, dialog } = await confirmationOf(SHORTENING)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Annuler' }))

    expect(within(dialog).getByRole('heading', { name: 'Modifier les dates' })).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Enregistrer les dates' })).toBeEnabled()
    expect(within(dialog).getByRole('button', { name: 'Dim. 4 avr. 2027' })).toBeInTheDocument()
    expect(onSave).not.toHaveBeenCalled()
    expect(onCancel).not.toHaveBeenCalled()
  })

  it('« Confirmer » saves with the hash of the plan read, nobody told', async () => {
    const { onSave, dialog } = await confirmationOf(SHORTENING)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer' }))

    await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1))
    expect(onSave).toHaveBeenCalledWith({
      start: undefined,
      end: '2027-04-04',
      confirmation: 'plan-of-the-server',
      notify: false,
    })
  })

  it('« Confirmer et avertir » says who is told, and saves with notify', async () => {
    const { onSave, dialog } = await confirmationOf(SHORTENING)

    expect(within(dialog).getByText(/écrit uniquement à : Alice Bernard\./)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer et avertir' }))

    await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1))
    expect(onSave).toHaveBeenCalledWith({
      start: undefined,
      end: '2027-04-04',
      confirmation: 'plan-of-the-server',
      notify: true,
    })
  })

  it.each([
    ['nobody_to_notify', /Personne à avertir/],
    ['pending_changes', /d’autres modifications attendent d’être publiées/],
    ['not_republishable', /ne peut pas être republié tel quel/],
  ] as const)('« Confirmer et avertir » is disabled, and says why: %s', async (notifyBlock, reason) => {
    const { dialog } = await confirmationOf({ ...SHORTENING, canNotify: false, notifyBlock, recipients: [] })

    expect(within(dialog).getByRole('button', { name: 'Confirmer et avertir' })).toBeDisabled()
    expect(within(dialog).getByRole('button', { name: 'Confirmer' })).toBeEnabled()
    expect(within(dialog).getByText(reason)).toBeInTheDocument()
    expect(within(dialog).queryByText(/écrit uniquement à/)).not.toBeInTheDocument()
  })

  it('a double click sends one request, on « Enregistrer » as on « Confirmer »', async () => {
    let release: () => void = () => {}
    const onSave = vi.fn(() => new Promise<void>((resolve) => (release = resolve)))
    const direct = renderDialog({ onSave })
    await chooseSunday4April(direct.dialog)
    const save = within(direct.dialog).getByRole('button', { name: 'Enregistrer les dates' })
    fireEvent.click(save)
    fireEvent.click(save)
    await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1))
    fireEvent.click(save)
    release()
    await waitFor(() => expect(save).toBeEnabled())
    expect(onSave).toHaveBeenCalledTimes(1)
    cleanup()

    onSave.mockClear()
    const { dialog } = await confirmationOf(SHORTENING, { onSave })
    const confirm = within(dialog).getByRole('button', { name: 'Confirmer' })
    fireEvent.click(confirm)
    fireEvent.click(confirm)
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer et avertir' }))
    await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1))
    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ notify: false }))
    expect(confirm).toBeDisabled()
    release()
    await waitFor(() => expect(confirm).toBeEnabled())
    expect(onSave).toHaveBeenCalledTimes(1)
  })

  it('a refusal stays on the confirmation, says why, and reads what would now happen', async () => {
    const onSave = vi.fn().mockRejectedValue(new Error('Le calendrier a changé depuis cet aperçu.'))
    const loadPreview = vi.fn(() => Promise.resolve(SHORTENING))
    const { dialog } = await confirmationOf(SHORTENING, { onSave, loadPreview })
    const reads = loadPreview.mock.calls.length

    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Le calendrier a changé depuis cet aperçu.',
    )
    expect(within(dialog).getByRole('heading', { name: 'Confirmer les nouvelles dates' })).toBeInTheDocument()
    await waitFor(() => expect(loadPreview.mock.calls.length).toBe(reads + 1))
  })

  it('says as soon as the date is chosen which new days there are, and « Compléter la période N »', async () => {
    const { dialog } = renderDialog({
      loadPreview: () =>
        Promise.resolve(
          preview({
            changes: [
              {
                periodStableId: 'c2',
                periodNumber: 2,
                selected: true,
                generated: true,
                published: true,
                from: { startsAt: '2027-01-31', lastDay: '2027-05-02' },
                to: { startsAt: '2027-01-31', lastDay: '2027-04-04' },
              },
            ],
            additions: [
              {
                periodNumber: 2,
                line: 'Première ligne',
                firstDay: '2027-05-03',
                lastDay: '2027-05-16',
                dutyCount: 14,
                published: true,
              },
              {
                periodNumber: 2,
                line: 'Renfort',
                firstDay: '2027-05-03',
                lastDay: '2027-05-16',
                dutyCount: null,
                published: false,
              },
            ],
          }),
        ),
    })
    await chooseSunday4April(dialog)

    const summary = await within(dialog).findByRole('list', { name: 'Conséquences de ces dates' })
    const items = within(summary).getAllByRole('listitem')
    expect(items[0]).toHaveTextContent(
      'Première ligne : du lundi 3 mai 2027 au dimanche 16 mai 2027, 14 nouvelles gardes non attribuées. Utilisez ensuite « Compléter la période 2 ». Ces nouvelles gardes resteront à republier.',
    )
    expect(items[1]).toHaveTextContent('les renforts dépendront des gardes de la ligne source')
    expect(items[1]).toHaveTextContent('Utilisez ensuite « Compléter la période 2 ».')
    expect(items[1]).not.toHaveTextContent('à republier')
  })

  it('says how the next period moves — and that nothing assigned is concerned when that is all', async () => {
    const moved = {
      periodStableId: 'c3',
      periodNumber: 3,
      selected: false,
      generated: false,
      published: false,
      from: { startsAt: '2027-05-03', lastDay: '2027-08-01' },
      to: { startsAt: '2027-04-05', lastDay: '2027-08-01' },
    }
    const selected = {
      periodStableId: 'c2',
      periodNumber: 2,
      selected: true,
      generated: false,
      published: false,
      from: { startsAt: '2027-01-31', lastDay: '2027-05-02' },
      to: { startsAt: '2027-01-31', lastDay: '2027-04-04' },
    }
    const { dialog } = renderDialog({
      loadPreview: () => Promise.resolve(preview({ changes: [selected, moved] })),
    })
    await chooseSunday4April(dialog)

    const summary = await within(dialog).findByRole('list', { name: 'Conséquences de ces dates' })
    expect(within(summary).getByText('La période 3 commencera le lundi 5 avril 2027.')).toBeInTheDocument()
    cleanup()

    const alone = renderDialog({
      loadPreview: () => Promise.resolve(preview({ changes: [selected] })),
    })
    await chooseSunday4April(alone.dialog)
    expect(
      await within(alone.dialog).findByText('Aucune garde attribuée n’est concernée.'),
    ).toBeInTheDocument()
  })

  it('warns on the confirmation when a neighbour already generated moves', async () => {
    const { dialog } = await confirmationOf({
      ...SHORTENING,
      changes: [
        ...SHORTENING.changes,
        {
          periodStableId: 'c3',
          periodNumber: 3,
          selected: false,
          generated: true,
          published: true,
          from: { startsAt: '2027-05-03', lastDay: '2027-08-01' },
          to: { startsAt: '2027-04-05', lastDay: '2027-08-01' },
        },
      ],
    })

    expect(
      within(dialog).getByText(
        'La période 3, déjà générée et publiée, irait désormais du lundi 5 avril 2027 au dimanche 1 août 2027 (au lieu du lundi 3 mai 2027 au dimanche 1 août 2027).',
      ),
    ).toBeInTheDocument()
  })
})
