import './home.css'
import { Availability } from './sections/Availability'
import { Burden } from './sections/Burden'
import { Control } from './sections/Control'
import { Distribution } from './sections/Distribution'
import { Duration } from './sections/Duration'
import { Faq } from './sections/Faq'
import { FinalCta } from './sections/FinalCta'
import { Footer } from './sections/Footer'
import { Generation } from './sections/Generation'
import { Header } from './sections/Header'
import { Hero } from './sections/Hero'
import { Lines } from './sections/Lines'
import { ManualEdit } from './sections/ManualEdit'
import { Origin } from './sections/Origin'
import { Spaces } from './sections/Spaces'
import { Start } from './sections/Start'

/**
 * Public homepage (docs/Design/react_homepage). Shows only what the
 * product actually does — read that folder's CLAUDE.md before adding a
 * section. Texts and sample data live in content.ts.
 */
export function HomePage() {
  return (
    <div className="hp">
      {/* React 19 hoists these into <head>. */}
      <title>MedVue | Logiciel de planning de gardes médicales</title>
      <meta
        name="description"
        content="Logiciel de planning de gardes médicales pour les équipes de médecins : collecte des indisponibilités, génération du planning, planning d’astreintes sur plusieurs lignes et suivi de la répartition."
      />
      <Header />
      <main id="top">
        <Hero />
        <Burden />
        <Availability />
        <Distribution />
        <Generation />
        <ManualEdit />
        <Lines />
        <Spaces />
        <Duration />
        <Control />
        <Origin />
        <Start />
        <Faq />
        <FinalCta />
      </main>
      <Footer />
    </div>
  )
}
