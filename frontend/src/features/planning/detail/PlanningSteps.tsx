export type DetailTab = 'lines' | 'availability' | 'planning'

export type Step = {
  title: string
  detail: string
  state: 'done' | 'current' | 'todo'
  tag: string
  tab: DetailTab
}

type Props = {
  steps: Step[]
  tab: DetailTab
  onSelect: (tab: DetailTab) => void
}

/** Équipe → Indisponibilités → Génération: where the planning stands; each step opens its tab. */
export function PlanningSteps({ steps, tab, onSelect }: Props) {
  return (
    <section className="pd-steps" aria-label="Étapes du planning">
      {steps.map((step, index) => (
        <button
          key={step.title}
          type="button"
          className="pd-step"
          data-state={step.state}
          data-selected={tab === step.tab || undefined}
          aria-current={tab === step.tab ? 'step' : undefined}
          onClick={() => onSelect(step.tab)}
        >
          <span className="pd-step-mark" aria-hidden>
            {step.state === 'done' ? '✓' : index + 1}
          </span>
          <span className="pd-step-body">
            <span className="pd-step-top">
              <span className="pd-step-title">{step.title}</span>
              <span className="pd-step-tag">{step.tag}</span>
            </span>
            <span className="pd-step-detail">{step.detail}</span>
          </span>
        </button>
      ))}
    </section>
  )
}
