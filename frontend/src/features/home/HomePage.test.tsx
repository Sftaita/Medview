/// <reference types="node" />
import { fireEvent, render, screen, within } from '@testing-library/react'
import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { faq, NB } from './content'
import { HomePage } from './HomePage'

function renderHome() {
  return render(
    <MemoryRouter>
      <HomePage />
    </MemoryRouter>,
  )
}

describe('HomePage', () => {
  it('has a single h1 and sets the page title', () => {
    renderHome()
    const h1s = screen.getAllByRole('heading', { level: 1 })
    expect(h1s).toHaveLength(1)
    expect(h1s[0]).toHaveTextContent('Le planning de gardes médicales, enfin simple.')
    expect(document.title).toBe('MedVue | Logiciel de planning de gardes médicales')
  })

  it('sends visitors to the real login and registration routes', () => {
    renderHome()
    expect(screen.getByRole('link', { name: 'Connexion' })).toHaveAttribute('href', '/login')
    expect(screen.getByRole('link', { name: 'S’inscrire' })).toHaveAttribute('href', '/register')
    for (const name of ['Créer mon équipe', 'Commencer avec MedVue']) {
      for (const link of screen.getAllByRole('link', { name })) {
        expect(link).toHaveAttribute('href', '/register')
      }
    }
  })

  it('shows no link to pages that do not exist yet', () => {
    renderHome()
    for (const name of ['Nous contacter', 'Contact', 'Mentions légales', 'Confidentialité']) {
      expect(screen.queryByRole('link', { name })).not.toBeInTheDocument()
    }
    expect(screen.queryByRole('navigation', { name: 'Liens légaux' })).not.toBeInTheDocument()
  })

  it('points every in-page link at an existing section', () => {
    const { container } = renderHome()
    const anchors = [...container.querySelectorAll('a[href^="#"]')].map((a) => a.getAttribute('href')!)
    expect(anchors.length).toBeGreaterThan(0)
    for (const href of anchors) {
      expect(container.querySelector(href), href).not.toBeNull()
    }
  })

  it('switches the statistics between "Cette période" and "Cumul du planning"', () => {
    renderHome()
    const tabs = screen.getByRole('tablist', { name: 'Période des statistiques' })
    const period = within(tabs).getByRole('tab', { name: 'Cette période' })
    const cumul = within(tabs).getByRole('tab', { name: 'Cumul du planning' })
    const panel = screen.getByRole('tabpanel')

    expect(period).toHaveAttribute('aria-selected', 'true')
    expect(panel).toHaveAttribute('aria-labelledby', period.id)
    const hennebert = () => within(panel).getByRole('row', { name: /Dr C. Hennebert/ })
    expect(hennebert()).toHaveTextContent('1111101')

    fireEvent.click(cumul)
    expect(cumul).toHaveAttribute('aria-selected', 'true')
    expect(period).toHaveAttribute('aria-selected', 'false')
    expect(panel).toHaveAttribute('aria-labelledby', cumul.id)
    expect(hennebert()).toHaveTextContent('9898767')
    expect(screen.getByText(/Ensemble du planning/)).toBeInTheDocument()
  })

  it('opens one FAQ answer at a time, the first one by default', () => {
    renderHome()
    const questions = faq.map(([q]) => screen.getByRole('button', { name: q }))
    const answerOf = (b: HTMLElement) => document.getElementById(b.getAttribute('aria-controls')!)!

    expect(questions[0]).toHaveAttribute('aria-expanded', 'true')
    expect(answerOf(questions[0])).toBeVisible()
    expect(answerOf(questions[1])).not.toBeVisible()

    fireEvent.click(questions[1])
    expect(questions[1]).toHaveAttribute('aria-expanded', 'true')
    expect(answerOf(questions[1])).toBeVisible()
    expect(questions[0]).toHaveAttribute('aria-expanded', 'false')
    expect(answerOf(questions[0])).not.toBeVisible()

    fireEvent.click(questions[1])
    expect(questions.every((q) => q.getAttribute('aria-expanded') === 'false')).toBe(true)
  })

  it('keeps the thin non-breaking space before French punctuation', () => {
    renderHome()
    expect(
      screen.getByRole('heading', { name: `Plusieurs gardes${NB}? Plusieurs lignes de planning.` }),
    ).toBeInTheDocument()
    for (const [q] of faq) {
      expect(q.endsWith(`${NB}?`), q).toBe(true)
    }
  })

  it('never renders a duty exchange, notification or export feature (not in the product)', () => {
    const { container } = renderHome()
    expect(container.textContent).not.toMatch(/échange de garde|notification|export|synchronis/i)
  })
})

describe('home.css', () => {
  const dir = resolve(import.meta.dirname)
  const css = readFileSync(resolve(dir, 'home.css'), 'utf8')
  const sources = [
    readFileSync(resolve(dir, 'HomePage.tsx'), 'utf8'),
    ...readdirSync(resolve(dir, 'sections')).map((f) => readFileSync(resolve(dir, 'sections', f), 'utf8')),
  ].join('\n')

  it('defines every class the components use, all under the hp- namespace', () => {
    const used = new Set(
      [...sources.matchAll(/className=(?:"([^"]+)"|\{[^}]*\})/g)].flatMap((m) =>
        (
          m[1] ??
          m[0]
            .match(/'([^']+)'/g)
            ?.join(' ')
            .replace(/'/g, '') ??
          ''
        )
          .split(/\s+/)
          .filter(Boolean),
      ),
    )
    expect(used.size).toBeGreaterThan(50)
    for (const cls of used) {
      expect(cls === 'hp' || cls.startsWith('hp-'), cls).toBe(true)
      expect(css.includes(`.${cls}`), cls).toBe(true)
    }
  })

  it('only styles hp- selectors, so it cannot leak into the application', () => {
    const selectors = css
      .replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/\{[^{}]*\}/g, '{}')
      .replace(/@media[^{]*\{/g, '')
      .split('{}')
      .flatMap((s) => s.replace(/\}/g, '').split(','))
      .map((s) => s.trim())
      .filter(Boolean)
    for (const sel of selectors) {
      expect(sel, sel).toMatch(/^(\.hp\b|\.hp-|:where\(\.hp\)|html:has\(\.hp\))/)
    }
  })
})
