import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../../lib/apiClient'
import type { PeriodPreparation, PreparationInput } from '../api'
import { formatDate } from '../dates'
import { PeriodPreparationPanel } from './PeriodPreparationPanel'

const api = vi.hoisted(() => ({
  fetchPeriodPreparation: vi.fn(),
  savePeriodPreparation: vi.fn(),
}))
vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  ...api,
}))

afterEach(cleanup)
beforeEach(() => {
  api.fetchPeriodPreparation.mockReset()
  api.savePeriodPreparation.mockReset()
})

const NO_EMAILS = { queued: 0, sent: 0, failed: 0, cancelled: 0, pending: 0 }

/** docs/decisions.md D210 — period 2 of Trauma Delta, nothing planned yet, on 24 October 2026. */
function blank(overrides: Partial<PeriodPreparation> = {}): PeriodPreparation {
  return {
    period: { stableId: 'c2', number: 2, startsAt: '2027-01-31', lastDay: '2027-05-02' },
    timezone: 'Europe/Brussels',
    sendTime: '08:00',
    today: '2026-10-24',
    editable: true,
    blockers: [],
    announcement: { on: null, state: 'none', outcome: null, executedAt: null, emails: NO_EMAILS },
    targetGenerationOn: null,
    collection: { state: 'not_open', responded: 0, expected: 0 },
    reminders: [],
    ...overrides,
  }
}

async function renderPanel(view: PeriodPreparation) {
  api.fetchPeriodPreparation.mockResolvedValue(view)
  render(<PeriodPreparationPanel planningStableId="p1" periodStableId="c2" />)
  return screen.findByRole('heading', { name: 'Préparer la période 2' })
}

function day(name: RegExp): HTMLElement {
  return screen.getByRole('button', { name })
}

describe('PeriodPreparationPanel', () => {
  it('shows the period, an announcement not planned, the collection not open and no target', async () => {
    await renderPanel(blank())

    expect(
      screen.getByText(new RegExp(`Du ${formatDate('2027-01-31')} au ${formatDate('2027-05-02')}`)),
    ).toBeInTheDocument()
    expect(screen.getByText(/08 h 00 \(heure de Bruxelles\)/)).toBeInTheDocument()
    expect(screen.getByText('Non programmée')).toBeInTheDocument()
    expect(screen.getByText('Pas encore ouverte')).toBeInTheDocument()
    expect(screen.getByText('Non définie')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer la préparation' })).toBeDisabled()
  })

  it('chooses the announcement on a calendar that says why a past day is refused', async () => {
    await renderPanel(blank())

    fireEvent.click(screen.getAllByRole('button', { name: 'Choisir la date' })[0])
    const calendar = screen.getByRole('group', { name: /Date d’annonce — octobre 2026/ })
    expect(
      within(calendar).getByRole('button', {
        name: /20 octobre 2026, impossible : Ce jour est déjà passé\./,
      }),
    ).toBeInTheDocument()

    fireEvent.click(day(/20 octobre 2026/))
    expect(screen.getByText('Non programmée')).toBeInTheDocument()

    fireEvent.click(day(/26 octobre 2026, possible/))
    expect(screen.getByText(formatDate('2026-10-26'))).toBeInTheDocument()
    expect(screen.getByText('Programmée')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer la préparation' })).toBeEnabled()
  })

  it('places reminders before the target date, flags two on the same day, and saves the plan', async () => {
    await renderPanel(
      blank({
        announcement: {
          on: '2026-11-02',
          state: 'scheduled',
          outcome: null,
          executedAt: null,
          emails: NO_EMAILS,
        },
      }),
    )

    expect(screen.queryByRole('button', { name: 'Ajouter un rappel' })).toBeEnabled()
    // The target date: not on or before the announcement — said next to it, the day stays choosable.
    fireEvent.click(screen.getAllByRole('button', { name: 'Choisir la date' })[0])
    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))
    fireEvent.click(day(/^lundi 2 novembre 2026, possible/))
    expect(screen.getByText('La date cible de génération doit venir après l’annonce.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer la préparation' })).toBeDisabled()
    fireEvent.click(screen.getAllByRole('button', { name: 'Changer la date' })[1])
    fireEvent.click(day(/30 novembre 2026, possible/))
    expect(
      screen.queryByText('La date cible de génération doit venir après l’annonce.'),
    ).not.toBeInTheDocument()
    expect(screen.getByText(formatDate('2026-11-30'))).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un rappel' }))
    expect(screen.getByText(`Le ${formatDate('2026-11-23')} à 8 h`)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un rappel' }))
    expect(screen.getAllByText('Deux rappels tombent le même jour.')).toHaveLength(2)
    expect(screen.getByRole('button', { name: 'Enregistrer la préparation' })).toBeDisabled()

    fireEvent.change(screen.getByRole('spinbutton', { name: 'Rappel 2 : combien avant la date cible' }), {
      target: { value: '2' },
    })
    fireEvent.change(screen.getByRole('combobox', { name: 'Rappel 2 : unité' }), {
      target: { value: 'WEEK' },
    })
    expect(screen.getByText(`Le ${formatDate('2026-11-16')} à 8 h`)).toBeInTheDocument()

    api.savePeriodPreparation.mockImplementation(async (_p: string, _c: string, input: PreparationInput) =>
      blank({
        announcement: {
          on: input.announceOn,
          state: 'scheduled',
          outcome: null,
          executedAt: null,
          emails: NO_EMAILS,
        },
        targetGenerationOn: input.targetGenerationOn,
        reminders: [
          {
            stableId: 'r2',
            value: 2,
            unit: 'WEEK',
            dueOn: '2026-11-16',
            state: 'scheduled',
            outcome: null,
            executedAt: null,
            emails: NO_EMAILS,
          },
          {
            stableId: 'r1',
            value: 7,
            unit: 'DAY',
            dueOn: '2026-11-23',
            state: 'scheduled',
            outcome: null,
            executedAt: null,
            emails: NO_EMAILS,
          },
        ],
      }),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la préparation' }))

    expect(await screen.findByText('Préparation enregistrée.')).toBeInTheDocument()
    expect(api.savePeriodPreparation).toHaveBeenCalledWith('p1', 'c2', {
      announceOn: '2026-11-02',
      targetGenerationOn: '2026-11-30',
      reminders: [
        { value: 7, unit: 'DAY' },
        { value: 2, unit: 'WEEK' },
      ],
    })
  })

  it('changes and removes reminders not sent yet, by their stable id', async () => {
    await renderPanel(
      blank({
        targetGenerationOn: '2026-12-15',
        reminders: [
          {
            stableId: 'r1',
            value: 2,
            unit: 'WEEK',
            dueOn: '2026-12-01',
            state: 'scheduled',
            outcome: null,
            executedAt: null,
            emails: NO_EMAILS,
          },
          {
            stableId: 'r2',
            value: 3,
            unit: 'DAY',
            dueOn: '2026-12-12',
            state: 'scheduled',
            outcome: null,
            executedAt: null,
            emails: NO_EMAILS,
          },
        ],
      }),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Supprimer le rappel 1' }))
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Rappel 1 : combien avant la date cible' }), {
      target: { value: '5' },
    })
    api.savePeriodPreparation.mockResolvedValue(blank())
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la préparation' }))

    await waitFor(() =>
      expect(api.savePeriodPreparation).toHaveBeenCalledWith('p1', 'c2', {
        announceOn: null,
        targetGenerationOn: '2026-12-15',
        reminders: [{ stableId: 'r2', value: 5, unit: 'DAY' }],
      }),
    )
  })

  it('says why the server refused the plan', async () => {
    await renderPanel(blank({ targetGenerationOn: '2026-12-15' }))
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un rappel' }))
    api.savePeriodPreparation.mockRejectedValue(
      new ApiError(422, { error: 'reminder_before_announcement' }, 'Unprocessable'),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la préparation' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Un rappel doit venir après l’annonce.')
  })

  it('once the generation is launched: read only, with what was sent and what was cancelled', async () => {
    await renderPanel(
      blank({
        editable: false,
        blockers: ['period_generation_started'],
        announcement: {
          on: '2026-11-02',
          state: 'executed',
          outcome: 'collection_opened',
          executedAt: '2026-11-02T07:00:00+00:00',
          emails: { ...NO_EMAILS, queued: 9, sent: 9 },
        },
        targetGenerationOn: '2026-12-15',
        collection: { state: 'open', responded: 6, expected: 9 },
        reminders: [
          {
            stableId: 'r1',
            value: 2,
            unit: 'WEEK',
            dueOn: '2026-12-01',
            state: 'executed',
            outcome: 'sent_to_pending',
            executedAt: '2026-12-01T07:00:00+00:00',
            emails: { ...NO_EMAILS, queued: 4, sent: 4 },
          },
          {
            stableId: 'r2',
            value: 3,
            unit: 'DAY',
            dueOn: '2026-12-12',
            state: 'cancelled',
            outcome: 'generation_started',
            executedAt: '2026-12-05T10:00:00+00:00',
            emails: NO_EMAILS,
          },
        ],
      }),
    )

    expect(screen.getByRole('note')).toHaveTextContent(
      'La génération de cette période a été lancée : sa préparation ne change plus.',
    )
    expect(screen.getByText('Faite')).toBeInTheDocument()
    expect(screen.getByText(/collecte ouverte · 9 emails envoyés/)).toBeInTheDocument()
    expect(screen.getByText('6 / 9 confirmations')).toBeInTheDocument()
    const history = screen.getByRole('list', { name: 'Rappels passés' })
    expect(within(history).getByText('Exécuté')).toBeInTheDocument()
    expect(
      within(history).getByText(/envoyé aux personnes sans confirmation · 4 emails envoyés/),
    ).toBeInTheDocument()
    expect(within(history).getByText('Annulé')).toBeInTheDocument()
    expect(within(history).getByText('la génération a été lancée')).toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: /Choisir la date|Changer la date|Ajouter un rappel|Enregistrer/ }),
    ).not.toBeInTheDocument()
  })

  it('tells when the preparation cannot be loaded', async () => {
    api.fetchPeriodPreparation.mockRejectedValue(new Error('offline'))
    render(<PeriodPreparationPanel planningStableId="p1" periodStableId="c2" />)

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'La préparation de la période n’a pas pu être chargée.',
    )
  })
})

/**
 * The bug of Trauma Delta, period 2 (28 October 2026): announcement on 17
 * January, target on 24 January, one reminder 2 days before, the collection
 * already opened by hand. Once saved, every day refused by the order of the
 * two dates was a click that did nothing — no message, the button still
 * greyed, « Préparation enregistrée » still shown: the plan could not move.
 */
describe('PeriodPreparationPanel — a saved plan that changes (bug of 28 October 2026)', () => {
  const reminder = (
    stableId: string,
    value: number,
    unit: 'DAY' | 'WEEK',
    dueOn: string,
  ): PeriodPreparation['reminders'][number] => ({
    stableId,
    value,
    unit,
    dueOn,
    state: 'scheduled',
    outcome: null,
    executedAt: null,
    emails: NO_EMAILS,
  })
  const traumaDelta = (overrides: Partial<PeriodPreparation> = {}): PeriodPreparation =>
    blank({
      period: { stableId: 'c2', number: 2, startsAt: '2027-01-29', lastDay: '2027-05-02' },
      today: '2026-10-28',
      announcement: {
        on: '2027-01-17',
        state: 'scheduled',
        outcome: null,
        executedAt: null,
        emails: NO_EMAILS,
      },
      targetGenerationOn: '2027-01-24',
      collection: { state: 'open', responded: 0, expected: 17 },
      reminders: [reminder('r1', 2, 'DAY', '2027-01-22')],
      ...overrides,
    })
  const saveButton = () => screen.getByRole('button', { name: 'Enregistrer la préparation' })
  const changeDate = (row: 0 | 1) =>
    fireEvent.click(screen.getAllByRole('button', { name: 'Changer la date' })[row])
  const months = (direction: 'Mois suivant' | 'Mois précédent', count: number) => {
    for (let i = 0; i < count; i++) fireEvent.click(screen.getByRole('button', { name: direction }))
  }

  it('moves the whole plan earlier, the target first: the order is an error next to it, never a dead click', async () => {
    await renderPanel(traumaDelta())
    expect(saveButton()).toBeDisabled()

    changeDate(1)
    fireEvent.click(day(/15 janvier 2027, possible/))
    expect(screen.getByText(formatDate('2027-01-15'))).toBeInTheDocument()
    expect(screen.getByText('La date cible de génération doit venir après l’annonce.')).toBeInTheDocument()
    // The reminder, now 13 January, before the announcement: said too.
    expect(screen.getByText('Un rappel doit venir après l’annonce.')).toBeInTheDocument()
    expect(screen.getByText('Corrigez les points signalés en rouge pour enregistrer.')).toBeInTheDocument()
    expect(saveButton()).toBeDisabled()

    changeDate(0)
    fireEvent.click(day(/10 janvier 2027, possible/))
    expect(screen.queryByText(/doit venir après l’annonce/)).not.toBeInTheDocument()
    expect(screen.getByText(`Le ${formatDate('2027-01-13')} à 8 h`)).toBeInTheDocument()
    expect(saveButton()).toBeEnabled()

    api.savePeriodPreparation.mockResolvedValue(
      traumaDelta({
        announcement: {
          on: '2027-01-10',
          state: 'scheduled',
          outcome: null,
          executedAt: null,
          emails: NO_EMAILS,
        },
        targetGenerationOn: '2027-01-15',
        reminders: [reminder('r1', 2, 'DAY', '2027-01-13')],
      }),
    )
    fireEvent.click(saveButton())
    expect(await screen.findByText('Préparation enregistrée.')).toBeInTheDocument()
    expect(api.savePeriodPreparation).toHaveBeenCalledTimes(1)
    expect(api.savePeriodPreparation).toHaveBeenCalledWith('p1', 'c2', {
      announceOn: '2027-01-10',
      targetGenerationOn: '2027-01-15',
      reminders: [{ stableId: 'r1', value: 2, unit: 'DAY' }],
    })
    expect(saveButton()).toBeDisabled()
  })

  it('moves the announcement after the target first, then the target', async () => {
    await renderPanel(traumaDelta())
    changeDate(0)
    fireEvent.click(day(/25 janvier 2027, possible/))
    expect(screen.getByText('La date cible de génération doit venir après l’annonce.')).toBeInTheDocument()
    changeDate(1)
    months('Mois suivant', 1)
    fireEvent.click(day(/^vendredi 5 février 2027, possible/))
    expect(screen.queryByText(/doit venir après l’annonce/)).not.toBeInTheDocument()
    expect(saveButton()).toBeEnabled()
  })

  it('says, under the calendar, why a day touched cannot be chosen, and wipes « Préparation enregistrée »', async () => {
    await renderPanel(traumaDelta())
    api.savePeriodPreparation.mockResolvedValue(
      traumaDelta({ targetGenerationOn: '2027-01-26', reminders: [reminder('r1', 2, 'DAY', '2027-01-24')] }),
    )
    changeDate(1)
    fireEvent.click(day(/26 janvier 2027, possible/))
    fireEvent.click(saveButton())
    expect(await screen.findByText('Préparation enregistrée.')).toBeInTheDocument()

    changeDate(1)
    months('Mois précédent', 3)
    fireEvent.click(day(/^mardi 27 octobre 2026/))
    expect(screen.getByRole('alert')).toHaveTextContent('mardi 27 octobre 2026 : Ce jour est déjà passé.')
    expect(screen.queryByText('Préparation enregistrée.')).not.toBeInTheDocument()
    expect(screen.getByText(formatDate('2027-01-26'))).toBeInTheDocument()

    months('Mois suivant', 7)
    fireEvent.click(day(/^lundi 3 mai 2027/))
    expect(screen.getByRole('alert')).toHaveTextContent('Ce jour est après la fin de la période.')
  })

  it('adds, changes and removes reminders of the saved plan', async () => {
    await renderPanel(traumaDelta())
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Rappel 1 : combien avant la date cible' }), {
      target: { value: '3' },
    })
    expect(screen.getByText(`Le ${formatDate('2027-01-21')} à 8 h`)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un rappel' }))
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Rappel 2 : combien avant la date cible' }), {
      target: { value: '1' },
    })
    fireEvent.change(screen.getByRole('combobox', { name: 'Rappel 2 : unité' }), {
      target: { value: 'WEEK' },
    })
    // One week before the 24th is the 17th: the day of the announcement.
    expect(screen.getByText('Un rappel doit venir après l’annonce.')).toBeInTheDocument()
    expect(saveButton()).toBeDisabled()
    fireEvent.change(screen.getByRole('combobox', { name: 'Rappel 2 : unité' }), { target: { value: 'DAY' } })
    expect(saveButton()).toBeEnabled()

    api.savePeriodPreparation.mockResolvedValue(traumaDelta())
    fireEvent.click(saveButton())
    await waitFor(() =>
      expect(api.savePeriodPreparation).toHaveBeenLastCalledWith('p1', 'c2', {
        announceOn: '2027-01-17',
        targetGenerationOn: '2027-01-24',
        reminders: [
          { stableId: 'r1', value: 3, unit: 'DAY' },
          { value: 1, unit: 'DAY' },
        ],
      }),
    )
    await screen.findByText('Préparation enregistrée.')

    fireEvent.click(screen.getByRole('button', { name: 'Supprimer le rappel 1' }))
    expect(saveButton()).toBeEnabled()
    api.savePeriodPreparation.mockResolvedValue(traumaDelta({ reminders: [] }))
    fireEvent.click(saveButton())
    await waitFor(() =>
      expect(api.savePeriodPreparation).toHaveBeenLastCalledWith('p1', 'c2', {
        announceOn: '2027-01-17',
        targetGenerationOn: '2027-01-24',
        reminders: [],
      }),
    )
  })

  it('a value put back as it was writes nothing', async () => {
    await renderPanel(traumaDelta())
    const value = screen.getByRole('spinbutton', { name: 'Rappel 1 : combien avant la date cible' })
    fireEvent.change(value, { target: { value: '4' } })
    expect(saveButton()).toBeEnabled()
    fireEvent.change(value, { target: { value: '2' } })
    expect(saveButton()).toBeDisabled()
    expect(api.savePeriodPreparation).not.toHaveBeenCalled()
  })

  it('a failed save keeps the changes, says why, never says saved, and can be retried', async () => {
    await renderPanel(traumaDelta())
    changeDate(1)
    fireEvent.click(day(/31 janvier 2027, possible/))
    api.savePeriodPreparation.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    fireEvent.click(saveButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'La préparation n’a pas pu être enregistrée. Vérifiez votre connexion et réessayez.',
    )
    expect(screen.queryByText('Préparation enregistrée.')).not.toBeInTheDocument()
    expect(screen.getByText(formatDate('2027-01-31'))).toBeInTheDocument()
    expect(saveButton()).toBeEnabled()

    api.savePeriodPreparation.mockRejectedValueOnce(new ApiError(409, { error: 'period_locked' }, 'Conflict'))
    fireEvent.click(saveButton())
    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent(
        'Cette période est validée ou publiée : sa préparation ne change plus.',
      ),
    )

    api.savePeriodPreparation.mockResolvedValueOnce(
      traumaDelta({ targetGenerationOn: '2027-01-31', reminders: [reminder('r1', 2, 'DAY', '2027-01-29')] }),
    )
    fireEvent.click(saveButton())
    expect(await screen.findByText('Préparation enregistrée.')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('shows, after reloading, what the server saved — never the draft', async () => {
    await renderPanel(traumaDelta())
    changeDate(1)
    fireEvent.click(day(/31 janvier 2027, possible/))
    const saved = traumaDelta({
      targetGenerationOn: '2027-01-31',
      reminders: [reminder('r1', 2, 'DAY', '2027-01-29')],
    })
    api.savePeriodPreparation.mockResolvedValue(saved)
    fireEvent.click(saveButton())
    await screen.findByText('Préparation enregistrée.')
    cleanup()

    await renderPanel(saved)
    expect(screen.getByText(formatDate('2027-01-31'))).toBeInTheDocument()
    expect(screen.getByText(`Le ${formatDate('2027-01-29')} à 8 h`)).toBeInTheDocument()
    expect(saveButton()).toBeDisabled()
  })

  it('creates a preparation from nothing', async () => {
    await renderPanel(blank({ today: '2026-10-28' }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Choisir la date' })[0])
    fireEvent.click(day(/30 octobre 2026, possible/))
    fireEvent.click(screen.getByRole('button', { name: 'Choisir la date' }))
    months('Mois suivant', 1)
    fireEvent.click(day(/20 novembre 2026, possible/))
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un rappel' }))
    api.savePeriodPreparation.mockResolvedValue(blank())
    fireEvent.click(saveButton())
    await waitFor(() =>
      expect(api.savePeriodPreparation).toHaveBeenCalledWith('p1', 'c2', {
        announceOn: '2026-10-30',
        targetGenerationOn: '2026-11-20',
        reminders: [{ value: 7, unit: 'DAY' }],
      }),
    )
  })

  it('tells the collection already open apart from the announcement still planned', async () => {
    await renderPanel(traumaDelta())
    expect(screen.getByText('Ouverte')).toBeInTheDocument()
    expect(screen.getByText('0 / 17 confirmations')).toBeInTheDocument()
    expect(screen.getByText('Programmée')).toBeInTheDocument()
    expect(
      screen.getByText(
        `Déjà ouverte, avant l’annonce : le ${formatDate('2027-01-17')}, l’annonce n’ouvrira rien de plus et enverra l’invitation aux seules personnes qui n’ont pas encore confirmé.`,
      ),
    ).toBeInTheDocument()
  })

  it('says nothing of the kind when the collection is not open yet', async () => {
    await renderPanel(traumaDelta({ collection: { state: 'not_open', responded: 0, expected: 0 } }))
    expect(screen.getByText('Pas encore ouverte')).toBeInTheDocument()
    expect(screen.queryByText(/Déjà ouverte, avant l’annonce/)).not.toBeInTheDocument()
  })
})
