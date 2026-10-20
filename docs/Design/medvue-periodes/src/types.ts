/** Date ISO « AAAA-MM-JJ », sans heure. Toutes les dates sont des journées entières. */
export type ISODate = string;

/** Libellés de maquette — à aligner sur les statuts techniques du backend. */
export type PeriodStatus = 'preparation' | 'collecte' | 'generee' | 'publiee' | 'archivee';

export type CollecteState = 'non-ouverte' | 'en-cours' | 'cloturee';

export interface Collecte {
  state: CollecteState;
  /** Nombre de membres ayant répondu. */
  responses: number;
  /** Date de clôture prévue (collecte en cours). */
  closesOn?: ISODate;
}

/** Une période appartient à un planning. Elle n’a ni membres ni lignes propres. */
export interface Period {
  id: string;
  /** Numéro d’ordre, stable : Période 1, 2, 3… */
  number: number;
  start: ISODate;
  /** Date de fin incluse. */
  end: ISODate;
  status: PeriodStatus;
  collecte: Collecte;
  generatedOn?: ISODate;
  publishedOn?: ISODate;
}

export type PlanningRole = 'Propriétaire' | 'Admin' | 'Membre';

export interface GuardLine {
  id: string;
  name: string;
}

export interface Member {
  id: string;
  name: string;
  isMe?: boolean;
}

/** Le planning est permanent : équipe, lignes et fuseau lui appartiennent. */
export interface Planning {
  id: string;
  name: string;
  active: boolean;
  role: PlanningRole;
  timezone: string;
  lines: GuardLine[];
  members: Member[];
  /** Triées par date de début croissante, sans trou ni chevauchement. */
  periods: Period[];
}

/** Une garde attribuée, pour l’onglet calendrier. */
export interface Shift {
  date: ISODate;
  lineId: string;
  memberId: string;
}
