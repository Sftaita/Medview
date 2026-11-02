import type { FillLinePlan, FillLineResult } from './types'

const plural = (count: number, one: string, many: string) => `${count} ${count > 1 ? many : one}`

/** Why a line cannot be given its first generation — from its stable code, never an internal message. */
export function fillBlockerText(code: string): string {
  switch (code) {
    case 'NO_ACTIVE_RULE_SET':
      return 'les règles de génération de la ligne ne sont pas activées'
    case 'NO_DUTIES':
      return 'aucune garde n’est définie (semaine type à configurer)'
    case 'NO_MEMBER_IN_PERIOD':
      return 'aucun membre n’est présent sur la période'
    case 'CONDITIONAL_SOURCE_NOT_GENERATED':
      return 'la ligne dont dépendent ses renforts n’a pas encore de gardes attribuées'
    case 'CONDITIONAL_SOURCE_NOT_IN_PERIOD':
      return 'la ligne dont dépendent ses renforts ne participe pas à cette période'
    case 'BLOCK_CUT_BY_PERIOD_BOUNDARY':
      return 'une limite de période coupe un de ses blocs'
    case 'AMBIGUOUS_COVERAGE_SOURCE':
      return 'la garde source d’un renfort est ambiguë'
    case 'NO_SOLVER_PARAMETER_SET':
      return 'le moteur de génération n’est pas configuré'
    default:
      return 'un prérequis de la génération manque'
  }
}

/** What "Générer les postes non pourvus" would do with this line, in one sentence. */
export function fillPlanText(plan: FillLinePlan): string {
  switch (plan.action) {
    case 'GENERATE': {
      const what =
        plan.requiredDutyCount === null
          ? 'ses renforts seront déterminés d’après la ligne dont ils dépendent'
          : plural(plan.requiredDutyCount, 'garde à pourvoir', 'gardes à pourvoir')
      return `Jamais générée : première génération de cette ligne seule${plan.afterSource ? ', juste après la ligne dont elle dépend' : ''} (${what}).`
    }
    case 'COMPLETE': {
      const holes = plural(plan.uncoveredRequiredDutyCount ?? 0, 'garde non pourvue', 'gardes non pourvues')
      return plan.reason === 'published'
        ? `${holes}. Ligne publiée : les gardes ajoutées apparaîtront dans « Modifications non publiées » — rien n’est republié ni envoyé.`
        : `${holes} à compléter, sans toucher aux gardes attribuées.`
    }
    case 'NOTHING':
      return 'Toutes les gardes obligatoires sont attribuées : rien à faire.'
    default:
      switch (plan.reason) {
        case 'archived':
          return 'Archivée : jamais modifiée.'
        case 'nothing_demanded':
          return 'Aucune garde demandée sur cette période.'
        case 'locked':
          return 'Verrouillée : jamais régénérée.'
        default:
          return plan.blockers.length > 0
            ? `Impossible pour l’instant : ${plan.blockers.map(fillBlockerText).join(' ; ')}.`
            : 'Impossible pour l’instant.'
      }
  }
}

/** The status of one line after a run, in one sentence (the counts are shown apart). */
export function fillResultText(line: FillLineResult): string {
  switch (line.status) {
    case 'done':
      return line.action === 'GENERATE' ? 'Première génération effectuée.' : 'Postes vacants complétés.'
    case 'nothing_to_do':
      return 'Rien à faire.'
    case 'not_selected':
      return 'Non sélectionnée : inchangée.'
    case 'excluded':
      return 'Exclue : inchangée.'
    case 'blocked':
      return line.blockers.length > 0
        ? `Non générée : ${line.blockers.map(fillBlockerText).join(' ; ')}.`
        : 'Non générée : un prérequis manquait.'
    case 'calendar_changed':
      return 'Le planning a été modifié pendant le calcul : rien n’a été enregistré pour cette ligne.'
    default:
      return 'Le moteur n’a pas pu produire de résultat exploitable : rien n’a été enregistré pour cette ligne.'
  }
}
