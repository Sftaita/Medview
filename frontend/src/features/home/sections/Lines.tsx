import { lineHeads, NB, planningLines } from '../content'
import { Eyebrow } from './Eyebrow'

export function Lines() {
  return (
    <section id="lignes" className="hp-wrap hp-section">
      <div className="hp-split hp-split--end">
        <div className="hp-split__a">
          <Eyebrow tone="ink">Lignes de planning</Eyebrow>
          <h2 className="hp-h2">Plusieurs gardes{NB}? Plusieurs lignes de planning.</h2>
        </div>
        <div className="hp-split__b hp-prose">
          <p>
            Votre organisation ne se limite pas forcément à une seule garde. Un planning MedVue peut comporter
            plusieurs lignes — garde principale, garde secondaire, planning d’astreintes ou toute autre
            activité.
          </p>
          <p>
            Chaque équipe nomme et configure ses propres lignes. Vous reproduisez le fonctionnement réel de
            votre service au lieu d’adapter votre organisation au logiciel.
          </p>
        </div>
      </div>
      <div className="hp-card hp-lines hp-table-scroll">
        <table className="hp-lines__table">
          <thead>
            <tr>
              <th scope="col">Semaine du 16 nov.</th>
              {lineHeads.map((h, i) => (
                <th key={h} scope="col" data-off={i >= 5}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {planningLines.map((l) => (
              <tr key={l.name} data-tone={l.tone}>
                <th scope="row">
                  <span className="hp-lines__dot" aria-hidden />
                  {l.name}
                </th>
                {l.cells.map((who, i) => {
                  // Consecutive days of the same member are drawn as one joined block.
                  const prev = i > 0 && who && l.cells[i - 1] === who
                  const next = i < 6 && who && l.cells[i + 1] === who
                  return (
                    <td key={i} data-off={i >= 5} data-prev={!!prev} data-next={!!next}>
                      <span className={who ? 'hp-lines__slot' : 'hp-lines__slot hp-lines__slot--empty'}>
                        {who && !prev ? who : ''}
                      </span>
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="hp-caption">
        Exemple d’un planning à quatre lignes. Le nombre de lignes et leurs noms sont définis par l’équipe.
      </p>
    </section>
  )
}
