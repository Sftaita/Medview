/** Date ISO « AAAA-MM-JJ », sans heure. Toutes les dates sont des journées entières. */
export type ISODate = string

/**
 * Statut affiché d'une période, déduit par le serveur (docs/planning-cycles.md §4.2) :
 * preparation, collecting, generated, partially_published, published. `archivee` n'est
 * pas produit (C) : une période passée et publiée reste « Publiée ».
 */
export type PeriodStatus = 'preparation' | 'collecte' | 'generee' | 'partielle' | 'publiee' | 'archivee'

/**
 * docs/decisions.md D214 : « programmee » tant que l'annonce aux participants est programmée, même si une
 * collecte existe déjà techniquement ; « en-cours » une fois annoncée. Personne ne confirme rien.
 */
export type CollecteState = 'non-ouverte' | 'programmee' | 'en-cours' | 'cloturee'

export interface Collecte {
  state: CollecteState
  /** Nombre de participants (collectes ouvertes). */
  participants?: number
  /** Date limite indicative de déclaration (jamais bloquante). */
  closesOn?: ISODate
  /** Date d'annonce aux participants, quand elle est programmée. */
  announceOn?: ISODate
  /** Jours de la période qu'aucune collecte ne couvre encore. */
  uncoveredDays?: number
}

/** Une période appartient à un planning. Elle n’a ni membres ni lignes propres. */
export interface Period {
  id: string
  /** Numéro d’ordre, stable : Période 1, 2, 3… */
  number: number
  start: ISODate
  /** Date de fin incluse. */
  end: ISODate
  status: PeriodStatus
  collecte: Collecte
  /** Date cible de la génération (manuelle) : l'atteindre ne génère rien. */
  targetGenerationOn?: ISODate
  /** Date réelle de la (dernière) génération. */
  generatedOn?: ISODate
  /** Première publication de la période. */
  publishedOn?: ISODate
  /** Dernière diffusion (republication comprise). */
  lastPublishedOn?: ISODate
  /** Lignes qui comptent et lignes publiées (D194). */
  lineCounts?: { counting: number; published: number }
}

/** Déduit des droits (docs/planning-cycles.md §8) : créateur, gestionnaire (publie), membre. */
export type PlanningRole = 'Propriétaire' | 'Gestionnaire' | 'Membre'

export interface GuardLine {
  id: string
  name: string
}

export interface Member {
  id: string
  name: string
  isMe?: boolean
}

/** Le planning est permanent : équipe, lignes et fuseau lui appartiennent. */
export interface Planning {
  id: string
  name: string
  active: boolean
  role: PlanningRole
  timezone: string
  lines: GuardLine[]
  members: Member[]
  /** Triées par date de début croissante, sans trou ni chevauchement. */
  periods: Period[]
}

/** Une garde attribuée, pour l’onglet calendrier. */
export interface Shift {
  date: ISODate
  lineId: string
  memberId: string
}
