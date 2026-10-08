import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AppShell } from '../../components/AppShell'
import { AdminLayout } from '../../features/admin/AdminLayout'
import { AdminRoute } from '../../features/admin/AdminRoute'
import * as api from '../../features/admin/api'
import { BarChart } from '../../features/admin/charts'
import type {
  AdminSettings,
  AdminUserDetail,
  Overview,
  SystemHealth,
  Timeseries,
} from '../../features/admin/types'
import type { CurrentUser } from '../../features/auth/types'
import { ApiError } from '../../lib/apiClient'
import { AdminOverviewPage } from './AdminOverviewPage'
import { AdminSettingsPage } from './AdminSettingsPage'
import { AdminUserDetailPage } from './AdminUserDetailPage'
import { AdminUsersPage } from './AdminUsersPage'

vi.mock('../../features/admin/api')

let currentUser: CurrentUser | null = null
vi.mock('../../features/auth/useAuth', () => ({
  useAuth: () => ({ user: currentUser, logout: vi.fn() }),
}))

const ME: CurrentUser = {
  id: 1,
  stableId: 'me',
  email: 'admin@example.test',
  firstName: 'Ada',
  lastName: 'Admin',
  active: true,
  platformAdmin: true,
  createdAt: '2026-09-01T10:00:00+00:00',
  updatedAt: '2026-09-01T10:00:00+00:00',
}

const OVERVIEW: Overview = {
  generatedAt: '2026-10-08T10:00:00+00:00',
  timezone: 'Europe/Brussels',
  users: { total: 42, activeAccounts: 40, disabledAccounts: 2, registeredLast30Days: 7, platformAdmins: 1 },
  plannings: { total: 9, createdLast30Days: 3 },
  activity: { activeUsersLast7Days: 12, activeUsersLast30Days: 25, dataSince: '2026-09-15' },
}

function timeseries(range: Timeseries['range']): Timeseries {
  return {
    range,
    granularity: 'day',
    from: '2026-10-06',
    to: '2026-10-08',
    timezone: 'Europe/Brussels',
    points: ['2026-10-06', '2026-10-07', '2026-10-08'].map((bucket, index) => ({
      bucket,
      registrations: index,
      plannings: 0,
      activeUsers: index + 1,
      activeUserDays: index + 1,
    })),
    totals: { registrations: 3, plannings: 0, activeUsers: 3 },
  }
}

const HEALTH: SystemHealth = {
  checkedAt: '2026-10-08T10:00:00+00:00',
  overall: 'unknown',
  version: { release: null, environment: 'dev' },
  checks: {
    api: { status: 'ok', detail: 'L’API a répondu.' },
    database: { status: 'ok', detail: 'Connexion vérifiée.', latencyMs: 1.2 },
    migrations: { status: 'ok', detail: 'Schéma à jour.' },
    jobQueue: { status: 'ok', detail: 'Aucun calcul bloqué.' },
    emails: { status: 'ok', detail: 'Aucun échec.' },
    backup: {
      status: 'unknown',
      detail: 'Aucune remontée des scripts de sauvegarde sur ce serveur.',
      finishedAt: null,
    },
    restoreTest: { status: 'unknown', detail: 'Aucun test.', finishedAt: null },
  },
  errors: {
    status: 'ok',
    detail: 'Aucune erreur serveur enregistrée ces dernières 24 heures.',
    total24h: 0,
    latest: [],
  },
}

function detail(overrides: Partial<AdminUserDetail> = {}): AdminUserDetail {
  return {
    stableId: 'u2',
    email: 'bob@example.test',
    firstName: 'Bob',
    lastName: 'Martin',
    phone: '+32470123456',
    active: true,
    platformAdmin: false,
    emailVerified: false,
    createdAt: '2026-09-02T10:00:00+00:00',
    updatedAt: '2026-09-02T10:00:00+00:00',
    activity: {
      lastActivityAt: '2026-10-07T10:00:00+00:00',
      activeDaysLast30: 4,
      firstActivityDay: '2026-09-02',
    },
    activeSessionCount: 1,
    sessions: [
      {
        startedAt: '2026-10-01T10:00:00+00:00',
        lastUsedAt: '2026-10-07T10:00:00+00:00',
        expiresAt: '2026-11-06T10:00:00+00:00',
        active: true,
        device: 'Chrome · Windows',
      },
    ],
    plannings: [
      {
        stableId: 'p1',
        name: 'Gardes 2027',
        createdAt: '2026-09-03T10:00:00+00:00',
        creator: false,
        roles: ['MEMBER'],
        ongoing: true,
      },
    ],
    ...overrides,
  }
}

const SETTINGS: AdminSettings = {
  platformAdmins: [
    {
      stableId: 'me',
      email: 'admin@example.test',
      firstName: 'Ada',
      lastName: 'Admin',
      active: true,
      lastActivityAt: null,
    },
    {
      stableId: 'u3',
      email: 'carl@example.test',
      firstName: 'Carl',
      lastName: 'Peer',
      active: true,
      lastActivityAt: null,
    },
  ],
  security: {
    accessTokenTtlSeconds: 900,
    refreshTokenTtlSeconds: 2592000,
    passwordResetTokenTtlSeconds: 1800,
    invitationTtlHours: 168,
    secureCookies: false,
  },
  telemetry: {
    timezone: 'Europe/Brussels',
    activityRetentionDays: 400,
    technicalErrorRetentionDays: 90,
    auditRetention: 'permanent',
  },
  version: { release: null, environment: 'dev' },
}

function renderAt(path: string, element: React.ReactNode, route = path) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={route} element={element} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  currentUser = ME
  vi.mocked(api.fetchOverview).mockResolvedValue(OVERVIEW)
  vi.mocked(api.fetchTimeseries).mockImplementation(async (range) => timeseries(range))
  vi.mocked(api.fetchSystemHealth).mockResolvedValue(HEALTH)
  vi.mocked(api.fetchAuditEvents).mockResolvedValue({
    items: [],
    total: 0,
    page: 1,
    perPage: 10,
    pageCount: 1,
  })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('access to /admin', () => {
  it('shows a refusal, not the administration, to a user without the global role', () => {
    currentUser = { ...ME, platformAdmin: false }
    renderAt(
      '/admin',
      <AdminRoute>
        <AdminLayout />
      </AdminRoute>,
      '/admin',
    )

    expect(screen.getByRole('heading', { name: 'Accès réservé' })).toBeInTheDocument()
    expect(
      screen.queryByRole('navigation', { name: 'Navigation de l’administration' }),
    ).not.toBeInTheDocument()
    expect(api.fetchOverview).not.toHaveBeenCalled()
  })

  it('shows the dedicated navigation to a platform administrator', () => {
    renderAt(
      '/admin',
      <AdminRoute>
        <AdminLayout />
      </AdminRoute>,
      '/admin',
    )

    const nav = screen.getByRole('navigation', { name: 'Navigation de l’administration' })
    for (const label of [
      'Vue générale',
      'Utilisateurs',
      'Statistiques',
      'Activité',
      'Infrastructure',
      'Paramètres',
    ]) {
      expect(within(nav).getByRole('link', { name: label })).toBeInTheDocument()
    }
  })

  it('offers the Administration entry in the app only to platform administrators', () => {
    currentUser = { ...ME, platformAdmin: false }
    const { unmount } = renderAt('/', <AppShell />)
    expect(screen.queryByRole('link', { name: 'Administration' })).not.toBeInTheDocument()
    unmount()

    currentUser = ME
    renderAt('/', <AppShell />)
    expect(screen.getByRole('link', { name: 'Administration' })).toHaveAttribute('href', '/admin')
  })
})

describe('Vue générale', () => {
  it('distinguishes accounts from recently active users and never claims an unverified state', async () => {
    renderAt('/admin', <AdminOverviewPage />)

    expect(await screen.findByText('Utilisateurs inscrits')).toBeInTheDocument()
    expect(screen.getByText('Comptes actifs').nextElementSibling).toHaveTextContent('40')
    expect(screen.getByText('Utilisateurs actifs (30 j)').nextElementSibling).toHaveTextContent('25')

    const backup = (await screen.findByText(/Dernière sauvegarde/)).closest('li')!
    expect(within(backup).getByText('Non vérifiable')).toBeInTheDocument()
    expect(within(backup).queryByText('Opérationnel')).not.toBeInTheDocument()
  })

  it('reloads the charts for the chosen period', async () => {
    renderAt('/admin', <AdminOverviewPage />)
    await screen.findAllByText('Inscriptions')

    fireEvent.click(screen.getByRole('button', { name: '12 mois' }))
    await waitFor(() => expect(api.fetchTimeseries).toHaveBeenLastCalledWith('12m'))
  })
})

describe('Utilisateurs', () => {
  it('lists accounts and sends search and filters to the backend', async () => {
    vi.mocked(api.fetchUsers).mockResolvedValue({
      items: [
        {
          stableId: 'u2',
          email: 'bob@example.test',
          firstName: 'Bob',
          lastName: 'Martin',
          phone: null,
          active: false,
          platformAdmin: false,
          createdAt: '2026-09-02T10:00:00+00:00',
          lastActivityAt: null,
          planningCount: 2,
        },
      ],
      total: 1,
      page: 1,
      perPage: 25,
      pageCount: 1,
    })
    renderAt('/admin/users', <AdminUsersPage />)

    const link = await screen.findByRole('link', { name: 'Bob Martin' })
    expect(link).toHaveAttribute('href', '/admin/users/u2')
    expect(screen.getByText('Désactivé')).toBeInTheDocument()
    expect(screen.getByText('Jamais')).toBeInTheDocument()

    fireEvent.change(screen.getByPlaceholderText('Rechercher par nom ou email'), { target: { value: 'bob' } })
    await waitFor(() =>
      expect(api.fetchUsers).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'bob', page: 1 })),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Désactivés' }))
    await waitFor(() =>
      expect(api.fetchUsers).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'disabled' })),
    )
  })
})

describe('Fiche utilisateur', () => {
  it('deactivates after a confirmation carrying the reason', async () => {
    vi.mocked(api.fetchUser).mockResolvedValue(detail())
    vi.mocked(api.runAccountAction).mockResolvedValue(detail({ active: false, activeSessionCount: 0 }))
    renderAt('/admin/users/u2', <AdminUserDetailPage />, '/admin/users/:stableId')

    expect(await screen.findByText('Chrome · Windows')).toBeInTheDocument()
    expect(screen.getByText('Gardes 2027')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Désactiver le compte' }))

    const dialog = screen.getByRole('dialog', { name: 'Désactiver ce compte' })
    fireEvent.change(within(dialog).getByRole('textbox'), { target: { value: 'Départ' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Désactiver' }))

    expect(await screen.findByText('Compte désactivé et sessions fermées.')).toBeInTheDocument()
    expect(api.runAccountAction).toHaveBeenCalledWith('u2', 'deactivate', 'Départ')
    expect(screen.getByRole('button', { name: 'Réactiver le compte' })).toBeInTheDocument()
  })

  it('keeps the dialog open with the API message when the action is refused', async () => {
    vi.mocked(api.fetchUser).mockResolvedValue(detail())
    vi.mocked(api.runAccountAction).mockRejectedValue(
      new ApiError(409, { error: 'already_disabled', message: 'Ce compte est déjà désactivé.' }, 'x'),
    )
    renderAt('/admin/users/u2', <AdminUserDetailPage />, '/admin/users/:stableId')

    fireEvent.click(await screen.findByRole('button', { name: 'Révoquer toutes les sessions' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Révoquer les sessions' }))

    expect(await within(dialog).findByText('Ce compte est déjà désactivé.')).toBeInTheDocument()
  })

  it('offers no action on one’s own account', async () => {
    vi.mocked(api.fetchUser).mockResolvedValue(detail({ stableId: 'me', platformAdmin: true }))
    renderAt('/admin/users/me', <AdminUserDetailPage />, '/admin/users/:stableId')

    expect(
      await screen.findByText('Ces actions ne sont pas disponibles sur votre propre compte.'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Désactiver le compte' })).not.toBeInTheDocument()
  })
})

describe('Paramètres', () => {
  it('requires the password to grant the role and never offers to revoke oneself', async () => {
    vi.mocked(api.fetchSettings).mockResolvedValue(SETTINGS)
    vi.mocked(api.grantPlatformAdmin).mockRejectedValue(
      new ApiError(403, { error: 'password_confirmation_failed', message: 'Mot de passe incorrect.' }, 'x'),
    )
    renderAt('/admin/settings', <AdminSettingsPage />)

    const me = (await screen.findByText('admin@example.test', { exact: false })).closest('li')!
    expect(within(me).queryByRole('button', { name: 'Retirer' })).not.toBeInTheDocument()
    const peer = screen.getByText('carl@example.test', { exact: false }).closest('li')!
    expect(within(peer).getByRole('button', { name: 'Retirer' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Ajouter' }))
    const dialog = screen.getByRole('dialog')
    const submit = within(dialog).getByRole('button', { name: 'Attribuer le rôle' })
    expect(submit).toBeDisabled()

    fireEvent.change(within(dialog).getByLabelText('Email du compte'), {
      target: { value: 'eve@example.test' },
    })
    fireEvent.change(within(dialog).getByLabelText('Votre mot de passe'), { target: { value: 'wrong' } })
    fireEvent.click(submit)

    expect(await within(dialog).findByText('Mot de passe incorrect.')).toBeInTheDocument()
    expect(api.grantPlatformAdmin).toHaveBeenCalledWith('eve@example.test', 'wrong')
  })
})

describe('charts', () => {
  it('exposes every value as a table and in a focusable tooltip', () => {
    render(
      <BarChart
        title="Inscriptions"
        unit="inscriptions"
        data={[
          { key: 'a', label: 'lundi 6 octobre', shortLabel: '6 oct.', value: 2 },
          { key: 'b', label: 'mardi 7 octobre', shortLabel: '7 oct.', value: 5 },
        ]}
      />,
    )

    expect(
      screen.getByRole('img', { name: 'Inscriptions : 7 inscriptions sur la période' }),
    ).toBeInTheDocument()
    fireEvent.focus(screen.getByLabelText('mardi 7 octobre : 5 inscriptions'))
    expect(screen.getByRole('status')).toHaveTextContent('mardi 7 octobre')
    expect(screen.getByRole('table')).toHaveTextContent('mardi 7 octobre5')
  })
})
