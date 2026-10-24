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
    // The target date: not on or before the announcement.
    fireEvent.click(screen.getAllByRole('button', { name: 'Choisir la date' })[0])
    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))
    expect(
      day(/2 novembre 2026, impossible : La date cible de génération vient après l’annonce\./),
    ).toBeInTheDocument()
    fireEvent.click(day(/30 novembre 2026, possible/))
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
