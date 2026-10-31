import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makeDetail, makeRow, makeStatus } from '../../../testUtils/pilotFixtures'
import { status as httpStatus, stubApi } from '../../../testUtils/stubApi'
import { CollectionStatusPanel } from './CollectionStatusPanel'
import type { PilotMemberRow } from './types'

beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

const STATUS = makeStatus()

function renderPanel(overrides = {}, onChanged = vi.fn()) {
  render(<CollectionStatusPanel status={makeStatus(overrides)} onChanged={onChanged} />)
  return onChanged
}

/** The row of the members table for a last name. */
const rowOf = (lastName: string) => screen.getByRole('button', { name: new RegExp(lastName) }).closest('li')!

const EMPTY_HISTORY = () => ({
  period: { stableId: 'c1', number: 1 },
  timezone: 'Europe/Brussels',
  member: null,
  entries: [],
})

function detailRoutes(members: PilotMemberRow[], extra: Record<string, () => unknown> = {}) {
  // The drawer reads the member's history of the period (D217).
  const routes: Record<string, () => unknown> = {
    'GET /api/plannings/plan-1/history': EMPTY_HISTORY,
    ...extra,
  }
  for (const member of members) {
    routes[`GET /api/plannings/plan-1/members/${member.memberStableId}/availability-status`] = () =>
      makeDetail(member)
  }
  return routes
}

describe('CollectionStatusPanel — summary and list', () => {
  it('says how many take part and what they declared, never a count of confirmations (D214)', () => {
    renderPanel()

    expect(screen.getByText('3 participants')).toBeInTheDocument()
    expect(screen.getByText('6 périodes d’indisponibilité déclarées sur cette période.')).toBeInTheDocument()
    expect(
      screen.getByText(/Sans indisponibilité renseignée, un participant est considéré comme disponible/),
    ).toBeInTheDocument()
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument()
    expect(screen.queryByText(/confirmé|en attente/i)).not.toBeInTheDocument()
  })

  it('lists each member with the unavailability count, the last change and the last reminder — no status', () => {
    renderPanel()

    const dupont = within(rowOf('Dupont'))
    expect(dupont.getByText('4')).toBeInTheDocument()
    expect(dupont.getByText('21/09/2026')).toBeInTheDocument() // calendar changed
    expect(dupont.queryByText('Confirmé')).not.toBeInTheDocument()

    const martin = within(rowOf('Martin'))
    expect(martin.getByText('2')).toBeInTheDocument()
    expect(martin.getByText('20/09/2026')).toBeInTheDocument()

    // No unavailability recorded: simply available, nothing pending.
    const leroy = within(rowOf('Leroy'))
    expect(leroy.queryByText('En attente')).not.toBeInTheDocument()
    expect(leroy.getByText('0')).toBeInTheDocument()
    expect(leroy.getByText('18/09/2026')).toBeInTheDocument()
  })

  it('marks someone no longer concerned (left before the period)', () => {
    renderPanel({ members: [makeRow('1', 'Dupont', { participant: false })] })

    expect(within(rowOf('Dupont')).getByText('Non concerné')).toBeInTheDocument()
  })
})

describe('CollectionStatusPanel — search and sort', () => {
  const names = () => screen.getAllByRole('button', { name: /Camille / }).map((button) => button.textContent)

  it('sorts by last name by default, and by number of unavailabilities on demand', () => {
    renderPanel()

    expect(names()).toEqual(['Camille Dupont', 'Camille Leroy', 'Camille Martin'])
    expect(screen.getByRole('button', { name: 'Nom' })).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByRole('button', { name: 'Indisponibilités' }))
    expect(screen.getByRole('button', { name: 'Indisponibilités' })).toHaveAttribute('aria-pressed', 'true')
    expect(names()).toEqual(['Camille Dupont', 'Camille Martin', 'Camille Leroy'])
  })

  it('filters members by name, case-insensitively, and says when nobody matches', () => {
    renderPanel()

    fireEvent.change(screen.getByRole('textbox', { name: 'Rechercher un membre' }), {
      target: { value: 'mar' },
    })
    expect(names()).toEqual(['Camille Martin'])

    fireEvent.change(screen.getByRole('textbox', { name: 'Rechercher un membre' }), {
      target: { value: 'zzz' },
    })
    expect(screen.getByText('Aucun membre ne correspond à « zzz ».')).toBeInTheDocument()
  })

  it('offers to set the informative deadline only when the page can open the settings', () => {
    const onEditDeadline = vi.fn()
    const { unmount } = render(
      <CollectionStatusPanel status={makeStatus()} onChanged={vi.fn()} onEditDeadline={onEditDeadline} />,
    )
    fireEvent.click(screen.getByRole('button', { name: 'Fixer une date limite indicative' }))
    expect(onEditDeadline).toHaveBeenCalledTimes(1)
    unmount()

    render(
      <CollectionStatusPanel
        status={makeStatus({ availabilityDeadline: '2026-09-25' })}
        onChanged={vi.fn()}
        onEditDeadline={onEditDeadline}
      />,
    )
    expect(screen.getByRole('button', { name: 'Modifier la date limite indicative' })).toBeInTheDocument()
    cleanup()

    renderPanel()
    expect(screen.queryByRole('button', { name: /date limite indicative/ })).not.toBeInTheDocument()
  })
})

describe('CollectionStatusPanel — the deadline is only a warning', () => {
  it('shows the informative deadline, and no warning while it is not passed', () => {
    renderPanel({ availabilityDeadline: '2026-09-25', deadlineOverdueDays: null })

    expect(screen.getByText('Date limite indicative : 25 septembre 2026')).toBeInTheDocument()
    expect(screen.queryByText(/dépassée/)).not.toBeInTheDocument()
  })

  it('warns "dépassée depuis 2 jours" but disables nothing', () => {
    renderPanel({ availabilityDeadline: '2026-09-25', deadlineOverdueDays: 2 })

    expect(screen.getByText(/Date limite indicative dépassée depuis 2 jours\./)).toBeInTheDocument()
    expect(screen.getByText(/Cette date reste indicative/)).toBeInTheDocument()
    // Every action of the panel is still available.
    expect(screen.getByRole('button', { name: 'Rappeler les participants' })).toBeEnabled()
    for (const button of screen.getAllByRole('button')) {
      expect(button).toBeEnabled()
    }
  })
})

describe('CollectionStatusPanel — member drawer', () => {
  it('opens on a click, shows identity, participation and the period unavailabilities, and closes', async () => {
    stubApi(detailRoutes(STATUS.members))
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: /Dupont/ }))

    const drawer = await screen.findByRole('dialog', { name: 'Camille Dupont' })
    await within(drawer).findByText('Indisponibilités (3)')
    expect(within(drawer).getByText('Membre')).toBeInTheDocument() // role
    expect(within(drawer).getByText('Seniors')).toBeInTheDocument() // team
    expect(
      within(drawer).getByText('Participe à la période : reçoit l’annonce et les rappels.'),
    ).toBeInTheDocument()
    expect(within(drawer).getByText(/Calendrier modifié le 21\/09\/2026 à 10:14/)).toBeInTheDocument()
    expect(within(drawer).queryByText(/confirmation reçue|Dernière confirmation/)).not.toBeInTheDocument()
    expect(within(drawer).getByText('3–7 janvier')).toBeInTheDocument()
    expect(within(drawer).getByText('21 janvier')).toBeInTheDocument()
    expect(within(drawer).getByText('14 février 08:00 → 15 février 12:00')).toBeInTheDocument()

    fireEvent.click(within(drawer).getByRole('button', { name: 'Fermer le panneau' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('closes with Escape and on a click on the backdrop', async () => {
    stubApi(detailRoutes(STATUS.members))
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: /Dupont/ }))
    await screen.findByRole('dialog')
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Dupont/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.mouseDown(dialog.parentElement!)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('shows a member without any unavailability as available, with nothing to confirm', async () => {
    const leroy = STATUS.members[2]
    stubApi({
      ...detailRoutes(STATUS.members),
      [`GET /api/plannings/plan-1/members/${leroy.memberStableId}/availability-status`]: () =>
        makeDetail(leroy, { unavailabilities: [] }),
    })
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: /Leroy/ }))

    const drawer = await screen.findByRole('dialog', { name: 'Camille Leroy' })
    await within(drawer).findByText('Aucune indisponibilité enregistrée sur cette période.')
    expect(within(drawer).getByText(/considéré comme disponible\. Rien à confirmer\./)).toBeInTheDocument()
    expect(within(drawer).queryByText('En attente')).not.toBeInTheDocument()
    expect(within(drawer).getByText('Dernier rappel : 18/09/2026 à 11:00')).toBeInTheDocument()
  })

  it('keeps preferences in their own section, apart from the unavailabilities', async () => {
    const martin = STATUS.members[1]
    stubApi({
      ...detailRoutes(STATUS.members),
      [`GET /api/plannings/plan-1/members/${martin.memberStableId}/availability-status`]: () =>
        makeDetail(martin, {
          unavailabilities: [makeDetail(martin).unavailabilities[0]],
          preferences: [
            {
              stableId: 'pref',
              type: 'PREFER_DUTY',
              startsAt: '2027-03-01T23:00:00+00:00',
              endsAt: '2027-03-03T23:00:00+00:00',
            },
          ],
        }),
    })
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: /Martin/ }))

    const drawer = await screen.findByRole('dialog')
    await within(drawer).findByText('Indisponibilités (1)')
    const preferences = within(drawer).getByRole('region', { name: 'Préférences de garde' })
    expect(within(preferences).getByText('2–3 mars')).toBeInTheDocument()
    const unavailabilities = within(drawer).getByRole('region', { name: /Indisponibilités/ })
    expect(within(unavailabilities).queryByText('2–3 mars')).not.toBeInTheDocument()
  })

  it('has no reminder button for someone no longer concerned', async () => {
    const gone = makeRow('9', 'Dupont', { participant: false })
    stubApi(detailRoutes([gone]))
    renderPanel({ members: [gone] })

    fireEvent.click(screen.getByRole('button', { name: /Dupont/ }))

    const drawer = await screen.findByRole('dialog')
    await within(drawer).findByText('Indisponibilités (3)')
    expect(within(drawer).getByText('Aucun rappel envoyé.')).toBeInTheDocument()
    expect(within(drawer).queryByRole('button', { name: /Envoyer un rappel/ })).not.toBeInTheDocument()
  })
})

describe('CollectionStatusPanel — individual reminder', () => {
  const martin = STATUS.members[1]
  const detailUrl = `GET /api/plannings/plan-1/members/${martin.memberStableId}/availability-status`
  const remindUrl = `POST /api/plannings/plan-1/members/${martin.memberStableId}/reminders`

  it('sends one reminder, shows it as the last one, and refreshes the list', async () => {
    let reminded = false
    const api = stubApi({
      [detailUrl]: () =>
        makeDetail(martin, {
          member: {
            ...martin,
            lastReminderAt: reminded ? '2026-09-21T08:14:00+00:00' : martin.lastReminderAt,
          },
          reminders: reminded
            ? [
                {
                  stableId: 'r2',
                  sentAt: '2026-09-21T08:14:00+00:00',
                  sentByName: 'Alice Martin',
                  channel: 'EMAIL',
                  bulk: false,
                },
              ]
            : [],
        }),
      [remindUrl]: () => {
        reminded = true
        return {
          stableId: 'r2',
          userStableId: 'u-2',
          sentAt: '2026-09-21T08:14:00+00:00',
          lastReminderAt: '2026-09-21T08:14:00+00:00',
          bulk: false,
        }
      },
    })
    const onChanged = renderPanel()

    fireEvent.click(screen.getByRole('button', { name: /Martin/ }))
    const drawer = await screen.findByRole('dialog')
    expect(await within(drawer).findByText('Dernier rappel : 20/09/2026 à 11:00')).toBeInTheDocument()

    fireEvent.click(within(drawer).getByRole('button', { name: 'Envoyer un rappel' }))

    expect(await within(drawer).findByText('Rappel envoyé.')).toBeInTheDocument()
    expect(await within(drawer).findByText('Dernier rappel : 21/09/2026 à 10:14')).toBeInTheDocument()
    expect(
      api.requests('POST', `/api/plannings/plan-1/members/${martin.memberStableId}/reminders`),
    ).toHaveLength(1)
    expect(onChanged).toHaveBeenCalledTimes(1)
    // The history is there, append-only.
    fireEvent.click(within(drawer).getByText('Historique des rappels (1)'))
    expect(within(drawer).getByText(/21\/09\/2026 à 10:14 — par Alice Martin/)).toBeInTheDocument()
  })

  it('shows a loading state and ignores a second click while the first is in flight', async () => {
    let release: () => void = () => {}
    const held = new Promise<void>((resolve) => (release = resolve))
    const api = stubApi({
      [detailUrl]: () => makeDetail(martin),
      [remindUrl]: async () => {
        await held
        return {
          stableId: 'r2',
          userStableId: 'u-2',
          sentAt: '2026-09-21T08:14:00+00:00',
          lastReminderAt: '2026-09-21T08:14:00+00:00',
          bulk: false,
        }
      },
    })
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: /Martin/ }))
    const drawer = await screen.findByRole('dialog')
    const button = await within(drawer).findByRole('button', { name: 'Envoyer un rappel' })

    // A real double click: two clicks before React re-renders.
    fireEvent.click(button)
    fireEvent.click(button)

    const busy = await within(drawer).findByRole('button', { name: 'Envoi en cours…' })
    expect(busy).toBeDisabled()
    expect(busy).toHaveAttribute('aria-busy', 'true')
    expect(
      api.requests('POST', `/api/plannings/plan-1/members/${martin.memberStableId}/reminders`),
    ).toHaveLength(1)

    release()
    await waitFor(() =>
      expect(within(drawer).getByRole('button', { name: 'Envoyer un rappel' })).toBeEnabled(),
    )
    expect(
      api.requests('POST', `/api/plannings/plan-1/members/${martin.memberStableId}/reminders`),
    ).toHaveLength(1)
  })

  it('explains a refusal instead of failing silently', async () => {
    stubApi({
      [detailUrl]: () => makeDetail(martin),
      [remindUrl]: () => httpStatus(409, { error: 'reminder_recently_sent' }),
    })
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: /Martin/ }))
    const drawer = await screen.findByRole('dialog')
    fireEvent.click(await within(drawer).findByRole('button', { name: 'Envoyer un rappel' }))

    expect(await within(drawer).findByRole('alert')).toHaveTextContent(/vient d’être envoyé/)
    expect(within(drawer).getByRole('button', { name: 'Envoyer un rappel' })).toBeEnabled()
  })

  it('says nothing was recorded when the email could not be sent', async () => {
    stubApi({
      [detailUrl]: () => makeDetail(martin),
      [remindUrl]: () => httpStatus(502, { error: 'email_not_sent' }),
    })
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: /Martin/ }))
    const drawer = await screen.findByRole('dialog')
    fireEvent.click(await within(drawer).findByRole('button', { name: 'Envoyer un rappel' }))

    expect(await within(drawer).findByRole('alert')).toHaveTextContent(/Aucun rappel n’a été enregistré/)
  })
})

describe('CollectionStatusPanel — remind every participant (D214)', () => {
  const bulkUrl = 'POST /api/plannings/plan-1/reminders/participants'

  it('asks for a confirmation, then sends once even on a double click', async () => {
    const api = stubApi({
      [bulkUrl]: () => ({
        targetedCount: 2,
        sentCount: 2,
        skippedRecentlyCount: 0,
        failedCount: 0,
        sent: [],
      }),
    })
    const onChanged = renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Rappeler les participants' }))
    expect(api.requests('POST', '/api/plannings/plan-1/reminders/participants')).toHaveLength(0)
    expect(screen.getByText('Envoyer un rappel par email à 3 participants ?')).toBeInTheDocument()

    const send = screen.getByRole('button', { name: 'Envoyer les rappels' })
    fireEvent.click(send)
    fireEvent.click(send)

    expect(await screen.findByText('2 rappels envoyés.')).toBeInTheDocument()
    expect(api.requests('POST', '/api/plannings/plan-1/reminders/participants')).toHaveLength(1)
    expect(onChanged).toHaveBeenCalledTimes(1)
  })

  it('reports people skipped because they were reminded a moment ago', async () => {
    stubApi({
      [bulkUrl]: () => ({
        targetedCount: 2,
        sentCount: 1,
        skippedRecentlyCount: 1,
        failedCount: 0,
        sent: [],
      }),
    })
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Rappeler les participants' }))
    fireEvent.click(screen.getByRole('button', { name: 'Envoyer les rappels' }))

    expect(
      await screen.findByText('1 rappel envoyé · 1 personne déjà relancée à l’instant.'),
    ).toBeInTheDocument()
  })

  it('can be cancelled without sending anything', () => {
    const api = stubApi({})
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Rappeler les participants' }))
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))

    expect(screen.getByRole('button', { name: 'Rappeler les participants' })).toBeInTheDocument()
    expect(api.calls).toHaveLength(0)
  })
})
