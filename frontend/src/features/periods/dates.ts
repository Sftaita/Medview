import type { ISODate } from './types'

export const DAY_MS = 86_400_000

const MOIS = [
  'janv.',
  'févr.',
  'mars',
  'avr.',
  'mai',
  'juin',
  'juil.',
  'août',
  'sept.',
  'oct.',
  'nov.',
  'déc.',
]
const MOIS_CAP = [
  'Janv.',
  'Févr.',
  'Mars',
  'Avr.',
  'Mai',
  'Juin',
  'Juil.',
  'Août',
  'Sept.',
  'Oct.',
  'Nov.',
  'Déc.',
]
const MOIS_LONG = [
  'janvier',
  'février',
  'mars',
  'avril',
  'mai',
  'juin',
  'juillet',
  'août',
  'septembre',
  'octobre',
  'novembre',
  'décembre',
]
const JOURS = ['Dim.', 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.']
const JOURS_LONG = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi']

/** Millisecondes UTC à minuit — évite tout décalage de fuseau. */
export function toMs(iso: ISODate): number {
  const [y, m, d] = iso.split('-').map(Number)
  return Date.UTC(y, m - 1, d)
}

export function toIso(ms: number): ISODate {
  return new Date(ms).toISOString().slice(0, 10)
}

export function isValidIso(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(toMs(value))
}

export function addDays(iso: ISODate, n: number): ISODate {
  return toIso(toMs(iso) + n * DAY_MS)
}

/** Nombre de jours, bornes incluses : 1 oct. → 30 janv. = 122. */
export function daysInclusive(start: ISODate, end: ISODate): number {
  return Math.round((toMs(end) - toMs(start)) / DAY_MS) + 1
}

/** Fin d’une durée de k mois commençant à `start` : 1 mai + 4 mois → 31 août. */
export function endAfterMonths(start: ISODate, months: number): ISODate {
  const d = new Date(toMs(start))
  return toIso(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + months, d.getUTCDate()) - DAY_MS)
}

type DateStyle = 'dm' | 'dmy' | 'card' | 'long' | 'wdm' | 'my'

export function formatDate(iso: ISODate, style: DateStyle = 'dmy'): string {
  const x = new Date(toMs(iso))
  const d = x.getUTCDate(),
    m = x.getUTCMonth(),
    y = x.getUTCFullYear(),
    w = x.getUTCDay()
  switch (style) {
    case 'dm':
      return `${d} ${MOIS[m]}`
    case 'dmy':
      return `${d} ${MOIS[m]} ${y}`
    case 'card':
      return `${JOURS[w]} ${d} ${MOIS[m]} ${y}`
    case 'long':
      return `${JOURS_LONG[w]} ${d} ${MOIS_LONG[m]} ${y}`
    case 'wdm':
      return `${JOURS[w]} ${d} ${MOIS[m]}`
    case 'my':
      return `${MOIS_CAP[m]} ${y}`
  }
}

/** « 1 oct. 2026 → 30 janv. 2027 » ou « 31 janv. → 30 avr. 2027 » si même année. */
export function formatRange(start: ISODate, end: ISODate): string {
  const sameYear = start.slice(0, 4) === end.slice(0, 4)
  return `${formatDate(start, sameYear ? 'dm' : 'dmy')} → ${formatDate(end, 'dmy')}`
}

export type TimeKind = 'past' | 'current' | 'future'

export interface TimeState {
  kind: TimeKind
  /** Durée totale en jours. */
  days: number
  /** Jour courant (1-based) si en cours. */
  dayIndex?: number
  /** Jours avant le début si future. */
  daysUntil?: number
  /** Pourcentage du temps écoulé, 0–100. */
  elapsedPct: number
  label: string
  shortLabel: string
}

/** Position d’une période dans le temps — indépendante de son statut de préparation. */
export function timeState(range: { start: ISODate; end: ISODate }, today: ISODate): TimeState {
  const days = daysInclusive(range.start, range.end)
  const t = toMs(today)
  if (t > toMs(range.end)) {
    return {
      kind: 'past',
      days,
      elapsedPct: 100,
      label: `Terminée le ${formatDate(range.end)}`,
      shortLabel: 'terminée',
    }
  }
  if (t < toMs(range.start)) {
    const daysUntil = Math.round((toMs(range.start) - t) / DAY_MS)
    return {
      kind: 'future',
      days,
      daysUntil,
      elapsedPct: 0,
      label: daysUntil === 1 ? 'Commence demain' : `Commence dans ${daysUntil} jours`,
      shortLabel: `dans ${daysUntil} j`,
    }
  }
  const dayIndex = Math.round((t - toMs(range.start)) / DAY_MS) + 1
  return {
    kind: 'current',
    days,
    dayIndex,
    elapsedPct: ((dayIndex - 1) / days) * 100,
    label: `En cours · jour ${dayIndex} sur ${days}`,
    shortLabel: `jour ${dayIndex}/${days}`,
  }
}
