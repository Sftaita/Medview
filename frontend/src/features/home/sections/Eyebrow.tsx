export type Tone = 'red' | 'blue' | 'green' | 'ink' | 'amber' | 'light'

/** Section label: a short colored bar, the brand's "period" motif. */
export function Eyebrow({ tone, children }: { tone: Tone; children: string }) {
  return (
    <div className="hp-eyebrow" data-tone={tone}>
      <span className="hp-eyebrow__bar" aria-hidden />
      <span className="hp-eyebrow__label">{children}</span>
    </div>
  )
}
