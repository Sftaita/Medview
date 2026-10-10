import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createFakeBackend } from '../../testUtils/fakeBackend'
import { SurgicalHubAccountSection } from './SurgicalHubAccountSection'
import type { SurgicalHubLink } from './types'

const ACTIVE: SurgicalHubLink = {
  status: 'ACTIVE',
  surgicalHubName: 'Dr Alice Martin',
  linkedByAdministrator: true,
  linkedAt: '2026-10-01T08:00:00+00:00',
  revokedAt: null,
  lastSyncAttemptAt: '2026-10-09T08:00:00+00:00',
  lastSuccessfulSyncAt: '2026-10-09T08:00:00+00:00',
  lastSyncError: null,
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('SurgicalHubAccountSection (docs/surgicalhub-integration.md §4, §10)', () => {
  it('generates a short-lived code and says where to type it', async () => {
    const backend = createFakeBackend()
    backend.install()
    render(<SurgicalHubAccountSection />)

    fireEvent.click(await screen.findByRole('button', { name: 'Générer un code d’association' }))

    expect(await screen.findByText('K7QM-2XPA-9DRT')).toBeInTheDocument()
    expect(screen.getByText(/Profil → Intégration MedVue/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Générer un nouveau code' })).toBeInTheDocument()
    expect(backend.requests('POST', '/api/me/surgicalhub/link-code')).toHaveLength(1)
  })

  it('shows the association, who made it, and dissociates only after a confirming second click', async () => {
    const backend = createFakeBackend({ surgicalHub: { available: true, link: ACTIVE } })
    backend.install()
    render(<SurgicalHubAccountSection />)

    expect(await screen.findByText('Dr Alice Martin')).toBeInTheDocument()
    expect(screen.getByText(/par un administrateur SurgicalHub/)).toBeInTheDocument()
    expect(screen.getByText(/n’envoie aucune donnée de planning à SurgicalHub/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Dissocier SurgicalHub' }))
    expect(backend.requests('DELETE', '/api/me/surgicalhub/link')).toHaveLength(0)
    fireEvent.click(screen.getByRole('button', { name: 'Confirmer : dissocier' }))

    await waitFor(() => expect(backend.requests('DELETE', '/api/me/surgicalhub/link')).toHaveLength(1))
    expect(await screen.findByText(/congés passés et en cours sont conservés/)).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Générer un code d’association' })).toBeInTheDocument()
  })

  it('explains an association ended by SurgicalHub and offers to associate again', async () => {
    createFakeBackend({
      surgicalHub: {
        available: true,
        link: { ...ACTIVE, status: 'REVOKED_REMOTE', revokedAt: '2026-10-09T10:00:00+00:00' },
      },
    }).install()
    render(<SurgicalHubAccountSection />)

    expect(await screen.findByText(/a pris fin côté SurgicalHub/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Générer un code d’association' })).toBeInTheDocument()
  })

  it('offers both ways out of a suspended association: a new code or dissociating', async () => {
    const backend = createFakeBackend({
      surgicalHub: {
        available: true,
        link: { ...ACTIVE, status: 'SUSPENDED', suspendedAt: '2026-10-10T08:00:00+00:00' },
      },
    })
    backend.install()
    render(<SurgicalHubAccountSection />)

    expect(await screen.findByText(/SurgicalHub ne reconnaît plus votre association/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Générer un code d’association' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Dissocier SurgicalHub' })).toBeInTheDocument()
  })
})
