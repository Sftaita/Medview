import { Icon } from '../../../components/Icon'
import type { PlanningResultLine } from './types'

type Props = {
  lines: PlanningResultLine[]
}

/**
 * "Couverture 28/31 · 3 gardes non couvertes" — or "Couverture complète",
 * or the neutral "aucune génération" state (docs/decisions.md D130 §13):
 * never a fake 0/0, never "Erreur de génération" for a normal INCOMPLETE
 * outcome (§14). Aggregates every line that has a generation; lines
 * without one yet are reported separately, never silently dropped.
 */
export function CoverageHeader({ lines }: Props) {
  const generated = lines.filter((line) => null !== line.generationStableId)
  const notGenerated = lines.filter((line) => null === line.generationStableId)

  if (generated.length === 0) {
    return (
      <p className="muted coverage-header__empty">
        Aucun planning n&apos;a encore été généré pour cette période.
      </p>
    )
  }

  const requiredTotal = generated.reduce((sum, line) => sum + line.requiredDutyCount, 0)
  const coveredTotal = generated.reduce((sum, line) => sum + line.coveredRequiredDutyCount, 0)
  const uncoveredTotal = requiredTotal - coveredTotal
  const complete = uncoveredTotal === 0

  return (
    <div className="coverage-header">
      <div
        className={`coverage-header__badge ${complete ? 'coverage-header__badge--complete' : 'coverage-header__badge--incomplete'}`}
      >
        <Icon name={complete ? 'check' : 'alert'} size={20} strokeWidth={2} />
        <div>
          <p className="coverage-header__title">
            {complete ? 'Couverture complète' : 'Couverture incomplète'}
          </p>
          <p className="coverage-header__count tnum">
            {coveredTotal} / {requiredTotal} garde{requiredTotal > 1 ? 's' : ''} couverte
            {requiredTotal > 1 ? 's' : ''}
          </p>
        </div>
      </div>
      {!complete && (
        <p className="coverage-header__uncovered">
          {uncoveredTotal} garde{uncoveredTotal > 1 ? 's' : ''} non couverte{uncoveredTotal > 1 ? 's' : ''}
        </p>
      )}
      {lines.length > 1 &&
        generated.map((line) => (
          <p key={line.lineStableId} className="muted coverage-header__line">
            {line.lineName} : {line.coveredRequiredDutyCount} / {line.requiredDutyCount}
            {line.generatedAt && ` — générée le ${new Date(line.generatedAt).toLocaleDateString('fr-BE')}`}
          </p>
        ))}
      {notGenerated.map((line) => (
        <p key={line.lineStableId} className="muted coverage-header__line">
          {line.lineName} : aucune génération pour le moment.
        </p>
      ))}
    </div>
  )
}
