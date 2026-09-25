import type { PlanningResultCandidateReason } from './types'

type Props = {
  reasons: PlanningResultCandidateReason[]
}

/**
 * "NON COUVERTE" + "Pourquoi ?" (docs/decisions.md D130): only the real,
 * already-computed reasons — an empty list is shown as a neutral message,
 * never a fabricated cause (never turns "nobody chose them" into an
 * exclusion, D095).
 */
export function UncoveredDuty({ reasons }: Props) {
  return (
    <div className="uncovered-duty">
      <span className="tag tag--red">NON COUVERTE</span>
      {reasons.length > 0 ? (
        <details className="uncovered-duty__why">
          <summary>Pourquoi ?</summary>
          <ul className="uncovered-duty__reasons">
            {reasons.map((reason, index) => (
              <li key={index}>
                {reason.candidateFirstName} {reason.candidateLastName} : {reason.reasons.join(', ')}
              </li>
            ))}
          </ul>
        </details>
      ) : (
        <p className="muted uncovered-duty__unknown">Raison non disponible.</p>
      )}
    </div>
  )
}
