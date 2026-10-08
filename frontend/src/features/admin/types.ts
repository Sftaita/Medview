/** Shapes of the /api/admin responses (docs/admin.md §9). Instants are ISO 8601 with their offset; days "YYYY-MM-DD" in Europe/Brussels. */

export type HealthStatus = 'ok' | 'warning' | 'error' | 'unknown'

export type Overview = {
  generatedAt: string
  timezone: string
  users: {
    total: number
    activeAccounts: number
    disabledAccounts: number
    registeredLast30Days: number
    platformAdmins: number
  }
  plannings: { total: number; createdLast30Days: number }
  activity: { activeUsersLast7Days: number; activeUsersLast30Days: number; dataSince: string | null }
}

export type Range = '7d' | '30d' | '90d' | '12m'
export type Granularity = 'day' | 'week' | 'month'

export type TimeseriesPoint = {
  bucket: string
  registrations: number
  plannings: number
  activeUsers: number
  activeUserDays: number
}

export type Timeseries = {
  range: Range
  granularity: Granularity
  from: string
  to: string
  timezone: string
  points: TimeseriesPoint[]
  totals: { registrations: number; plannings: number; activeUsers: number }
}

export type Retention = {
  windowDays: number
  cohortFrom: string
  cohortTo: string
  cohortSize: number
  returned: number
  rate: number | null
}

export type SeniorityBucket = 'lt30' | '30to89' | '90to364' | 'gte365'

export type Adoption = {
  range: Range
  timezone: string
  dataSince: string | null
  dauMau: { day: string; dau: number; mau: number }[]
  current: { dau: number; mau: number; averageDauLast30Days: number; stickiness: number | null }
  retention: { day7: Retention; day30: Retention }
  seniority: { bucket: SeniorityBucket; count: number }[]
}

export type AdminUserRow = {
  stableId: string
  email: string
  firstName: string
  lastName: string
  phone: string | null
  active: boolean
  platformAdmin: boolean
  createdAt: string
  lastActivityAt: string | null
  planningCount: number
}

export type Page<T> = { items: T[]; total: number; page: number; perPage: number; pageCount: number }

export type AdminUserDetail = {
  stableId: string
  email: string
  firstName: string
  lastName: string
  phone: string | null
  active: boolean
  platformAdmin: boolean
  emailVerified: boolean
  createdAt: string
  updatedAt: string
  activity: { lastActivityAt: string | null; activeDaysLast30: number; firstActivityDay: string | null }
  activeSessionCount: number
  sessions: { startedAt: string; lastUsedAt: string; expiresAt: string; active: boolean; device: string }[]
  plannings: {
    stableId: string
    name: string
    createdAt: string
    creator: boolean
    roles: string[]
    ongoing: boolean
  }[]
}

export type AuditEventType =
  | 'USER_REGISTERED'
  | 'USER_DEACTIVATED'
  | 'USER_REACTIVATED'
  | 'USER_SESSIONS_REVOKED'
  | 'PLATFORM_ADMIN_GRANTED'
  | 'PLATFORM_ADMIN_REVOKED'
  | 'PASSWORD_RESET_COMPLETED'

export type AuditPerson = { stableId: string; firstName: string; lastName: string; email: string }

export type AuditEvent = {
  stableId: string
  occurredAt: string
  type: AuditEventType
  outcome: 'SUCCESS' | 'DENIED' | 'FAILURE'
  actorKind: 'USER' | 'CONSOLE' | 'SYSTEM'
  actor: AuditPerson | null
  target: AuditPerson | null
  context: Record<string, string | number | boolean | null>
}

export type TechnicalError = {
  occurredAt: string
  source: 'HTTP' | 'WORKER'
  exceptionClass: string
  route: string | null
  httpMethod: string | null
}

export type Check = { status: HealthStatus; detail: string }

export type SystemHealth = {
  checkedAt: string
  overall: HealthStatus
  version: { release: string | null; environment: string }
  checks: {
    api: Check & { environment?: string; phpVersion?: string }
    database: Check & { latencyMs?: number | null; serverVersion?: string | null; sizeBytes?: number | null }
    migrations: Check & { pending?: number; current?: string }
    jobQueue: Check & {
      waiting?: number
      oldestWaitingSince?: string | null
      failedMessages?: number
      last7Days?: {
        succeeded: number
        failed: number
        averageSeconds: number | null
        maxSeconds: number | null
      }
    }
    emails: Check & { failed?: number; stuck?: number; sentLast7Days?: number }
    backup: Check & {
      finishedAt?: string | null
      result?: string | null
      postgresDumpBytes?: number | null
      latestMigration?: string | null
    }
    restoreTest: Check & { finishedAt?: string | null; result?: string | null }
  }
  errors: Check & { total24h?: number; total7d?: number; latest?: TechnicalError[]; retentionDays?: number }
}

export type PlatformAdmin = {
  stableId: string
  email: string
  firstName: string
  lastName: string
  active: boolean
  lastActivityAt: string | null
}

export type AdminSettings = {
  platformAdmins: PlatformAdmin[]
  security: {
    accessTokenTtlSeconds: number
    refreshTokenTtlSeconds: number
    passwordResetTokenTtlSeconds: number
    invitationTtlHours: number
    secureCookies: boolean
  }
  telemetry: {
    timezone: string
    activityRetentionDays: number
    technicalErrorRetentionDays: number
    auditRetention: string
  }
  version: { release: string | null; environment: string }
}
