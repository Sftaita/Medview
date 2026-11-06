import type { FillLinePlan, FillLineResult } from './types'

const plural = (count: number, one: string, many: string) => `${count} ${count > 1 ? many : one}`

const FULL_DATE = new Intl.DateTimeFormat('fr-BE', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})
const fullDate = (date: string) => FULL_DATE.format(new Date(`${date}T00:00:00Z`))

/**
 * docs/decisions.md D234 — why some holes of a line cannot be filled, in one
 * sentence: no member of the line on their dates (with the first day one
 * joins), or none of the members on these dates can take them.
 */
export function unfillableText(plan: FillLinePlan): string {
  const vacancies = plan.unfillable
  if (!vacancies || vacancies.dutyCount === 0) return ''
  const dates =
    vacancies.from && vacancies.to
      ? vacancies.from === vacancies.to
        ? ` (le ${fullDate(vacancies.from)})`
        : ` (du ${fullDate(vacancies.from)} au ${fullDate(vacancies.to)})`
      : ''
  const what = plural(vacancies.dutyCount, 'garde', 'gardes') + dates
  if (vacancies.noMemberOnDateCount === vacancies.dutyCount) {
    const joins = vacancies.firstMembershipStart
      ? ` : ses membres n’y sont rattachés qu’à partir du ${fullDate(vacancies.firstMembershipStart)}`
      : ''
    return `Aucun membre de « ${plan.lineName} » n’est éligible pour ${what}${joins}.`
  }
  return `Aucun membre de « ${plan.lineName} » ne peut assurer ${what}.`
}

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

/** What "Compléter la période" would do with this line, in one sentence. */
export function fillPlanText(plan: FillLinePlan): string {
  switch (plan.action) {
    case 'GENERATE': {
      const what =
        plan.requiredDutyCount === null
          ? 'ses renforts seront déterminés d’après la ligne dont ils dépendent'
          : plural(plan.requiredDutyCount, 'garde à pourvoir', 'gardes à pourvoir')
      return `Jamais générée : ses gardes seront créées puis attribuées${plan.afterSource ? ', juste après la ligne dont elle dépend' : ''} (${what}).`
    }
    case 'COMPLETE': {
      if (plan.extensionOnly) {
        const others = plan.otherUncoveredCount ?? 0
        return `Publiée : ${plural(plan.uncoveredRequiredDutyCount ?? 0, 'garde des nouvelles dates à attribuer', 'gardes des nouvelles dates à attribuer')}. Les gardes déjà publiées ne changent pas${others > 0 ? `, et ${plural(others, 'ancien poste vacant reste', 'anciens postes vacants restent')} à attribuer à la main` : ''}. À republier ensuite.`
      }
      const holes = plural(plan.uncoveredRequiredDutyCount ?? 0, 'garde non pourvue', 'gardes non pourvues')
      const late =
        (plan.lateMemberCount ?? 0) > 0
          ? ` ${plural(plan.lateMemberCount ?? 0, 'membre arrivé', 'membres arrivés')} depuis la génération ${(plan.lateMemberCount ?? 0) > 1 ? 'sont pris' : 'est pris'} en compte.`
          : ''
      const impossible = plan.unfillable?.dutyCount ?? 0
      if (impossible > 0) {
        const fillable = (plan.uncoveredRequiredDutyCount ?? 0) - impossible
        return `${plural(fillable, 'garde non pourvue', 'gardes non pourvues')} à compléter, sans toucher aux gardes attribuées.${late} ${unfillableText(plan)} Elles resteront vacantes.`
      }
      return `${holes} à compléter, sans toucher aux gardes attribuées.${late}`
    }
    case 'NOTHING':
      if (plan.reason === 'no_eligible_member') {
        return `${unfillableText(plan)} La complétion ne peut pas les pourvoir : rien n’est lancé pour cette ligne.`
      }
      return plan.followsSource
        ? 'Ses gardes actuelles sont conservées. Des renforts seront ajoutés si les nouveaux titulaires de sa ligne source les déclenchent.'
        : 'Toutes les gardes obligatoires sont attribuées : rien à faire.'
    default:
      switch (plan.reason) {
        case 'archived':
          return 'Archivée : jamais modifiée.'
        case 'published': {
          const holes = plan.uncoveredRequiredDutyCount ?? 0
          if (plan.followsSource) {
            return 'Publiée : ses gardes ne changent pas. Des renforts seront ajoutés si les nouveaux titulaires de sa ligne source les déclenchent.'
          }
          return `Publiée : conservée telle quelle${holes > 0 ? ` (${plural(holes, 'garde non pourvue', 'gardes non pourvues')}, à attribuer à la main)` : ''}. Les autres lignes sont traitées normalement.`
        }
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
      if (line.action === 'GENERATE') return 'Première génération effectuée.'
      // Never "complétés" when nobody could take them: what happened, as measured.
      if (line.addedCount === 0) return 'Personne n’a pu être attribué : postes vacants inchangés.'
      return (line.remainingUncoveredCount ?? 0) > 0
        ? 'Postes vacants complétés en partie.'
        : 'Postes vacants complétés.'
    case 'nothing_to_do':
      return 'Rien à faire.'
    case 'not_selected':
      return 'Non sélectionnée : inchangée.'
    case 'excluded':
      return line.reason === 'published'
        ? 'Publiée : jamais complétée automatiquement, inchangée.'
        : 'Exclue : inchangée.'
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
