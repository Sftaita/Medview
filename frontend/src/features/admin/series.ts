import { ApiError } from '../../lib/apiClient'
import type { BarDatum } from './charts'
import { formatDay } from './format'
import type { Range, Timeseries, TimeseriesPoint } from './types'

export const RANGE_OPTIONS: { value: Range; label: string }[] = [
  { value: '7d', label: '7 jours' },
  { value: '30d', label: '30 jours' },
  { value: '90d', label: '90 jours' },
  { value: '12m', label: '12 mois' },
]

/** Bars of one series of a timeseries, labelled by its granularity (day, ISO week, month). */
export function seriesBars(series: Timeseries, field: keyof Omit<TimeseriesPoint, 'bucket'>): BarDatum[] {
  return series.points.map((point) => {
    const label =
      series.granularity === 'month'
        ? formatDay(point.bucket, { month: 'long', year: 'numeric' })
        : series.granularity === 'week'
          ? `Semaine du ${formatDay(point.bucket, { day: 'numeric', month: 'long' })}`
          : formatDay(point.bucket, { weekday: 'long', day: 'numeric', month: 'long' })
    const shortLabel =
      series.granularity === 'month'
        ? formatDay(point.bucket, { month: 'short' })
        : formatDay(point.bucket, { day: 'numeric', month: 'short' })
    return { key: point.bucket, label, shortLabel, value: point[field] }
  })
}

/** The message the API gave for a refused action, or a generic one. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const body = error.body as { message?: string; violations?: Record<string, string> } | null
    if (body?.violations) return Object.values(body.violations).join(' ')
    if (body?.message) return body.message
    if (error.status === 403) return 'Action refusée.'
  }
  return 'Une erreur est survenue. Réessayez.'
}
