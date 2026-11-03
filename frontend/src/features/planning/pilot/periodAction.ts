import type { FillLinePlan } from './types'

/**
 * The main action of a period (docs/decisions.md D228): one place, one state,
 * decided by the real state of the period — never by which period it is.
 *
 * - no line generated: "Générer la période N";
 * - at least one line generated, something to receive: "Compléter la période N";
 * - nothing left but holes of published lines: said, nothing to launch;
 * - every duty needed is covered: "Période complète".
 *
 * Until the lines are analysed (`plans` undefined), a generated period reads
 * "Compléter": same label, same place, and the dialog says what there is to do.
 */
export type PeriodAction =
  | { kind: 'busy'; label: string }
  | { kind: 'generate'; label: string }
  | { kind: 'complete'; label: string }
  | { kind: 'manual'; label: string; holes: number }
  | { kind: 'done'; label: string }

export const COMPLETE_HELP =
  'Complète automatiquement les gardes non pourvues de toutes les lignes de cette période, lorsque cela est possible. Les gardes déjà attribuées sont conservées. La répartition respecte les disponibilités, les contraintes et les règles d’équité de chaque groupe.'

export function periodAction(input: {
  number: number
  hasGeneratedLines: boolean
  plans?: FillLinePlan[]
  busy: boolean
}): PeriodAction {
  const { number, hasGeneratedLines, plans, busy } = input
  if (busy) return { kind: 'busy', label: 'Calcul en cours…' }
  if (!hasGeneratedLines) return { kind: 'generate', label: `Générer la période ${number}` }

  const complete = { kind: 'complete' as const, label: `Compléter la période ${number}` }
  if (!plans) return complete

  // A line waiting for something (its first generation blocked, a fact to configure): the dialog says why.
  const open = plans.some(
    (plan) =>
      plan.action === 'GENERATE' ||
      plan.action === 'COMPLETE' ||
      (plan.action === 'EXCLUDED' && plan.reason === 'blocked'),
  )
  if (open) return complete

  const holes = plans
    .filter((plan) => plan.action === 'EXCLUDED' && plan.reason === 'published')
    .reduce((sum, plan) => sum + (plan.uncoveredRequiredDutyCount ?? 0), 0)
  if (holes > 0) {
    return {
      kind: 'manual',
      label: `${holes} garde${holes > 1 ? 's' : ''} à attribuer à la main`,
      holes,
    }
  }
  return { kind: 'done', label: 'Période complète' }
}
