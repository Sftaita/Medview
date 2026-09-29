import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiUrl } from '../../lib/apiClient'
import { MyDutiesPage } from '../../pages/MyDutiesPage'
import { AuthProvider } from '../auth/AuthContext'
import { createFakeBackend } from '../../testUtils/fakeBackend'
import type { CalendarFeed } from './calendarFeed'

const EXISTING: CalendarFeed = {
  token: 'f'.repeat(64),
  createdAt: '2026-12-01T08:00:00+00:00',
  lastFetchedAt: null,
}

const feedUrl = (token: string) => apiUrl(`/api/calendar-feeds/${token}.ics`)

async function openDialog() {
  render(
    <MemoryRouter>
      <AuthProvider>
        <MyDutiesPage />
      </AuthProvider>
    </MemoryRouter>,
  )
  fireEvent.click(await screen.findByRole('button', { name: 'Ajouter à mon agenda' }))
  return screen.findByRole('dialog', { name: 'Ajouter mes gardes à mon agenda' })
}

describe('CalendarSubscriptionModal (D170)', () => {
  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('creates the address only when asked, then offers every calendar app', async () => {
    const backend = createFakeBackend()
    backend.install()
    const dialog = await openDialog()

    const create = await within(dialog).findByRole('button', { name: 'Créer mon lien d’agenda' })
    expect(backend.requests('POST', '/api/me/calendar-feed')).toHaveLength(0)
    fireEvent.click(create)

    const token = '1'.padStart(64, '0')
    const google = await within(dialog).findByRole('link', { name: 'Google Agenda' })
    expect(new URL(google.getAttribute('href')!).searchParams.get('cid')).toBe(
      feedUrl(token).replace(/^https?:\/\//, 'webcal://'),
    )
    expect(google).toHaveAttribute('target', '_blank')
    expect(within(dialog).getByRole('link', { name: 'Apple (iPhone, iPad, Mac)' })).toHaveAttribute(
      'href',
      feedUrl(token).replace(/^https?:\/\//, 'webcal://'),
    )
    expect(
      new URL(
        within(dialog).getByRole('link', { name: 'Outlook.com' }).getAttribute('href')!,
      ).searchParams.get('url'),
    ).toBe(feedUrl(token))
    expect(within(dialog).getByRole('link', { name: 'Outlook Microsoft 365' })).toBeInTheDocument()
    expect(within(dialog).getByRole('textbox')).toHaveValue(feedUrl(token))
    expect(dialog).toHaveTextContent('Aucun agenda ne s’est encore synchronisé avec ce lien.')
    expect(dialog).toHaveTextContent('ne le partagez pas')
    expect(backend.requests('POST', '/api/me/calendar-feed')).toHaveLength(1)
  })

  it('shows an existing address right away, with its last synchronisation', async () => {
    createFakeBackend({ calendarFeed: { ...EXISTING, lastFetchedAt: '2026-12-10T08:00:00+00:00' } }).install()
    const dialog = await openDialog()

    expect(await within(dialog).findByRole('textbox')).toHaveValue(feedUrl(EXISTING.token))
    expect(dialog).toHaveTextContent('Dernière synchronisation par un agenda : 10 décembre')
    expect(within(dialog).queryByRole('button', { name: 'Créer mon lien d’agenda' })).not.toBeInTheDocument()
  })

  it('creates a single address however fast the button is clicked', async () => {
    const backend = createFakeBackend()
    backend.install()
    const release = backend.hold('POST', '/api/me/calendar-feed')
    const dialog = await openDialog()

    const create = await within(dialog).findByRole('button', { name: 'Créer mon lien d’agenda' })
    fireEvent.click(create)
    fireEvent.click(create)
    expect(await within(dialog).findByRole('button', { name: 'Création…' })).toBeDisabled()
    release()

    await within(dialog).findByRole('textbox')
    expect(backend.requests('POST', '/api/me/calendar-feed')).toHaveLength(1)
  })

  it('copies the address', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } })
    createFakeBackend({ calendarFeed: EXISTING }).install()
    const dialog = await openDialog()

    fireEvent.click(await within(dialog).findByRole('button', { name: 'Copier' }))

    expect(await within(dialog).findByRole('button', { name: 'Copié' })).toBeInTheDocument()
    expect(writeText).toHaveBeenCalledWith(feedUrl(EXISTING.token))
  })

  it('explains how to copy by hand when the clipboard is refused', async () => {
    vi.stubGlobal('navigator', {
      ...navigator,
      clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
    })
    createFakeBackend({ calendarFeed: EXISTING }).install()
    const dialog = await openDialog()

    fireEvent.click(await within(dialog).findByRole('button', { name: 'Copier' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('copiez-le manuellement')
  })

  it('regenerates the address only after a confirmation', async () => {
    const backend = createFakeBackend({ calendarFeed: EXISTING })
    backend.install()
    const dialog = await openDialog()
    await within(dialog).findByRole('textbox')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Générer un nouveau lien' }))
    const confirmation = within(dialog).getByRole('group', { name: 'Confirmation' })
    expect(confirmation).toHaveTextContent('L’ancien lien cessera de fonctionner')
    expect(backend.requests('POST', '/api/me/calendar-feed/regenerate')).toHaveLength(0)

    fireEvent.click(within(confirmation).getByRole('button', { name: 'Générer un nouveau lien' }))

    await waitFor(() =>
      expect(within(dialog).getByRole('textbox')).toHaveValue(feedUrl('1'.padStart(64, '0'))),
    )
    expect(backend.requests('POST', '/api/me/calendar-feed/regenerate')).toHaveLength(1)
    expect(within(dialog).queryByRole('group', { name: 'Confirmation' })).not.toBeInTheDocument()
  })

  it('cancelling a confirmation changes nothing', async () => {
    const backend = createFakeBackend({ calendarFeed: EXISTING })
    backend.install()
    const dialog = await openDialog()
    await within(dialog).findByRole('textbox')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Désactiver le lien' }))
    fireEvent.click(
      within(within(dialog).getByRole('group', { name: 'Confirmation' })).getByRole('button', {
        name: 'Annuler',
      }),
    )

    expect(within(dialog).getByRole('textbox')).toHaveValue(feedUrl(EXISTING.token))
    expect(backend.requests('DELETE', '/api/me/calendar-feed')).toHaveLength(0)
  })

  it('disables the address after a confirmation', async () => {
    const backend = createFakeBackend({ calendarFeed: EXISTING })
    backend.install()
    const dialog = await openDialog()
    await within(dialog).findByRole('textbox')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Désactiver le lien' }))
    const confirmation = within(dialog).getByRole('group', { name: 'Confirmation' })
    expect(confirmation).toHaveTextContent('ne recevront plus vos gardes')
    fireEvent.click(within(confirmation).getByRole('button', { name: 'Désactiver' }))

    expect(await within(dialog).findByRole('button', { name: 'Créer mon lien d’agenda' })).toBeInTheDocument()
    expect(within(dialog).queryByRole('textbox')).not.toBeInTheDocument()
    expect(backend.requests('DELETE', '/api/me/calendar-feed')).toHaveLength(1)
  })

  it('reports a failed load', async () => {
    const backend = createFakeBackend()
    backend.install()
    backend.failNext('GET', '/api/me/calendar-feed', 500)
    const dialog = await openDialog()

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Impossible de charger votre lien d’agenda.',
    )
    expect(within(dialog).queryByRole('button', { name: 'Créer mon lien d’agenda' })).not.toBeInTheDocument()
  })

  it('reports a failed creation and lets the person retry', async () => {
    const backend = createFakeBackend()
    backend.install()
    backend.failNext('POST', '/api/me/calendar-feed', 500)
    const dialog = await openDialog()

    fireEvent.click(await within(dialog).findByRole('button', { name: 'Créer mon lien d’agenda' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Impossible de créer le lien.')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer mon lien d’agenda' }))
    expect(await within(dialog).findByRole('textbox')).toBeInTheDocument()
    expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument()
  })

  it('closes', async () => {
    createFakeBackend().install()
    const dialog = await openDialog()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Fermer' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
