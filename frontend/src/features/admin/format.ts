import type { AuditEvent, AuditEventType, HealthStatus, SeniorityBucket } from './types'

/** Display helpers of the administration. Dates are shown in the platform's zone, never the browser's guess. */
export const PLATFORM_TIMEZONE = 'Europe/Brussels'

const numberFormat = new Intl.NumberFormat('fr-BE')

export function formatNumber(value: number): string {
  return numberFormat.format(value)
}

export function formatPercent(rate: number | null): string {
  if (rate === null) return '—'
  return new Intl.NumberFormat('fr-BE', { style: 'percent', maximumFractionDigits: 1 }).format(rate)
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('fr-BE', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: PLATFORM_TIMEZONE,
  }).format(new Date(iso))
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('fr-BE', { dateStyle: 'medium', timeZone: PLATFORM_TIMEZONE }).format(
    new Date(iso),
  )
}

/** A "YYYY-MM-DD" platform day (no time zone shift: it is already a calendar day). */
export function formatDay(
  day: string | null | undefined,
  options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
): string {
  if (!day) return '—'
  const [year, month, date] = day.split('-').map(Number)
  return new Intl.DateTimeFormat('fr-BE', { ...options, timeZone: 'UTC' }).format(
    new Date(Date.UTC(year, month - 1, date)),
  )
}

/** "il y a 3 h", "il y a 2 j" — relative to now. */
export function formatRelative(iso: string | null | undefined, now: Date = new Date()): string {
  if (!iso) return 'Jamais'
  const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000)
  const rtf = new Intl.RelativeTimeFormat('fr-BE', { numeric: 'auto', style: 'short' })
  const abs = Math.abs(seconds)
  if (abs < 60) return 'à l’instant'
  if (abs < 3600) return rtf.format(Math.round(seconds / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(seconds / 3600), 'hour')
  if (abs < 86400 * 45) return rtf.format(Math.round(seconds / 86400), 'day')
  return formatDate(iso)
}

export function formatBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) return '—'
  const units = ['o', 'Ko', 'Mo', 'Go']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${new Intl.NumberFormat('fr-BE', { maximumFractionDigits: unit === 0 ? 0 : 1 }).format(value)} ${units[unit]}`
}

export function formatDuration(seconds: number): string {
  if (seconds % 86400 === 0) return `${seconds / 86400} j`
  if (seconds % 3600 === 0) return `${seconds / 3600} h`
  if (seconds % 60 === 0) return `${seconds / 60} min`
  return `${seconds} s`
}

export const STATUS_LABELS: Record<HealthStatus, string> = {
  ok: 'Opérationnel',
  warning: 'À surveiller',
  error: 'En erreur',
  unknown: 'Non vérifiable',
}

export const AUDIT_TYPE_LABELS: Record<AuditEventType, string> = {
  USER_REGISTERED: 'Inscription',
  USER_DEACTIVATED: 'Compte désactivé',
  USER_REACTIVATED: 'Compte réactivé',
  USER_SESSIONS_REVOKED: 'Sessions révoquées',
  PLATFORM_ADMIN_GRANTED: 'Rôle administrateur attribué',
  PLATFORM_ADMIN_REVOKED: 'Rôle administrateur retiré',
  PASSWORD_RESET_COMPLETED: 'Mot de passe réinitialisé',
}

export const OUTCOME_LABELS: Record<AuditEvent['outcome'], string> = {
  SUCCESS: 'Effectué',
  DENIED: 'Refusé',
  FAILURE: 'Échec',
}

export const DENIED_REASONS: Record<string, string> = {
  cannot_target_self: 'action sur son propre compte',
  target_is_platform_admin: 'cible administratrice de la plateforme',
  password_confirmation_failed: 'mot de passe incorrect',
}

export const SENIORITY_LABELS: Record<SeniorityBucket, string> = {
  lt30: 'Moins de 30 jours',
  '30to89': '30 à 89 jours',
  '90to364': '3 à 12 mois',
  gte365: 'Plus d’un an',
}

export const ROLE_LABELS: Record<string, string> = {
  OWNER: 'Propriétaire',
  ADMIN: 'Administrateur d’équipe',
  MEMBER: 'Membre',
}

export function personName(person: { firstName: string; lastName: string } | null): string {
  return person ? `${person.firstName} ${person.lastName}` : ''
}

export function initials(firstName: string, lastName: string): string {
  return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase()
}
