import { spaces } from '../content'

export function Spaces() {
  return (
    <section className="hp-band">
      <div className="hp-wrap hp-section">
        <h2 className="hp-h2 hp-h2--narrow">Un espace pour chaque membre de l’équipe</h2>
        <div className="hp-spaces">
          {spaces.map((s) => (
            <div key={s.title} className="hp-spaces__item">
              <span className="hp-mark hp-mark--wide" data-tone={s.tone} aria-hidden />
              <h3 className="hp-h3">{s.title}</h3>
              <p>{s.body}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}
