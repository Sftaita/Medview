import { links } from '../content'

const legalLinks = [
  { href: links.contact, label: 'Contact' },
  { href: links.legal, label: 'Mentions légales' },
  { href: links.privacy, label: 'Confidentialité' },
].filter((l): l is { href: string; label: string } => l.href !== null)

export function Footer() {
  return (
    <footer className="hp-footer">
      <div className="hp-wrap hp-footer__inner">
        <div className="hp-brand hp-brand--sm">
          <img src="/logo/mark.svg" alt="" width={24} height={24} />
          <span className="hp-brand__word">MedVue</span>
          <span className="hp-footer__copy">© 2026</span>
        </div>
        {legalLinks.length > 0 && (
          <nav className="hp-footer__nav" aria-label="Liens légaux">
            {legalLinks.map((l) => (
              <a key={l.label} href={l.href}>
                {l.label}
              </a>
            ))}
          </nav>
        )}
      </div>
    </footer>
  )
}
