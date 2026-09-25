import { responses, selectionDays, selectionPeriods } from '../content'
import { Eyebrow } from './Eyebrow'

export function Availability() {
  return (
    <section id="indisponibilites" className="hp-wrap hp-split hp-split--center">
      <div className="hp-split__a hp-prose">
        <Eyebrow tone="red">Indisponibilités</Eyebrow>
        <h2 className="hp-h2">Les indisponibilités, sans échanges d’e-mails</h2>
        <p>
          Chaque membre renseigne directement ses <strong>indisponibilités</strong> depuis son calendrier
          personnel, et peut y indiquer ses préférences de garde.
        </p>
        <p>
          Une date, plusieurs jours ou une période entière peuvent être sélectionnés facilement, sur
          ordinateur comme sur smartphone.
        </p>
        <p>
          Le responsable du planning peut suivre l’avancement des réponses et voir immédiatement qui doit
          encore compléter ses disponibilités.
        </p>
      </div>
      <div className="hp-split__b hp-stack">
        <div className="hp-card hp-card--pad">
          <div className="hp-card__head">
            <span className="hp-card__title">Mes indisponibilités</span>
            <span className="hp-card__meta hp-card__meta--red">8 jours · 3 sélections</span>
          </div>
          <div className="hp-grid7 hp-sel">
            {selectionDays.map((n, i) => {
              const p = selectionPeriods.find((r) => n >= r[0] && n <= r[1])
              return (
                <div
                  key={n}
                  className="hp-sel__day"
                  data-in={!!p}
                  data-start={p?.[0] === n}
                  data-end={p?.[1] === n}
                  data-off={i % 7 >= 5}
                >
                  <span>{n}</span>
                </div>
              )
            })}
          </div>
        </div>
        <div className="hp-card">
          <div className="hp-card__head hp-card__head--bar">
            <span className="hp-card__title">Réponses · période de novembre</span>
            <span className="hp-card__meta">7 / 9</span>
          </div>
          {responses.map((r) => (
            <div key={r.initials} className="hp-row">
              <span className="hp-avatar">{r.initials}</span>
              <span className="hp-row__name">{r.name}</span>
              <span className="hp-pill" data-tone={r.answered ? 'ok' : 'wait'}>
                {r.answered ? 'A répondu' : 'En attente'}
              </span>
            </div>
          ))}
          <div className="hp-row hp-row--foot">
            <span className="hp-row__note">2 membres n’ont pas encore répondu</span>
            <span className="hp-btn hp-btn--secondary hp-btn--xs">Relancer</span>
          </div>
        </div>
      </div>
    </section>
  )
}
