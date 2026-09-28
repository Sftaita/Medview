import type { ApiWeekday } from './types'

/**
 * The one explicit mapping between the screen's days and the API's
 * (docs/decisions.md D162): "Lun…Dim" are only ever labels, the API only ever
 * receives MONDAY…SUNDAY — never a string comparison between the two.
 */
export const WEEKDAYS: readonly { api: ApiWeekday; short: string; long: string }[] = [
  { api: 'MONDAY', short: 'Lun', long: 'lundi' },
  { api: 'TUESDAY', short: 'Mar', long: 'mardi' },
  { api: 'WEDNESDAY', short: 'Mer', long: 'mercredi' },
  { api: 'THURSDAY', short: 'Jeu', long: 'jeudi' },
  { api: 'FRIDAY', short: 'Ven', long: 'vendredi' },
  { api: 'SATURDAY', short: 'Sam', long: 'samedi' },
  { api: 'SUNDAY', short: 'Dim', long: 'dimanche' },
]

export const ALL_API_WEEKDAYS: ApiWeekday[] = WEEKDAYS.map((day) => day.api)

/** Monday first, whatever order the days were selected in. */
export function sortWeekdays(days: Iterable<ApiWeekday>): ApiWeekday[] {
  const set = new Set(days)
  return ALL_API_WEEKDAYS.filter((day) => set.has(day))
}

export function weekdayLabel(day: ApiWeekday): string {
  return WEEKDAYS.find((d) => d.api === day)?.long ?? day
}
