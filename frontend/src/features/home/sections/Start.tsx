import { Link } from 'react-router-dom'
import { links, steps } from '../content'

export function Start() {
  return (
    <section id="commencer" className="hp-band">
      <div className="hp-wrap hp-section">
        <h2 className="hp-h2">Commencez par votre prochain planning</h2>
        <ol className="hp-steps">
          {steps.map((s, i) => (
            <li key={s} className="hp-steps__item" data-last={i === steps.length - 1}>
              <div className="hp-steps__bar">
                <span>{i + 1}</span>
              </div>
              <div className="hp-steps__text">{s}</div>
            </li>
          ))}
        </ol>
        <Link to={links.signup} className="hp-btn hp-btn--primary hp-btn--lg">
          Créer mon équipe
        </Link>
      </div>
    </section>
  )
}
