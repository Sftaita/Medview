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
    declarationDeadlineOn: null,
    deadlineOn: null,
    generatedAt: null,
    collection: { state: 'not_open', participants: 0 },
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
    // Neither the indicative deadline nor the target date.
    expect(screen.getAllByText('Non définie')).toHaveLength(2)
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

    // No target date yet: no reminder can be placed, and the panel says why.
    expect(screen.getByRole('button', { name: 'Ajouter un rappel' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Ajouter un rappel' })).toHaveAccessibleDescription(
      'Choisissez d’abord la date cible de génération : les rappels se placent avant elle.',
    )
    // The target date (after the deadline's picker): not on or before the announcement.
    fireEvent.click(screen.getAllByRole('button', { name: 'Choisir la date' })[1])
    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))
    expect(
      day(/2 novembre 2026, impossible : La date cible de génération vient après l’annonce\./),
    ).toBeInTheDocument()
    fireEvent.click(day(/30 novembre 2026, possible/))
    expect(screen.getByText(formatDate('2026-11-30'))).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ajouter un rappel' })).toBeEnabled()

    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un rappel' }))
    expect(screen.getByText(`Le ${formatDate('2026-11-23')} à 8 h`)).toBeInTheDocument()
    // The next one is never proposed on a day already taken: 6 days before.
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un rappel' }))
    expect(screen.getByRole('spinbutton', { name: 'Rappel 2 : combien avant la date cible' })).toHaveValue(6)
    expect(screen.getByText(`Le ${formatDate('2026-11-24')} à 8 h`)).toBeInTheDocument()
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Rappel 2 : combien avant la date cible' }), {
      target: { value: '7' },
    })
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
      declarationDeadlineOn: null,
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
        declarationDeadlineOn: null,
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
        collection: { state: 'open', participants: 9 },
        reminders: [
          {
            stableId: 'r1',
            value: 2,
            unit: 'WEEK',
            dueOn: '2026-12-01',
            state: 'executed',
            outcome: 'sent_to_participants',
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
    expect(screen.getByText('9 participants')).toBeInTheDocument()
    expect(screen.queryByText(/confirmations?\b/)).not.toBeInTheDocument()
    const history = screen.getByRole('list', { name: 'Rappels passés' })
    expect(within(history).getByText('Exécuté')).toBeInTheDocument()
    expect(within(history).getByText(/envoyé à tous les participants · 4 emails envoyés/)).toBeInTheDocument()
    expect(within(history).getByText('Annulé')).toBeInTheDocument()
    expect(within(history).getByText('la génération a été lancée')).toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: /Choisir la date|Changer la date|Ajouter un rappel|Enregistrer/ }),
    ).not.toBeInTheDocument()
  })

  it('plans an indicative deadline between the announcement and the target, and says why one is refused (D214)', async () => {
    await renderPanel(
      blank({
        announcement: {
          on: '2026-11-02',
          state: 'scheduled',
          outcome: null,
          executedAt: null,
          emails: NO_EMAILS,
        },
        targetGenerationOn: '2026-11-30',
      }),
    )

    expect(screen.getByText('Date limite indicative de déclaration')).toBeInTheDocument()
    expect(screen.getByText(/Jamais bloquante/)).toBeInTheDocument()
    // The deadline's picker (the announcement's reads "Changer la date"), opened on today's month.
    fireEvent.click(screen.getAllByRole('button', { name: 'Choisir la date' })[0])
    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }))
    // On the announcement day itself: refused, with the reason.
    expect(day(/2 novembre 2026, impossible/)).toBeInTheDocument()
    fireEvent.click(day(/20 novembre 2026, possible/))
    expect(screen.getByText(formatDate('2026-11-20'))).toBeInTheDocument()

    api.savePeriodPreparation.mockResolvedValue(
      blank({
        declarationDeadlineOn: '2026-11-20',
        deadlineOn: '2026-11-20',
        targetGenerationOn: '2026-11-30',
      }),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la préparation' }))
    await waitFor(() =>
      expect(api.savePeriodPreparation).toHaveBeenCalledWith('p1', 'c2', {
        announceOn: '2026-11-02',
        declarationDeadlineOn: '2026-11-20',
        targetGenerationOn: '2026-11-30',
        reminders: [],
      }),
    )
  })

  it('refuses a deadline after the target date, and says it next to "Enregistrer"', async () => {
    await renderPanel(
      blank({
        targetGenerationOn: '2026-11-30',
        declarationDeadlineOn: '2026-11-25',
        deadlineOn: '2026-11-25',
      }),
    )

    // The target moves before the deadline: the deadline is now wrong.
    fireEvent.click(screen.getAllByRole('button', { name: 'Changer la date' })[1])
    fireEvent.click(day(/20 novembre 2026, possible/))

    const message = 'La date limite indicative doit venir au plus tard à la date cible de génération.'
    expect(screen.getAllByText(message, { exact: false }).length).toBeGreaterThanOrEqual(2)
    expect(screen.getByRole('button', { name: 'Enregistrer la préparation' })).toBeDisabled()
  })

  it('never says "Ouverte" while the announcement is still scheduled (Trauma Delta, D214)', async () => {
    await renderPanel(
      blank({
        announcement: {
          on: '2027-01-17',
          state: 'scheduled',
          outcome: null,
          executedAt: null,
          emails: NO_EMAILS,
        },
        targetGenerationOn: '2027-01-24',
        collection: { state: 'open', participants: 17 },
      }),
    )

    expect(screen.getByText('Pas encore annoncée')).toBeInTheDocument()
    expect(
      screen.getByText(`Les participants seront prévenus le ${formatDate('2027-01-17')}.`),
    ).toBeInTheDocument()
    expect(screen.queryByText('Ouverte')).not.toBeInTheDocument()
    expect(screen.getByText(/Personne n’a rien à confirmer/)).toBeInTheDocument()
  })

  it('shows the real generation date next to the target date, distinctly', async () => {
    await renderPanel(
      blank({
        editable: false,
        blockers: ['period_generation_started'],
        targetGenerationOn: '2027-01-24',
        generatedAt: '2027-01-25T09:15:00+00:00',
      }),
    )

    expect(screen.getByText('Date cible de génération')).toBeInTheDocument()
    expect(screen.getByText(formatDate('2027-01-24'))).toBeInTheDocument()
    expect(screen.getByText(`Générée le ${formatDate('2027-01-25')}`)).toBeInTheDocument()
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
 * The real case (production, Trauma Delta period 2, saved on 28 October 2026):
 * announcement 17 January 2027, target 24 January, a reminder 2 days before.
 * "Ajouter un rappel" used to propose 7 days — the announcement day itself —
 * and greyed "Enregistrer" with only a small line under the reminder.
 */
function trauma(overrides: Partial<PeriodPreparation> = {}): PeriodPreparation {
  return blank({
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
    reminders: [reminder('r1', 2, 'DAY', '2027-01-22')],
    ...overrides,
  })
}

function reminder(
  stableId: string,
  value: number,
  unit: 'DAY' | 'WEEK',
  dueOn: string,
): PeriodPreparation['reminders'][number] {
  return {
    stableId,
    value,
    unit,
    dueOn,
    state: 'scheduled',
    outcome: null,
    executedAt: null,
    emails: NO_EMAILS,
  }
}

const saveButton = () => screen.getByRole('button', { name: 'Enregistrer la préparation' })
const addButton = () => screen.getByRole('button', { name: 'Ajouter un rappel' })
const valueOf = (n: number) =>
  screen.getByRole('spinbutton', { name: `Rappel ${n} : combien avant la date cible` })
const unitOf = (n: number) => screen.getByRole('combobox', { name: `Rappel ${n} : unité` })

describe('PeriodPreparationPanel — adding a reminder (Trauma Delta, 17 → 24 January 2027)', () => {
  it('proposes the first free day after the announcement, shows its date, saves it and finds it again on reload', async () => {
    await renderPanel(trauma())

    expect(screen.getByText('Aucune modification à enregistrer.')).toBeInTheDocument()
    expect(saveButton()).toBeDisabled()

    fireEvent.click(addButton())
    // Never 7 days (17 January, the announcement day) nor 2 days (taken): 6 days, the 18th.
    expect(valueOf(2)).toHaveValue(6)
    expect(screen.getByText(`Le ${formatDate('2027-01-18')} à 8 h`)).toBeInTheDocument()
    expect(screen.queryByText(/Enregistrement impossible/)).not.toBeInTheDocument()
    expect(saveButton()).toBeEnabled()

    const saved = trauma({
      reminders: [reminder('r1', 2, 'DAY', '2027-01-22'), reminder('r2', 6, 'DAY', '2027-01-18')],
    })
    api.savePeriodPreparation.mockResolvedValue(saved)
    fireEvent.click(saveButton())

    expect(await screen.findByText('Préparation enregistrée.')).toBeInTheDocument()
    expect(api.savePeriodPreparation).toHaveBeenCalledWith('p1', 'c2', {
      announceOn: '2027-01-17',
      declarationDeadlineOn: null,
      targetGenerationOn: '2027-01-24',
      reminders: [
        { stableId: 'r1', value: 2, unit: 'DAY' },
        { value: 6, unit: 'DAY' },
      ],
    })

    // Reload: the panel reads the plan again from the server, as saved.
    cleanup()
    await renderPanel(saved)
    expect(valueOf(1)).toHaveValue(2)
    expect(valueOf(2)).toHaveValue(6)
    expect(screen.getByText(`Le ${formatDate('2027-01-22')} à 8 h`)).toBeInTheDocument()
    expect(screen.getByText(`Le ${formatDate('2027-01-18')} à 8 h`)).toBeInTheDocument()
    expect(screen.getByText('Aucune modification à enregistrer.')).toBeInTheDocument()
  })

  it('says next to "Enregistrer" why a reminder on the announcement day cannot be saved, and keeps its own error', async () => {
    await renderPanel(trauma())
    fireEvent.click(addButton())
    fireEvent.change(valueOf(2), { target: { value: '7' } })

    // The day it would fall on stays visible, with the precise error under the field.
    expect(screen.getByText(`Le ${formatDate('2027-01-17')} à 8 h`)).toBeInTheDocument()
    const fieldError = `Un rappel doit venir après l’annonce. L’annonce est le ${formatDate('2027-01-17')}.`
    expect(screen.getByText(fieldError)).toBeInTheDocument()
    expect(valueOf(2)).toHaveAttribute('aria-invalid', 'true')

    // Invalid is not "nothing to save": the footer says what to fix, and the button says why it is off.
    expect(screen.queryByText('Aucune modification à enregistrer.')).not.toBeInTheDocument()
    expect(saveButton()).toBeDisabled()
    expect(saveButton()).toHaveAccessibleDescription(
      new RegExp(`Enregistrement impossible.*Rappel 2 \\(le ${formatDate('2027-01-17')}\\)`),
    )

    // One week before is the same day: refused as well.
    fireEvent.change(valueOf(2), { target: { value: '1' } })
    fireEvent.change(unitOf(2), { target: { value: 'WEEK' } })
    expect(screen.getByText(fieldError)).toBeInTheDocument()

    fireEvent.change(unitOf(2), { target: { value: 'DAY' } })
    fireEvent.change(valueOf(2), { target: { value: '3' } })
    expect(screen.getByText(`Le ${formatDate('2027-01-21')} à 8 h`)).toBeInTheDocument()
    expect(screen.queryByText(/Enregistrement impossible/)).not.toBeInTheDocument()
    expect(saveButton()).toBeEnabled()
  })

  it('flags two reminders on the same day, in days or in weeks', async () => {
    await renderPanel(
      trauma({
        announcement: {
          on: '2027-01-03',
          state: 'scheduled',
          outcome: null,
          executedAt: null,
          emails: NO_EMAILS,
        },
        reminders: [reminder('r1', 7, 'DAY', '2027-01-17')],
      }),
    )
    fireEvent.click(addButton())
    fireEvent.change(valueOf(2), { target: { value: '1' } })
    fireEvent.change(unitOf(2), { target: { value: 'WEEK' } })

    expect(screen.getAllByText('Deux rappels tombent le même jour.')).toHaveLength(2)
    expect(screen.getByText(/Enregistrement impossible/)).toBeInTheDocument()
    expect(saveButton()).toBeDisabled()
  })

  it('moves every reminder with the target date, and re-checks it against the announcement', async () => {
    await renderPanel(trauma())

    // Target 19 January: 2 days before is the 17th, the announcement day.
    fireEvent.click(screen.getAllByRole('button', { name: 'Changer la date' })[1])
    fireEvent.click(day(/19 janvier 2027, possible/))
    expect(screen.getByText(`Le ${formatDate('2027-01-17')} à 8 h`)).toBeInTheDocument()
    // Under the field and in the footer, next to the button.
    expect(screen.getAllByText(/Un rappel doit venir après l’annonce\./)).toHaveLength(2)
    expect(saveButton()).toBeDisabled()

    // An earlier announcement frees the day.
    fireEvent.click(screen.getAllByRole('button', { name: 'Changer la date' })[0])
    fireEvent.click(day(/10 janvier 2027, possible/))
    expect(screen.queryAllByText(/Un rappel doit venir après l’annonce\./)).toHaveLength(0)
    expect(saveButton()).toBeEnabled()
  })

  it('disables "Ajouter un rappel" and says why when no day is left', async () => {
    await renderPanel(
      trauma({
        announcement: {
          on: '2027-01-21',
          state: 'scheduled',
          outcome: null,
          executedAt: null,
          emails: NO_EMAILS,
        },
        reminders: [reminder('r1', 1, 'DAY', '2027-01-23'), reminder('r2', 2, 'DAY', '2027-01-22')],
      }),
    )

    expect(addButton()).toBeDisabled()
    expect(addButton()).toHaveAccessibleDescription(
      `Aucun jour libre pour un nouveau rappel : il doit tomber après l’annonce (${formatDate('2027-01-21')}) et avant la date cible (${formatDate('2027-01-24')}), sans tomber le même jour qu’un autre rappel.`,
    )

    // Removing one frees its day again.
    fireEvent.click(screen.getByRole('button', { name: 'Supprimer le rappel 2' }))
    expect(addButton()).toBeEnabled()
    fireEvent.click(addButton())
    expect(valueOf(2)).toHaveValue(2)
  })

  it('keeps the draft and says why when the server refuses or cannot be reached', async () => {
    await renderPanel(trauma())
    fireEvent.click(addButton())

    api.savePeriodPreparation.mockRejectedValueOnce(
      new ApiError(409, { error: 'period_generation_started' }, 'Conflict'),
    )
    fireEvent.click(saveButton())
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'La génération de cette période a été lancée : sa préparation ne change plus.',
    )
    expect(valueOf(2)).toHaveValue(6)

    api.savePeriodPreparation.mockRejectedValueOnce(new Error('offline'))
    fireEvent.click(saveButton())
    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent(
        'La préparation n’a pas pu être enregistrée. Vérifiez votre connexion et réessayez.',
      ),
    )
    expect(valueOf(2)).toHaveValue(6)
    expect(saveButton()).toBeEnabled()
  })
})
