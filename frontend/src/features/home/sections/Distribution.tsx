import { useId, useState } from 'react'
import { NB, stats, statTabs, weekdays } from '../content'
import { Eyebrow } from './Eyebrow'

type StatTab = (typeof statTabs)[number]['id']

export function Distribution() {
  const [tab, setTab] = useState<StatTab>('periode')
  const tabsId = useId()
  const active = statTabs.find((t) => t.id === tab)!
  return (
    <section id="repartition" className="hp-band">
      <div className="hp-wrap hp-split">
        <div className="hp-split__a hp-prose">
          <Eyebrow tone="green">Répartition</Eyebrow>
          <h2 className="hp-h2">Voyez comment les gardes sont réellement réparties</h2>
          <p>Compter uniquement le nombre total de gardes ne suffit pas.</p>
          <p>
            MedVue permet de visualiser comment les gardes sont réellement réparties dans l’équipe et de
            suivre cette répartition dans la durée.
          </p>
          <p>
            Chaque garde affectée est comptée sur son vrai jour de la semaine. Un bloc qui couvre le samedi et
            le dimanche compte pour un samedi <strong>et</strong> pour un dimanche.
          </p>
          <p>
            Deux onglets{NB}: <strong>Cette période</strong> pour le planning en cours,{' '}
            <strong>Cumul du planning</strong> pour l’ensemble des périodes.
          </p>
        </div>
        <div className="hp-split__b hp-split__b--wide">
          <div className="hp-panel">
            <div className="hp-panel__head">
              <span className="hp-card__title">Statistiques · Anesthésie</span>
              <div className="hp-seg" role="tablist" aria-label="Période des statistiques">
                {statTabs.map((t) => (
                  <button
                    key={t.id}
                    type="button"
                    role="tab"
                    id={`${tabsId}-${t.id}`}
                    aria-selected={tab === t.id}
                    aria-controls={`${tabsId}-panel`}
                    className="hp-seg__btn"
                    onClick={() => setTab(t.id)}
                  >
                    {t.label}
                  </button>
                ))}
              </div>
            </div>
            <div
              className="hp-table-scroll"
              role="tabpanel"
              id={`${tabsId}-panel`}
              aria-labelledby={`${tabsId}-${tab}`}
            >
              <table className="hp-stats">
                <thead>
                  <tr>
                    <th scope="col">Membre</th>
                    {weekdays.map((d, i) => (
                      <th key={d} scope="col" data-off={i >= 5}>
                        {d}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {stats[tab].map(([name, vals]) => (
                    <tr key={name}>
                      <th scope="row">{name}</th>
                      {vals.map((v, i) => (
                        <td key={i} data-off={i >= 5} data-zero={v === 0}>
                          {v}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="hp-panel__note">{active.note}</div>
          </div>
        </div>
      </div>
    </section>
  )
}
