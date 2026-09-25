import { Link } from 'react-router-dom'
import { links } from '../content'

export function Header() {
  return (
    <header className="hp-header">
      <div className="hp-header__inner">
        <a href="#top" className="hp-brand" aria-label="MedVue, accueil">
          <img src="/logo/mark.svg" alt="" width={30} height={30} />
          <span className="hp-brand__word">MedVue</span>
        </a>
        <nav className="hp-header__nav" aria-label="Sections">
          <a href="#indisponibilites">Indisponibilités</a>
          <a href="#repartition">Répartition</a>
          <a href="#lignes">Lignes de planning</a>
          <a href="#faq">Questions</a>
        </nav>
        <span className="hp-header__spacer" />
        <div className="hp-header__actions">
          <Link to={links.login} className="hp-btn hp-btn--secondary hp-btn--sm">
            Connexion
          </Link>
          <Link to={links.signup} className="hp-btn hp-btn--primary hp-btn--sm">
            S’inscrire
          </Link>
        </div>
      </div>
    </header>
  )
}
