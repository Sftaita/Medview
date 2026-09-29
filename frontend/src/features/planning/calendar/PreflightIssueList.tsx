import { Icon } from '../../../components/Icon'
import type { PreflightIssue } from './preflightIssues'

type Props = {
  issues: PreflightIssue[]
  /** "Voir dans le calendrier": the calendar goes to the duty and highlights it. */
  onLocate?: (dutyStableId: string) => void
  label?: string
}

/**
 * Every blocking issue of the current calendar, one per duty or block —
 * where it is, what is wrong, the rule it breaks and how to fix it
 * (docs/decisions.md D133). Never only a count.
 */
export function PreflightIssueList({ issues, onLocate, label = 'Points bloquants' }: Props) {
  if (issues.length === 0) return null
  return (
    <ul className="preflight-issues" aria-label={label}>
      {issues.map((issue) => (
        <li key={issue.key} className="alert alert--warning preflight-issue">
          <Icon name="alert" size={18} strokeWidth={2} />
          <div className="preflight-issue__body">
            <strong>{issue.where}</strong>
            <span>{issue.detail}</span>
            <span className="preflight-issue__rule">
              Règle : {issue.rule}. {issue.fix}
            </span>
          </div>
          {onLocate && issue.dutyStableId && (
            <button
              type="button"
              className="btn btn--sm btn--secondary preflight-issue__locate"
              onClick={() => onLocate(issue.dutyStableId as string)}
            >
              Voir dans le calendrier
            </button>
          )}
        </li>
      ))}
    </ul>
  )
}
