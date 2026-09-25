import { Link } from 'react-router-dom'
import { heroLines, links } from '../content'
import { Eyebrow } from './Eyebrow'
import { HeroCalendar } from './HeroCalendar'

export function Hero() {
  return (
    <section className="hp-wrap hp-hero">
      <div className="hp-hero__text">
        <Eyebrow tone="green">Planning de gardes médicales</Eyebrow>
        <h1 className="hp-h1">
          Le planning de gardes médicales, <span className="hp-h1__period">enfin simple.</span>
        </h1>
        <p className="hp-lead">
          Organisez les <strong>gardes et astreintes de votre équipe</strong>, centralisez les
          indisponibilités et construisez une répartition plus équitable, sans multiplier les fichiers Excel,
          les e-mails et les messages.
        </p>
        <ul className="hp-hero__lines">
          {heroLines.map((l) => (
            <li key={l.text}>
              <span className="hp-mark" data-tone={l.tone} aria-hidden />
              {l.text}
            </li>
          ))}
        </ul>
        <div className="hp-actions">
          <Link to={links.signup} className="hp-btn hp-btn--primary hp-btn--lg">
            Créer mon équipe
          </Link>
          <a href="#indisponibilites" className="hp-btn hp-btn--secondary hp-btn--lg">
            Découvrir MedVue
          </a>
        </div>
      </div>
      <div className="hp-hero__visual">
        <HeroCalendar />
      </div>
    </section>
  )
}
