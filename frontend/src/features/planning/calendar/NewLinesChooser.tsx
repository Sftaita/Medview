import type { PublicationLineBlocker, PublicationLineReadiness } from '../result/types'

const BLOCKERS: Record<PublicationLineBlocker, string> = {
  NOT_GENERATED: 'pas encore générée',
  INCOMPLETE: 'configuration incomplète',
  UNCOVERED_DUTIES: 'des gardes obligatoires ne sont pas couvertes',
  INCONSISTENT_GROUPS: 'un bloc est réparti entre plusieurs personnes',
  INVALID_ASSIGNMENTS: 'des affectations ne sont plus valides',
  CONFLICTS: 'des affectations sont en conflit',
  UNDETERMINED_REINFORCEMENTS: 'des renforts ne peuvent pas être évalués',
}

type Props = {
  /** The lines of the period never published yet, ready or not. */
  lines: PublicationLineReadiness[]
  /** The lines already published in the period — a reinforcement line may follow one of them. */
  publishedIds: ReadonlySet<string>
  selected: ReadonlySet<string>
  onChange: (selected: Set<string>) => void
  legend: string
  disabled?: boolean
}

/**
 * Lines to publish for the first time (docs/decisions.md D196), always
 * chosen explicitly: every box starts unchecked, a line that is not ready
 * says why and cannot be chosen, and a reinforcement line can only go out
 * with, or after, the line it reinforces.
 */
export function NewLinesChooser({
  lines,
  publishedIds,
  selected,
  onChange,
  legend,
  disabled = false,
}: Props) {
  if (lines.length === 0) return null
  const names = new Map(lines.map((line) => [line.lineStableId, line.lineName]))

  function sourceMissing(line: PublicationLineReadiness, chosen: ReadonlySet<string>): boolean {
    const source = line.sourceLineStableId
    return !!source && !publishedIds.has(source) && !chosen.has(source)
  }

  function toggle(line: PublicationLineReadiness, checked: boolean) {
    const next = new Set(selected)
    if (checked) next.add(line.lineStableId)
    else next.delete(line.lineStableId)
    // A reinforcement whose source is no longer chosen goes with it — never published before it.
    for (const other of lines) {
      if (next.has(other.lineStableId) && sourceMissing(other, next)) next.delete(other.lineStableId)
    }
    onChange(next)
  }

  return (
    <fieldset className="new-lines">
      <legend className="preflight-heading">{legend}</legend>
      <ul className="preflight-issues">
        {lines.map((line) => {
          const ready = line.firstPublishable === true
          const waitsForSource = ready && sourceMissing(line, selected)
          const reason = !ready
            ? (line.blockers ?? []).map((code) => BLOCKERS[code] ?? code).join(', ') || 'pas prête'
            : waitsForSource
              ? `se publie avec ou après « ${names.get(line.sourceLineStableId ?? '') ?? 'sa ligne source'} »`
              : null
          return (
            <li key={line.lineStableId}>
              <label>
                <input
                  type="checkbox"
                  checked={selected.has(line.lineStableId)}
                  disabled={disabled || !ready || waitsForSource}
                  onChange={(event) => toggle(line, event.target.checked)}
                />{' '}
                <strong>{line.lineName}</strong>
                {reason && <span className="muted"> — {reason}</span>}
              </label>
            </li>
          )
        })}
      </ul>
    </fieldset>
  )
}
