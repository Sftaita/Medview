import { useId, useState } from 'react'
import { faq } from '../content'

export function Faq() {
  // One answer open at a time; the first one is open on arrival.
  const [open, setOpen] = useState(0)
  const base = useId()
  return (
    <section id="faq" className="hp-wrap hp-faq">
      <h2 className="hp-h2">Questions fréquentes</h2>
      <div className="hp-faq__list">
        {faq.map(([q, a], i) => {
          const isOpen = open === i
          return (
            <div key={q} className="hp-faq__item">
              <h3 className="hp-faq__q">
                <button
                  type="button"
                  aria-expanded={isOpen}
                  aria-controls={`${base}-${i}`}
                  onClick={() => setOpen(isOpen ? -1 : i)}
                >
                  <span>{q}</span>
                  <span className="hp-faq__icon" data-open={isOpen} aria-hidden />
                </button>
              </h3>
              <p id={`${base}-${i}`} className="hp-faq__a" hidden={!isOpen}>
                {a}
              </p>
            </div>
          )
        })}
      </div>
    </section>
  )
}
