import { apiFetch } from '../../lib/apiClient'
import type {
  Adoption,
  AdminSettings,
  AdminUserDetail,
  AdminUserRow,
  AuditEvent,
  Granularity,
  Overview,
  Page,
  Range,
  SystemHealth,
  TechnicalError,
  Timeseries,
} from './types'

/** Every call below is refused by the backend (403) unless the session holds ROLE_PLATFORM_ADMIN (D174). */

function query(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') search.set(key, String(value))
  }
  const text = search.toString()
  return text ? `?${text}` : ''
}

export const fetchOverview = () => apiFetch<Overview>('/api/admin/overview')

export const fetchTimeseries = (range: Range, granularity?: Granularity) =>
  apiFetch<Timeseries>(`/api/admin/analytics/timeseries${query({ range, granularity })}`)

export type AdoptionRange = Exclude<Range, '7d'>

export const fetchAdoption = (range: AdoptionRange) =>
  apiFetch<Adoption>(`/api/admin/analytics/adoption${query({ range })}`)

export type UserListParams = {
  search?: string
  status?: 'all' | 'active' | 'disabled'
  sort?: 'createdAt' | 'lastActivity' | 'name' | 'email'
  direction?: 'asc' | 'desc'
  page?: number
  perPage?: number
}

export const fetchUsers = (params: UserListParams) =>
  apiFetch<Page<AdminUserRow>>(`/api/admin/users${query(params)}`)

export const fetchUser = (stableId: string) =>
  apiFetch<AdminUserDetail>(`/api/admin/users/${encodeURIComponent(stableId)}`)

export type AccountAction = 'deactivate' | 'reactivate' | 'revoke-sessions'

export const runAccountAction = (stableId: string, action: AccountAction, reason: string) =>
  apiFetch<AdminUserDetail>(`/api/admin/users/${encodeURIComponent(stableId)}/${action}`, {
    method: 'POST',
    body: reason.trim() === '' ? {} : { reason: reason.trim() },
  })

export const fetchAuditEvents = (params: {
  type?: string
  outcome?: string
  user?: string
  page?: number
  perPage?: number
}) => apiFetch<Page<AuditEvent>>(`/api/admin/audit-events${query(params)}`)

export const fetchTechnicalErrors = (page = 1) =>
  apiFetch<Page<TechnicalError>>(`/api/admin/technical-errors${query({ page })}`)

export const fetchSystemHealth = () => apiFetch<SystemHealth>('/api/admin/system-health')

export const fetchSettings = () => apiFetch<AdminSettings>('/api/admin/settings')

export const grantPlatformAdmin = (email: string, password: string) =>
  apiFetch<AdminSettings>('/api/admin/platform-admins', { method: 'POST', body: { email, password } })

export const revokePlatformAdmin = (stableId: string, password: string) =>
  apiFetch<AdminSettings>(`/api/admin/platform-admins/${encodeURIComponent(stableId)}/revoke`, {
    method: 'POST',
    body: { password },
  })
