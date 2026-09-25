import { heroWeeks, holidays, weekdays } from '../content'

/** Illustrative November calendar: availability being collected, duties assigned. */
export function HeroCalendar() {
  return (
    <div className="hp-card hp-card--raised hp-hero-cal" aria-label="Exemple de planning de novembre">
      <div className="hp-hero-cal__head">
        <div>
          <div className="hp-kicker">Anesthésie · garde principale</div>
          <div className="hp-hero-cal__month">Novembre 2026</div>
        </div>
        <div className="hp-progress">
          <div className="hp-progress__track">
            <div className="hp-progress__fill" style={{ width: '78%' }} />
          </div>
          <span className="hp-progress__label">7 / 9 réponses</span>
        </div>
      </div>
      <div className="hp-grid7 hp-hero-cal__wd">
        {weekdays.map((d) => (
          <span key={d}>{d}</span>
        ))}
      </div>
      <div className="hp-hero-cal__weeks">
        {heroWeeks.map((w) => (
          <div key={w.start} className="hp-hero-cal__week">
            <div className="hp-grid7 hp-hero-cal__cells">
              {weekdays.map((_, i) => {
                const n = w.start + i
                const off = i >= 5 || holidays.includes(n)
                return (
                  <div key={n} className="hp-hero-cal__cell" data-off={off}>
                    <span>{n}</span>
                  </div>
                )
              })}
            </div>
            <div className="hp-grid7 hp-hero-cal__bars" aria-hidden>
              {w.bars.map((b, k) => (
                <div
                  key={k}
                  className="hp-hero-cal__bar"
                  data-kind={b.kind}
                  style={{ gridColumn: `${b.from} / ${b.to + 1}` }}
                >
                  <span className="hp-hero-cal__who">{b.who}</span>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
      <div className="hp-legend">
        <span>
          <i className="hp-legend__sw hp-legend__sw--garde" />
          Garde attribuée
        </span>
        <span>
          <i className="hp-legend__sw hp-legend__sw--indispo" />
          Indisponible
        </span>
        <span>
          <i className="hp-legend__sw hp-legend__sw--off" />
          Week-end · férié
        </span>
      </div>
    </div>
  )
}
