import { Icon } from '../../../components/Icon'
import type { PublicationUncoveredUnit } from '../result/types'
import { datesLabel } from './preflightIssues'

type Props = {
  units: PublicationUncoveredUnit[] | undefined
  /** "Voir dans le calendrier": the calendar goes to the duty and highlights it. */
  onLocate?: (dutyStableId: string) => void
}

const FULL_DATE = new Intl.DateTimeFormat('fr-BE', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

function fullDate(date: string): string {
  return FULL_DATE.format(new Date(`${date}T00:00:00Z`))
}

/** Why these posts are empty, and what can be done about it — in the words of the check behind the calendar. */
function vacancyText(unit: PublicationUncoveredUnit): { why: string; next: string } {
  const { vacancy, lineName, linePublished } = unit
  const afterwards = linePublished ? 'puis republiez' : 'puis publiez'
  switch (vacancy.code) {
    case 'NO_MEMBER_ON_DATE':
      return {
        why: vacancy.firstMembershipStart
          ? `Aucun membre de « ${lineName} » à ces dates : les adhésions commencent le ${fullDate(vacancy.firstMembershipStart)}.`
          : `Aucun membre de « ${lineName} » à ces dates.`,
        next: 'Ni la complétion ni une attribution manuelle ne peuvent les pourvoir. Si aucune garde n’est nécessaire à ces dates, réglez le « Début de couverture de cette ligne » dans ses paramètres.',
      }
    case 'NOT_CREATED':
      return {
        why: 'Renforts devenus nécessaires après la génération (titulaire de la ligne source modifié) : ces gardes ne sont pas encore créées.',
        next: linePublished
          ? `Créez ces postes depuis le calendrier, attribuez-les, ${afterwards}.`
          : 'Créez-les depuis le calendrier ou utilisez « Compléter la période » en haut de page.',
      }
    case 'NO_ELIGIBLE_MEMBER':
      return {
        why: `Aucun membre ne peut les assurer : ${vacancy.reasons.map((reason) => `${reason.label} (${reason.count})`).join(', ')}.`,
        next: 'Levez l’un de ces empêchements, ou modifiez le titulaire de la ligne source.',
      }
    case 'ASSIGNABLE_MEMBERS':
      return {
        why: 'Des membres peuvent les assurer : personne n’a encore été désigné.',
        next: linePublished
          ? `Attribuez-les depuis le calendrier, ${afterwards}.`
          : 'Attribuez-les depuis le calendrier ou utilisez « Compléter la période » en haut de page.',
      }
  }
}

/** Same explanation, same group: 13 posts with one cause are one sentence and 13 rows. */
function vacancyKey(unit: PublicationUncoveredUnit): string {
  return JSON.stringify([
    unit.lineStableId,
    unit.vacancy.code,
    unit.vacancy.firstMembershipStart,
    unit.vacancy.reasons,
  ])
}

/** "Jerome De Muylder tient « Première ligne » et déclenche ce renfort" — one sentence per source holder of the post. */
function whyRequired(unit: PublicationUncoveredUnit): string {
  if (!unit.conditional) return 'Garde obligatoire de cette ligne.'
  const holders = [
    ...new Set(
      unit.days.map((day) =>
        day.sourceHolder ? `${day.sourceHolder.firstName} ${day.sourceHolder.lastName}` : '',
      ),
    ),
  ].filter(Boolean)
  if (holders.length === 0) return 'Renfort requis.'
  const source = unit.sourceLineName ? `tient « ${unit.sourceLineName} »` : 'tient la ligne source'
  return holders.length === 1
    ? `${holders[0]} ${source} et déclenche ce renfort.`
    : `${holders.join(' et ')} ${unit.sourceLineName ? `tiennent « ${unit.sourceLineName} »` : 'tiennent la ligne source'} et déclenchent ce renfort.`
}

function count(units: PublicationUncoveredUnit[]): string {
  const days = units.reduce((total, unit) => total + unit.days.length, 0)
  return days === 1 ? '1 garde requise' : `${days} gardes requises`
}

/**
 * Every required post without holder (docs/decisions.md D232, D233), keeping a
 * line from its first publication or told before a republication: its dates, the source holder who triggers it, and
 * why it is empty. The backend decides all of it: nothing is computed here.
 */
export function UncoveredPostsList({ units, onLocate }: Props) {
  if (!units || units.length === 0) return null
  const groups = new Map<string, PublicationUncoveredUnit[]>()
  for (const unit of units) {
    const key = vacancyKey(unit)
    groups.set(key, [...(groups.get(key) ?? []), unit])
  }

  return (
    <div className="uncovered-posts">
      {[...groups.entries()].map(([key, group]) => {
        const { why, next } = vacancyText(group[0])
        return (
          <section key={key} className="alert alert--warning uncovered-posts__group">
            <Icon name="alert" size={18} strokeWidth={2} />
            <div className="preflight-issue__body">
              <strong>
                {group[0].lineName} — {count(group)} sans titulaire
              </strong>
              {group[0].linePublished && (
                <span>
                  Ligne déjà publiée : cela n’empêche pas de republier, mais ces postes resteront vacants.
                </span>
              )}
              <span>{why}</span>
              <span className="preflight-issue__rule">{next}</span>
              <ul className="uncovered-posts__list" aria-label={`Postes de ${group[0].lineName}`}>
                {group.map((unit) => (
                  <li key={unit.dates.join('|')}>
                    <div>
                      <strong>{datesLabel(unit.dates)}</strong>
                      <span className="preflight-issue__rule"> — {whyRequired(unit)}</span>
                    </div>
                    {onLocate && unit.dutyStableIds[0] && (
                      <button
                        type="button"
                        className="btn btn--sm btn--secondary preflight-issue__locate"
                        onClick={() => onLocate(unit.dutyStableIds[0])}
                      >
                        Voir dans le calendrier
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          </section>
        )
      })}
    </div>
  )
}
