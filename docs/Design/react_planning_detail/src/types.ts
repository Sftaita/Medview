export type LineKind = 'principale' | 'secondaire';

export interface Line {
  id: string;
  name: string;
  kind: LineKind;
}

export interface Member {
  id: string;
  name: string;
  lineId: string;
  /** Jours d'indisponibilité déclarés sur la période */
  unavailableDays: number;
}

export interface Collecte {
  /** ISO yyyy-mm-dd */
  deadline: string;
  respondedIds: string[];
  remindedAt?: string;
}

export interface Planning {
  id: string;
  name: string;
  status: 'brouillon' | 'publie';
  /** ISO yyyy-mm-dd, inclus */
  start: string;
  /** ISO yyyy-mm-dd, inclus */
  end: string;
  timeZone: string;
  hasGeneration: boolean;
}
