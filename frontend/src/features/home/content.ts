/* Contenu de la page d'accueil.
   Les noms et chiffres sont des données d'exemple ; les comportements illustrés existent dans MedVue. */

export const NB = '\u202F' // espace fine insécable avant ? : ; ! et à l'intérieur des « »

/**
 * Routes de l'application. Contact, mentions légales et confidentialité
 * n'existent pas encore : null = lien non affiché (la page ne montre que
 * ce qui existe).
 */
export const links: {
  login: string
  signup: string
  contact: string | null
  legal: string | null
  privacy: string | null
} = {
  login: '/login',
  signup: '/register',
  contact: null,
  legal: null,
  privacy: null,
}

export const heroLines = [
  { text: 'Chaque membre renseigne ses contraintes.', tone: 'red' },
  { text: 'MedVue vous aide à construire le planning.', tone: 'blue' },
  { text: 'Le responsable garde la maîtrise du résultat.', tone: 'green' },
] as const

/** Novembre 2026 : semaines du lundi 2 au dimanche 29. Le 11 est férié. */
export const heroWeeks: {
  start: number
  bars: { kind: 'garde' | 'indispo'; who: string; from: number; to: number }[]
}[] = [
  {
    start: 2,
    bars: [
      { kind: 'indispo', who: 'CH', from: 2, to: 4 },
      { kind: 'garde', who: 'JV', from: 5, to: 7 },
    ],
  },
  {
    start: 9,
    bars: [
      { kind: 'indispo', who: 'SB', from: 1, to: 2 },
      { kind: 'garde', who: 'ML', from: 5, to: 7 },
    ],
  },
  {
    start: 16,
    bars: [
      { kind: 'garde', who: 'CH', from: 1, to: 1 },
      { kind: 'indispo', who: 'AO', from: 3, to: 7 },
    ],
  },
  {
    start: 23,
    bars: [
      { kind: 'indispo', who: 'JV', from: 1, to: 1 },
      { kind: 'garde', who: 'SB', from: 5, to: 7 },
    ],
  },
]
export const holidays = [11]

/** Calendrier personnel : une date, plusieurs dates, une période. */
export const selectionDays = [9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22]
export const selectionPeriods: [number, number][] = [
  [10, 12],
  [16, 16],
  [19, 22],
]

export const responses = [
  { initials: 'CH', name: 'Dr Camille Hennebert', answered: true },
  { initials: 'JV', name: 'Dr Julien Vandersmissen', answered: true },
  { initials: 'ML', name: 'Dr Marie Léonard', answered: false },
  { initials: 'AO', name: 'Dr Ada Okonkwo', answered: true },
  { initials: 'SB', name: 'Dr Sophie Bastin', answered: false },
]

export const weekdays = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']

/** Statistiques : gardes affectées par jour ISO. Un bloc sam. + dim. compte +1 samedi et +1 dimanche. */
export const stats = {
  periode: [
    ['Dr C. Hennebert', [1, 1, 1, 1, 1, 0, 1]],
    ['Dr J. Vandersmissen', [1, 1, 1, 0, 1, 1, 1]],
    ['Dr M. Léonard', [1, 0, 1, 1, 0, 1, 1]],
    ['Dr A. Okonkwo', [1, 1, 0, 1, 1, 1, 1]],
    ['Dr S. Bastin', [1, 1, 1, 1, 1, 1, 1]],
  ],
  cumul: [
    ['Dr C. Hennebert', [9, 8, 9, 8, 7, 6, 7]],
    ['Dr J. Vandersmissen', [8, 9, 8, 8, 8, 7, 7]],
    ['Dr M. Léonard', [9, 8, 8, 9, 7, 7, 6]],
    ['Dr A. Okonkwo', [5, 5, 4, 5, 4, 4, 5]],
    ['Dr S. Bastin', [9, 9, 9, 8, 8, 6, 8]],
  ],
} as const satisfies Record<string, readonly (readonly [string, readonly number[]])[]>

export const statTabs = [
  {
    id: 'periode',
    label: 'Cette période',
    note: 'Novembre 2026 · gardes affectées, comptées sur leur jour de la semaine.',
  },
  {
    id: 'cumul',
    label: 'Cumul du planning',
    note: 'Ensemble du planning · calculé à partir des affectations réelles.',
  },
] as const

export const engineSteps = [
  { n: '1', title: 'Éligibilité', body: `Qui peut réellement assurer cette garde${NB}?`, tone: 'ink' },
  {
    n: '2',
    title: 'Contraintes',
    body: 'Indisponibilités, conflits de garde et règles de repos.',
    tone: 'red',
  },
  {
    n: '3',
    title: 'Répartition',
    body: 'Recherche d’une solution cohérente à l’échelle du planning.',
    tone: 'green',
  },
] as const

export const uncovered = [
  { num: '14', wd: 'sam.', line: 'Garde principale' },
  { num: '22', wd: 'dim.', line: 'Astreinte' },
]

/** Exemples de lignes : les noms et le nombre de lignes sont définis par l'équipe. */
export const planningLines = [
  { name: 'Garde principale', tone: 'blue', cells: ['CH', 'JV', 'ML', 'AO', 'SB', 'SB', 'SB'] },
  { name: 'Garde secondaire', tone: 'green', cells: ['ML', 'AO', 'SB', 'CH', 'JV', 'JV', 'JV'] },
  { name: 'Astreinte', tone: 'ink', cells: ['AO', 'AO', 'AO', 'AO', 'AO', null, null] },
  { name: 'Consultation du samedi', tone: 'amber', cells: [null, null, null, null, null, 'ML', null] },
] as const
export const lineHeads = ['Lun. 16', 'Mar. 17', 'Mer. 18', 'Jeu. 19', 'Ven. 20', 'Sam. 21', 'Dim. 22']

export const spaces = [
  {
    title: 'Pour les membres',
    tone: 'red',
    body: 'Renseignez vos indisponibilités depuis votre calendrier personnel, indiquez vos préférences de garde et participez à la collecte de votre équipe.',
  },
  {
    title: 'Pour le responsable du planning',
    tone: 'blue',
    body: 'Créez et configurez le planning, suivez la collecte, relancez les membres, lancez la génération, consultez et modifiez le résultat, consultez les statistiques.',
  },
  {
    title: 'Pour toute l’équipe',
    tone: 'green',
    body: 'Un planning commun, construit à partir des contraintes renseignées par chacun.',
  },
] as const

export const chores = [
  'Collecter les disponibilités.',
  'Relancer les collègues qui n’ont pas répondu.',
  'Construire le planning.',
  'Recompter la répartition des gardes.',
  'Corriger les déséquilibres.',
  'Modifier le planning lorsqu’un imprévu survient.',
]

export const steps = [
  'Créez votre équipe et votre planning.',
  'Ajoutez les membres.',
  'Définissez la période.',
  'Recueillez les indisponibilités.',
  'Générez le planning.',
]

export const faq: [string, string][] = [
  [
    `À qui s’adresse MedVue${NB}?`,
    'MedVue est conçu principalement pour les équipes médicales et les organisations qui doivent répartir régulièrement des gardes ou des astreintes entre plusieurs professionnels.',
  ],
  [
    `Les membres peuvent-ils encoder eux-mêmes leurs indisponibilités${NB}?`,
    'Oui. Chaque membre dispose d’un calendrier personnel et peut sélectionner une date, plusieurs dates ou une période, sur ordinateur comme sur smartphone.',
  ],
  [
    `Les préférences de garde sont-elles utilisées par la génération${NB}?`,
    'Les membres peuvent indiquer une préférence de garde, recueillie avec leurs indisponibilités. Elle n’est pas encore utilisée comme critère par la génération automatique.',
  ],
  [
    `Peut-on gérer plusieurs types de garde${NB}?`,
    'Oui. Un planning peut comporter plusieurs lignes, définies par l’équipe, afin de représenter différentes gardes, astreintes ou activités.',
  ],
  [
    `Que se passe-t-il si le planning ne peut pas être entièrement couvert${NB}?`,
    'MedVue produit une solution partielle et affiche les gardes qui restent à couvrir, afin que le responsable puisse agir.',
  ],
  [
    `Peut-on modifier le planning après sa génération${NB}?`,
    'Oui. Le responsable peut réaffecter une garde à un autre membre lorsque c’est nécessaire.',
  ],
  [
    `Comment la répartition est-elle suivie${NB}?`,
    'Les statistiques montrent, pour chaque membre, les gardes affectées par jour de la semaine, du lundi au dimanche. Un bloc samedi et dimanche compte pour un samedi et pour un dimanche.',
  ],
  [
    `Peut-on suivre la répartition sur plusieurs périodes${NB}?`,
    `Oui. À côté de l’onglet «${NB}Cette période${NB}», l’onglet «${NB}Cumul du planning${NB}» est calculé à partir des affectations réelles de l’ensemble du planning.`,
  ],
]
