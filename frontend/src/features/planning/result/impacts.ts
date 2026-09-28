import type { DependentImpact, LiveCoverageState } from './types'

const DAY = new Intl.DateTimeFormat('fr-BE', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })

function when(dates: string[]): string {
  const format = (date: string) => DAY.format(new Date(`${date}T00:00:00Z`))
  return dates.length > 1 ? `du ${format(dates[0])} au ${format(dates[dates.length - 1])}` : format(dates[0])
}

function outcome(state: LiveCoverageState, assignee: DependentImpact['assignee']): string {
  const who = assignee ? `${assignee.firstName} ${assignee.lastName}` : null
  switch (state) {
    case 'REQUIRED_UNASSIGNED':
      return 'renfort désormais requis — non attribué'
    case 'REQUIRED_ASSIGNED':
      return who ? `renfort requis — déjà attribué à ${who}` : 'renfort requis'
    case 'NOT_REQUIRED_ASSIGNED':
      return who ? `renfort plus nécessaire — ${who} reste affecté(e)` : 'renfort plus nécessaire'
    case 'NOT_REQUIRED_UNASSIGNED':
      return 'plus de renfort nécessaire'
    case 'UNDETERMINED':
      return 'besoin de renfort non évaluable (la garde source n’a plus de titulaire)'
  }
}

/**
 * One compact line per reinforcement whose state changed (docs/decisions.md
 * D165/D167) — e.g. "Renfort, ven. 8 janv. : renfort désormais requis — non
 * attribué". Read from the backend's dependentImpacts; nothing is compared here.
 */
export function impactSummary(impacts: DependentImpact[]): string[] {
  return impacts
    .filter((impact) => impact.changed)
    .map((impact) => `${impact.lineName}, ${when(impact.dates)} : ${outcome(impact.newState, impact.assignee)}`)
}
