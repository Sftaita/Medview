/** A status of the swap workflow, as a pill (docs/duty-swaps.md §9). */
export function SwapStatusPill({
  label,
  tone,
}: {
  label: string
  tone: 'info' | 'live' | 'done' | 'warn' | 'muted'
}) {
  return (
    <span className={`db-pill swap-pill swap-pill--${tone}`}>
      <span className="db-dot" aria-hidden="true" />
      {label}
    </span>
  )
}
