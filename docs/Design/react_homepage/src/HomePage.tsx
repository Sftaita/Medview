import { useId, useState } from 'react';
import {
  NB, links, heroLines, heroWeeks, holidays, selectionDays, selectionPeriods, responses, weekdays,
  stats, statTabs, engineSteps, uncovered, planningLines, lineHeads, spaces, chores, steps, faq,
} from './content';

type StatTab = (typeof statTabs)[number]['id'];

function Eyebrow({ tone, children }: { tone: string; children: string }) {
  return (
    <div className="eyebrow" data-tone={tone}>
      <span className="eyebrow__bar" aria-hidden />
      <span className="eyebrow__label">{children}</span>
    </div>
  );
}

/* ------------------------------------------------------------------ */

function Header() {
  return (
    <header className="header">
      <div className="header__inner">
        <a href="#top" className="brand" aria-label="MedVue, accueil">
          <img src="/logo/mark.svg" alt="" width={30} height={30} />
          <span className="brand__word">MedVue</span>
        </a>
        <nav className="header__nav" aria-label="Sections">
          <a href="#indisponibilites">Indisponibilités</a>
          <a href="#repartition">Répartition</a>
          <a href="#lignes">Lignes de planning</a>
          <a href="#faq">Questions</a>
        </nav>
        <span className="header__spacer" />
        <div className="header__actions">
          <a href={links.login} className="btn btn--secondary btn--sm">Connexion</a>
          <a href={links.signup} className="btn btn--primary btn--sm">S’inscrire</a>
        </div>
      </div>
    </header>
  );
}

function HeroCalendar() {
  return (
    <div className="card card--raised hero-cal" aria-label="Exemple de planning de novembre">
      <div className="hero-cal__head">
        <div>
          <div className="kicker">Anesthésie · garde principale</div>
          <div className="hero-cal__month">Novembre 2026</div>
        </div>
        <div className="progress">
          <div className="progress__track"><div className="progress__fill" style={{ width: '78%' }} /></div>
          <span className="progress__label">7 / 9 réponses</span>
        </div>
      </div>
      <div className="grid7 hero-cal__wd">{weekdays.map(d => <span key={d}>{d}</span>)}</div>
      <div className="hero-cal__weeks">
        {heroWeeks.map(w => (
          <div key={w.start} className="hero-cal__week">
            <div className="grid7 hero-cal__cells">
              {weekdays.map((_, i) => {
                const n = w.start + i;
                const off = i >= 5 || holidays.includes(n);
                return <div key={n} className="hero-cal__cell" data-off={off}><span>{n}</span></div>;
              })}
            </div>
            <div className="grid7 hero-cal__bars" aria-hidden>
              {w.bars.map((b, k) => (
                <div key={k} className="hero-cal__bar" data-kind={b.kind} style={{ gridColumn: `${b.from} / ${b.to + 1}` }}>
                  <span className="hero-cal__who">{b.who}</span>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
      <div className="legend">
        <span><i className="legend__sw legend__sw--garde" />Garde attribuée</span>
        <span><i className="legend__sw legend__sw--indispo" />Indisponible</span>
        <span><i className="legend__sw legend__sw--off" />Week-end · férié</span>
      </div>
    </div>
  );
}

function Hero() {
  return (
    <section className="wrap hero">
      <div className="hero__text">
        <Eyebrow tone="green">Planning de gardes médicales</Eyebrow>
        <h1 className="h1">Le planning de gardes médicales, <span className="h1__period">enfin simple.</span></h1>
        <p className="lead">
          Organisez les <strong>gardes et astreintes de votre équipe</strong>, centralisez les indisponibilités et construisez une répartition plus équitable, sans multiplier les fichiers Excel, les e-mails et les messages.
        </p>
        <ul className="hero__lines">
          {heroLines.map(l => (
            <li key={l.text}><span className="mark" data-tone={l.tone} aria-hidden />{l.text}</li>
          ))}
        </ul>
        <div className="actions">
          <a href={links.signup} className="btn btn--primary btn--lg">Créer mon équipe</a>
          <a href="#indisponibilites" className="btn btn--secondary btn--lg">Découvrir MedVue</a>
        </div>
      </div>
      <div className="hero__visual"><HeroCalendar /></div>
    </section>
  );
}

function Burden() {
  return (
    <section className="band">
      <div className="wrap split split--tight">
        <div className="split__a">
          <h2 className="h2">Moins de temps à faire le planning. Plus de visibilité pour toute l’équipe.</h2>
        </div>
        <div className="split__b prose">
          <p>Organiser les gardes d’un service devient rapidement complexe.</p>
          <p>Il faut recueillir les indisponibilités, relancer ceux qui n’ont pas répondu, respecter les règles de repos, gérer plusieurs gardes en parallèle et garder un œil sur la répartition dans la durée.</p>
          <p>MedVue rassemble la collecte des indisponibilités, la génération et le suivi du planning dans un seul outil.</p>
          <div className="period-chain">
            <span>Un calendrier.</span><span>Une équipe.</span><span>Un planning partagé.</span>
          </div>
        </div>
      </div>
    </section>
  );
}

function Availability() {
  return (
    <section id="indisponibilites" className="wrap split split--center">
      <div className="split__a prose">
        <Eyebrow tone="red">Indisponibilités</Eyebrow>
        <h2 className="h2">Les indisponibilités, sans échanges d’e-mails</h2>
        <p>Chaque membre renseigne directement ses <strong>indisponibilités</strong> depuis son calendrier personnel, et peut y indiquer ses préférences de garde.</p>
        <p>Une date, plusieurs jours ou une période entière peuvent être sélectionnés facilement, sur ordinateur comme sur smartphone.</p>
        <p>Le responsable du planning peut suivre l’avancement des réponses et voir immédiatement qui doit encore compléter ses disponibilités.</p>
      </div>
      <div className="split__b stack">
        <div className="card card--pad">
          <div className="card__head">
            <span className="card__title">Mes indisponibilités</span>
            <span className="card__meta card__meta--red">8 jours · 3 sélections</span>
          </div>
          <div className="grid7 sel">
            {selectionDays.map((n, i) => {
              const p = selectionPeriods.find(r => n >= r[0] && n <= r[1]);
              return (
                <div key={n} className="sel__day" data-in={!!p} data-start={p?.[0] === n} data-end={p?.[1] === n} data-off={i % 7 >= 5}>
                  <span>{n}</span>
                </div>
              );
            })}
          </div>
        </div>
        <div className="card">
          <div className="card__head card__head--bar">
            <span className="card__title">Réponses · période de novembre</span>
            <span className="card__meta">7 / 9</span>
          </div>
          {responses.map(r => (
            <div key={r.initials} className="row">
              <span className="avatar">{r.initials}</span>
              <span className="row__name">{r.name}</span>
              <span className="pill" data-tone={r.answered ? 'ok' : 'wait'}>{r.answered ? 'A répondu' : 'En attente'}</span>
            </div>
          ))}
          <div className="row row--foot">
            <span className="row__note">2 membres n’ont pas encore répondu</span>
            <span className="btn btn--secondary btn--xs">Relancer</span>
          </div>
        </div>
      </div>
    </section>
  );
}

function Distribution() {
  const [tab, setTab] = useState<StatTab>('periode');
  const tabsId = useId();
  const active = statTabs.find(t => t.id === tab)!;
  return (
    <section id="repartition" className="band">
      <div className="wrap split">
        <div className="split__a prose">
          <Eyebrow tone="green">Répartition</Eyebrow>
          <h2 className="h2">Voyez comment les gardes sont réellement réparties</h2>
          <p>Compter uniquement le nombre total de gardes ne suffit pas.</p>
          <p>MedVue permet de visualiser comment les gardes sont réellement réparties dans l’équipe et de suivre cette répartition dans la durée.</p>
          <p>Chaque garde affectée est comptée sur son vrai jour de la semaine. Un bloc qui couvre le samedi et le dimanche compte pour un samedi <strong>et</strong> pour un dimanche.</p>
          <p>Deux onglets{NB}: <strong>Cette période</strong> pour le planning en cours, <strong>Cumul du planning</strong> pour l’ensemble des périodes.</p>
        </div>
        <div className="split__b split__b--wide">
          <div className="panel">
            <div className="panel__head">
              <span className="card__title">Statistiques · Anesthésie</span>
              <div className="seg" role="tablist" aria-label="Période des statistiques">
                {statTabs.map(t => (
                  <button key={t.id} type="button" role="tab" id={`${tabsId}-${t.id}`} aria-selected={tab === t.id}
                    aria-controls={`${tabsId}-panel`} className="seg__btn" onClick={() => setTab(t.id)}>{t.label}</button>
                ))}
              </div>
            </div>
            <div className="table-scroll" role="tabpanel" id={`${tabsId}-panel`} aria-labelledby={`${tabsId}-${tab}`}>
              <table className="stats">
                <thead>
                  <tr><th scope="col">Membre</th>{weekdays.map((d, i) => <th key={d} scope="col" data-off={i >= 5}>{d}</th>)}</tr>
                </thead>
                <tbody>
                  {stats[tab].map(([name, vals]) => (
                    <tr key={name}>
                      <th scope="row">{name}</th>
                      {vals.map((v, i) => <td key={i} data-off={i >= 5} data-zero={v === 0}>{v}</td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="panel__note">{active.note}</div>
          </div>
        </div>
      </div>
    </section>
  );
}

function Generation() {
  return (
    <section className="wrap section">
      <div className="prose prose--narrow">
        <Eyebrow tone="blue">Génération</Eyebrow>
        <h2 className="h2">Générez votre planning en tenant compte des vraies contraintes</h2>
        <p>Une fois les indisponibilités recueillies, MedVue recherche une solution <strong>à l’échelle de l’ensemble du planning</strong> plutôt que d’attribuer les gardes une par une.</p>
        <p>La génération tient compte notamment des membres éligibles, des indisponibilités, des conflits de garde, des contraintes de repos configurées et des règles d’équité du moteur.</p>
      </div>
      <div className="tiles3">
        {engineSteps.map(s => (
          <div key={s.n} className="tile" data-tone={s.tone}>
            <span className="tile__n">{s.n}</span>
            <div className="tile__title">{s.title}</div>
            <div className="tile__body">{s.body}</div>
          </div>
        ))}
      </div>
      <div className="callout">
        <div className="callout__text prose">
          <h3 className="h3">Si tout ne peut pas être couvert, vous le voyez.</h3>
          <p>Lorsqu’aucune solution complète n’existe, MedVue <strong>ne masque pas le problème</strong>. Il produit une solution partielle et affiche clairement les gardes qui restent à couvrir.</p>
        </div>
        <div className="card result">
          <div className="result__head">
            <span className="card__title">Résultat de la génération</span>
            <span className="pill pill--card" data-tone="wait">Couverture incomplète</span>
          </div>
          {uncovered.map(u => (
            <div key={u.num} className="row">
              <span className="date"><b>{u.num}</b><small>{u.wd}</small></span>
              <span className="row__name">{u.line}</span>
              <span className="pill" data-tone="wait">Non couverte</span>
            </div>
          ))}
          <div className="row row--note">Les autres gardes de la période sont attribuées.</div>
        </div>
      </div>
    </section>
  );
}

function ManualEdit() {
  return (
    <section className="band">
      <div className="wrap split split--center split--reverse">
        <div className="split__a">
          <div className="panel">
            <div className="card">
              <div className="card__head card__head--bar"><span className="kicker">Attribution modifiée</span></div>
              <div className="edit">
                <span className="date date--lg"><b>21</b><small>nov.</small></span>
                <span className="edit__body">
                  <span className="edit__line">Garde principale</span>
                  <span className="edit__swap">
                    <s>Dr M. Léonard</s>
                    <i aria-hidden />
                    <strong>Dr S. Bastin</strong>
                  </span>
                </span>
              </div>
              <div className="saved"><span aria-hidden />Modification enregistrée</div>
            </div>
          </div>
        </div>
        <div className="split__b prose">
          <h2 className="h2">L’automatisation vous aide. Elle ne décide pas à votre place.</h2>
          <p>Un planning hospitalier comporte toujours des situations particulières.</p>
          <p>Le planning généré reste modifiable. Le responsable peut <strong>réaffecter une garde</strong> lorsque la réalité du service l’exige.</p>
          <p className="quote">MedVue vous aide à construire le planning. Vous restez décisionnaire.</p>
        </div>
      </div>
    </section>
  );
}

function Lines() {
  return (
    <section id="lignes" className="wrap section">
      <div className="split split--end">
        <div className="split__a">
          <Eyebrow tone="ink">Lignes de planning</Eyebrow>
          <h2 className="h2">Plusieurs gardes{NB}? Plusieurs lignes de planning.</h2>
        </div>
        <div className="split__b prose">
          <p>Votre organisation ne se limite pas forcément à une seule garde. Un planning MedVue peut comporter plusieurs lignes — garde principale, garde secondaire, planning d’astreintes ou toute autre activité.</p>
          <p>Chaque équipe nomme et configure ses propres lignes. Vous reproduisez le fonctionnement réel de votre service au lieu d’adapter votre organisation au logiciel.</p>
        </div>
      </div>
      <div className="card lines table-scroll">
        <table className="lines__table">
          <thead>
            <tr><th scope="col">Semaine du 16 nov.</th>{lineHeads.map((h, i) => <th key={h} scope="col" data-off={i >= 5}>{h}</th>)}</tr>
          </thead>
          <tbody>
            {planningLines.map(l => (
              <tr key={l.name} data-tone={l.tone}>
                <th scope="row"><span className="lines__dot" aria-hidden />{l.name}</th>
                {l.cells.map((who, i) => {
                  const prev = i > 0 && who && l.cells[i - 1] === who;
                  const next = i < 6 && who && l.cells[i + 1] === who;
                  return (
                    <td key={i} data-off={i >= 5} data-prev={!!prev} data-next={!!next}>
                      <span className={who ? 'lines__slot' : 'lines__slot lines__slot--empty'}>{who && !prev ? who : ''}</span>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="caption">Exemple d’un planning à quatre lignes. Le nombre de lignes et leurs noms sont définis par l’équipe.</p>
    </section>
  );
}

function Spaces() {
  return (
    <section className="band">
      <div className="wrap section">
        <h2 className="h2 h2--narrow">Un espace pour chaque membre de l’équipe</h2>
        <div className="spaces">
          {spaces.map(s => (
            <div key={s.title} className="spaces__item">
              <span className="mark mark--wide" data-tone={s.tone} aria-hidden />
              <h3 className="h3">{s.title}</h3>
              <p>{s.body}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function Duration() {
  return (
    <section className="wrap split split--tight">
      <div className="split__a"><h2 className="h2">L’équité se construit dans la durée</h2></div>
      <div className="split__b prose">
        <p>Une équipe évolue.</p>
        <p>MedVue conserve le contexte nécessaire pour que la répartition puisse être suivie dans la durée, au-delà d’une seule période.</p>
        <p>L’onglet <strong>Cumul du planning</strong> est calculé à partir des affectations réelles de l’ensemble du planning.</p>
      </div>
    </section>
  );
}

function Control() {
  return (
    <section className="dark">
      <div className="wrap split split--dark">
        <div className="split__a">
          <Eyebrow tone="light">Contrôle</Eyebrow>
          <h2 className="h2 h2--light">Un planning automatique doit rester contrôlable.</h2>
        </div>
        <div className="split__b">
          <p className="dark__lead">MedVue montre le résultat au responsable, signale les situations qui ne peuvent pas être complètement couvertes et lui permet d’effectuer les ajustements nécessaires.</p>
        </div>
      </div>
    </section>
  );
}

function Origin() {
  return (
    <section className="wrap split">
      <div className="split__a prose">
        <h2 className="h2">Pensé pour les équipes médicales</h2>
        <p>MedVue est né d’un problème très concret{NB}: <strong>le temps perdu à organiser les gardes d’un service médical.</strong></p>
        <p>MedVue rassemble progressivement ces tâches dans un environnement unique conçu autour du fonctionnement réel des équipes médicales.</p>
      </div>
      <ol className="split__b chores">
        {chores.map((c, i) => <li key={c}><span>{String(i + 1).padStart(2, '0')}</span>{c}</li>)}
      </ol>
    </section>
  );
}

function Start() {
  return (
    <section id="commencer" className="band">
      <div className="wrap section">
        <h2 className="h2">Commencez par votre prochain planning</h2>
        <ol className="steps">
          {steps.map((s, i) => (
            <li key={s} className="steps__item" data-last={i === steps.length - 1}>
              <div className="steps__bar"><span>{i + 1}</span></div>
              <div className="steps__text">{s}</div>
            </li>
          ))}
        </ol>
        <a href={links.signup} className="btn btn--primary btn--lg">Créer mon équipe</a>
      </div>
    </section>
  );
}

function Faq() {
  const [open, setOpen] = useState(0);
  const base = useId();
  return (
    <section id="faq" className="wrap faq">
      <h2 className="h2">Questions fréquentes</h2>
      <div className="faq__list">
        {faq.map(([q, a], i) => {
          const isOpen = open === i;
          return (
            <div key={q} className="faq__item">
              <h3 className="faq__q">
                <button type="button" aria-expanded={isOpen} aria-controls={`${base}-${i}`} onClick={() => setOpen(isOpen ? -1 : i)}>
                  <span>{q}</span>
                  <span className="faq__icon" data-open={isOpen} aria-hidden />
                </button>
              </h3>
              <p id={`${base}-${i}`} className="faq__a" hidden={!isOpen}>{a}</p>
            </div>
          );
        })}
      </div>
    </section>
  );
}

function FinalCta() {
  return (
    <section className="final">
      <div className="final__inner">
        <div className="final__text">
          <h2 className="h2 h2--light h2--big">Simplifiez votre prochain planning de gardes.</h2>
          <p>Centralisez les disponibilités de votre équipe et construisez votre planning depuis un seul endroit.</p>
        </div>
        <div className="actions">
          <a href={links.signup} className="btn btn--white btn--lg">Commencer avec MedVue</a>
          <a href={links.contact} className="btn btn--outline-light btn--lg">Nous contacter</a>
        </div>
      </div>
    </section>
  );
}

function Footer() {
  return (
    <footer className="footer">
      <div className="wrap footer__inner">
        <div className="brand brand--sm">
          <img src="/logo/mark.svg" alt="" width={24} height={24} />
          <span className="brand__word">MedVue</span>
          <span className="footer__copy">© 2026</span>
        </div>
        <nav className="footer__nav" aria-label="Liens légaux">
          <a href={links.contact}>Contact</a>
          <a href={links.legal}>Mentions légales</a>
          <a href={links.privacy}>Confidentialité</a>
        </nav>
      </div>
    </footer>
  );
}

export function HomePage() {
  return (
    <>
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
    </>
  );
}
