export type PlanningStep = 'draft' | 'collect' | 'published';

export interface User {
  firstName: string;
  lastName: string;
}

export interface Planning {
  id: string;
  name: string;
  /** ISO yyyy-mm-dd, inclus */
  start: string;
  /** ISO yyyy-mm-dd, inclus */
  end: string;
  lineCount: number;
  memberCount: number;
  /** draft = à générer · collect = collecte des indispos ouverte · published = publié */
  step: PlanningStep;
}

export interface PlanningsHrefs {
  dashboard: string;
  plannings: string;
  planning: (id: string) => string;
  gardes: string;
  indispos: string;
  account: string;
}
