import { Link } from 'react-router-dom'
import { links } from '../content'

export function FinalCta() {
  return (
    <section className="hp-final">
      <div className="hp-final__inner">
        <div className="hp-final__text">
          <h2 className="hp-h2 hp-h2--light hp-h2--big">Simplifiez votre prochain planning de gardes.</h2>
          <p>
            Centralisez les disponibilités de votre équipe et construisez votre planning depuis un seul
            endroit.
          </p>
        </div>
        <div className="hp-actions">
          <Link to={links.signup} className="hp-btn hp-btn--white hp-btn--lg">
            Commencer avec MedVue
          </Link>
          {links.contact && (
            <a href={links.contact} className="hp-btn hp-btn--outline-light hp-btn--lg">
              Nous contacter
            </a>
          )}
        </div>
      </div>
    </section>
  )
}
