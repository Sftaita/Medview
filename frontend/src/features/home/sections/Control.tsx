import { Eyebrow } from './Eyebrow'

export function Control() {
  return (
    <section className="hp-dark">
      <div className="hp-wrap hp-split hp-split--dark">
        <div className="hp-split__a">
          <Eyebrow tone="light">Contrôle</Eyebrow>
          <h2 className="hp-h2 hp-h2--light">Un planning automatique doit rester contrôlable.</h2>
        </div>
        <div className="hp-split__b">
          <p className="hp-dark__lead">
            MedVue montre le résultat au responsable, signale les situations qui ne peuvent pas être
            complètement couvertes et lui permet d’effectuer les ajustements nécessaires.
          </p>
        </div>
      </div>
    </section>
  )
}
