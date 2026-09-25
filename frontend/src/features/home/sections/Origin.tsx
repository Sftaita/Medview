import { chores, NB } from '../content'

export function Origin() {
  return (
    <section className="hp-wrap hp-split">
      <div className="hp-split__a hp-prose">
        <h2 className="hp-h2">Pensé pour les équipes médicales</h2>
        <p>
          MedVue est né d’un problème très concret{NB}:{' '}
          <strong>le temps perdu à organiser les gardes d’un service médical.</strong>
        </p>
        <p>
          MedVue rassemble progressivement ces tâches dans un environnement unique conçu autour du
          fonctionnement réel des équipes médicales.
        </p>
      </div>
      <ol className="hp-split__b hp-chores">
        {chores.map((c, i) => (
          <li key={c}>
            <span>{String(i + 1).padStart(2, '0')}</span>
            {c}
          </li>
        ))}
      </ol>
    </section>
  )
}
