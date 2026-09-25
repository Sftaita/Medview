export function ManualEdit() {
  return (
    <section className="hp-band">
      <div className="hp-wrap hp-split hp-split--center hp-split--reverse">
        <div className="hp-split__a">
          <div className="hp-panel">
            <div className="hp-card">
              <div className="hp-card__head hp-card__head--bar">
                <span className="hp-kicker">Attribution modifiée</span>
              </div>
              <div className="hp-edit">
                <span className="hp-date hp-date--lg">
                  <b>21</b>
                  <small>nov.</small>
                </span>
                <span className="hp-edit__body">
                  <span className="hp-edit__line">Garde principale</span>
                  <span className="hp-edit__swap">
                    <s>Dr M. Léonard</s>
                    <i aria-hidden />
                    <strong>Dr S. Bastin</strong>
                  </span>
                </span>
              </div>
              <div className="hp-saved">
                <span aria-hidden />
                Modification enregistrée
              </div>
            </div>
          </div>
        </div>
        <div className="hp-split__b hp-prose">
          <h2 className="hp-h2">L’automatisation vous aide. Elle ne décide pas à votre place.</h2>
          <p>Un planning hospitalier comporte toujours des situations particulières.</p>
          <p>
            Le planning généré reste modifiable. Le responsable peut <strong>réaffecter une garde</strong>{' '}
            lorsque la réalité du service l’exige.
          </p>
          <p className="hp-quote">MedVue vous aide à construire le planning. Vous restez décisionnaire.</p>
        </div>
      </div>
    </section>
  )
}
