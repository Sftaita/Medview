import { useId, type ReactNode } from 'react'

export interface PeriodTab<T extends string> {
  id: T
  label: string
  /** Shorter label when the tab bar is narrow. */
  short?: string
  /** Shorter still, only where the short label has no room (a phone). */
  narrow?: string
  count?: number
}

interface Props<T extends string> {
  tabs: PeriodTab<T>[]
  selected: T
  onSelect: (id: T) => void
  /** "Période 2 · 1 mai → 31 août 2027": which period the content belongs to. */
  context?: string
  children: ReactNode
}

/**
 * The tab bar of docs/Design/medvue-periodes around the real content of the
 * selected period — lines, availability, calendar (docs/planning-cycles.md §8:
 * the mock's own fake calendar and answers are replaced by the existing
 * screens, only its look is kept).
 */
export function PeriodTabs<T extends string>({ tabs, selected, onSelect, context, children }: Props<T>) {
  const id = useId()
  return (
    <section className="mv-card mv-tabs-card">
      <div className="mv-tabs" role="tablist" aria-label="Contenu de la période">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            type="button"
            role="tab"
            id={`${id}-${tab.id}`}
            aria-selected={selected === tab.id}
            aria-controls={`${id}-panel`}
            aria-label={tab.count !== undefined ? `${tab.label} (${tab.count})` : tab.label}
            className={selected === tab.id ? 'is-selected' : ''}
            onClick={() => onSelect(tab.id)}
          >
            <span className="mv-tabs__long" aria-hidden="true">
              {tab.label}
            </span>
            <span className="mv-tabs__short" aria-hidden="true">
              {tab.short ?? tab.label}
            </span>
            <span className="mv-tabs__narrow" aria-hidden="true">
              {tab.narrow ?? tab.short ?? tab.label}
            </span>
            {tab.count !== undefined && (
              <span className="mv-count" aria-hidden="true">
                {tab.count}
              </span>
            )}
          </button>
        ))}
        {context && <span className="mv-tabs__context mv-muted">{context}</span>}
      </div>
      <div
        className="mv-tabs__panel"
        role="tabpanel"
        id={`${id}-panel`}
        aria-labelledby={`${id}-${selected}`}
      >
        {children}
      </div>
    </section>
  )
}
