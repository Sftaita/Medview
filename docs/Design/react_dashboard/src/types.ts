export interface User {
  firstName: string;
  lastName: string;
}

export interface PlanningSummary {
  id: string;
  name: string;
  /** ISO yyyy-mm-dd, inclus */
  start: string;
  /** ISO yyyy-mm-dd, inclus */
  end: string;
  /** Ligne de garde de l'utilisateur dans ce planning */
  role: string;
  memberCount: number;
  published: boolean;
}

export interface Unavailability {
  id: string;
  /** ISO yyyy-mm-dd, inclus */
  start: string;
  /** ISO yyyy-mm-dd, inclus */
  end: string;
}

export interface DashboardHrefs {
  dashboard: string;
  plannings: string;
  planning: (id: string) => string;
  gardes: string;
  indispos: string;
  indispo: (id: string) => string;
  calendar: string;
  account: string;
}
