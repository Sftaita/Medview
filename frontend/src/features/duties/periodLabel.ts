/**
 * « Période 2 » for a duty or a swap unit of a planning with several periods;
 * nothing for a planning with a single one, which stays as simple as before
 * (lot L7, docs/decisions.md D198). `count` is the planning's period count;
 * an older answer without it names only a period after the first.
 */
export function periodLabel(period: { number: number; count?: number } | null | undefined): string | null {
  if (!period) return null
  const several = period.count !== undefined ? period.count > 1 : period.number > 1
  return several ? `Période ${period.number}` : null
}
