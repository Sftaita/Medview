import type { ISODate, Period, PeriodStatus } from './types'
import { addDays, toMs, daysInclusive } from './dates'

/** Durée minimale d’une nouvelle période, en jours. */
export const MIN_PERIOD_DAYS = 7

/** Au-delà de ce nombre, les périodes anciennes passent dans « Historique ». */
export const FRISE_COLLAPSE_AFTER = 4

export const STATUS_LABEL: Record<PeriodStatus, string> = {
  preparation: 'En préparation',
  collecte: 'Collecte en cours',
  generee: 'Générée',
  partielle: 'Partiellement publiée',
  publiee: 'Publiée',
  archivee: 'Archivée',
}

/** Index de la période qui contient `today` ; sinon la première future ; sinon la dernière. */
export function currentIndex(periods: Period[], today: ISODate): number {
  const t = toMs(today)
  const inside = periods.findIndex((p) => t >= toMs(p.start) && t <= toMs(p.end))
  if (inside >= 0) return inside
  const future = periods.findIndex((p) => toMs(p.start) > t)
  return future >= 0 ? future : periods.length - 1
}

/** Ce que montre la carte « Mes plannings » : la période en cours et la suivante, rien d’autre. */
export function currentAndNext(periods: Period[], today: ISODate): { current?: Period; next?: Period } {
  if (periods.length === 0) return {}
  const i = currentIndex(periods, today)
  return { current: periods[i], next: periods[i + 1] }
}

/**
 * Fenêtre de la frise : jusqu’à 4 périodes, tout est visible.
 * Au-delà : la précédente, l’en cours et les suivantes ; les plus anciennes vont dans « Historique ».
 */
export function friseWindow(periods: Period[], today: ISODate): { visible: Period[]; history: Period[] } {
  if (periods.length <= FRISE_COLLAPSE_AFTER) return { visible: periods, history: [] }
  const from = Math.max(0, currentIndex(periods, today) - 1)
  return { visible: periods.slice(from), history: periods.slice(0, from) }
}

/** La nouvelle période commence le lendemain de la fin de la dernière. */
export function nextPeriodStart(periods: Period[]): ISODate {
  return addDays(periods[periods.length - 1].end, 1)
}

export function validateEnd(start: ISODate, end: ISODate | ''): { ok: boolean; error?: string } {
  if (!end) return { ok: false }
  if (daysInclusive(start, end) < MIN_PERIOD_DAYS) {
    return { ok: false, error: `La fin doit être au moins ${MIN_PERIOD_DAYS} jours après le début.` }
  }
  return { ok: true }
}

/** Construit la nouvelle période. Ne modifie jamais les périodes existantes. */
export function buildNextPeriod(periods: Period[], end: ISODate, id: string): Period {
  return {
    id,
    number: periods.length + 1,
    start: nextPeriodStart(periods),
    end,
    status: 'preparation',
    collecte: { state: 'non-ouverte', responses: 0 },
  }
}
