import { engineSteps, uncovered } from '../content'
import { Eyebrow } from './Eyebrow'

export function Generation() {
  return (
    <section className="hp-wrap hp-section">
      <div className="hp-prose hp-prose--narrow">
        <Eyebrow tone="blue">Génération</Eyebrow>
        <h2 className="hp-h2">Générez votre planning en tenant compte des vraies contraintes</h2>
        <p>
          Une fois les indisponibilités recueillies, MedVue recherche une solution{' '}
          <strong>à l’échelle de l’ensemble du planning</strong> plutôt que d’attribuer les gardes une par
          une.
        </p>
        <p>
          La génération tient compte notamment des membres éligibles, des indisponibilités, des conflits de
          garde, des contraintes de repos configurées et des règles d’équité du moteur.
        </p>
      </div>
      <div className="hp-tiles3">
        {engineSteps.map((s) => (
          <div key={s.n} className="hp-tile" data-tone={s.tone}>
            <span className="hp-tile__n">{s.n}</span>
            <div className="hp-tile__title">{s.title}</div>
            <div className="hp-tile__body">{s.body}</div>
          </div>
        ))}
      </div>
      <div className="hp-callout">
        <div className="hp-callout__text hp-prose">
          <h3 className="hp-h3">Si tout ne peut pas être couvert, vous le voyez.</h3>
          <p>
            Lorsqu’aucune solution complète n’existe, MedVue <strong>ne masque pas le problème</strong>. Il
            produit une solution partielle et affiche clairement les gardes qui restent à couvrir.
          </p>
        </div>
        <div className="hp-card hp-result">
          <div className="hp-result__head">
            <span className="hp-card__title">Résultat de la génération</span>
            <span className="hp-pill hp-pill--card" data-tone="wait">
              Couverture incomplète
            </span>
          </div>
          {uncovered.map((u) => (
            <div key={u.num} className="hp-row">
              <span className="hp-date">
                <b>{u.num}</b>
                <small>{u.wd}</small>
              </span>
              <span className="hp-row__name">{u.line}</span>
              <span className="hp-pill" data-tone="wait">
                Non couverte
              </span>
            </div>
          ))}
          <div className="hp-row hp-row--note">Les autres gardes de la période sont attribuées.</div>
        </div>
      </div>
    </section>
  )
}
