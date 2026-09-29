import type { Planning, User } from './types';

// Données d'exemple — remplacez par vos appels API.
export const user: User = { firstName: 'Samy', lastName: 'Ftaita' };

export const plannings: Planning[] = [
  { id: 'trauma-delta', name: 'Trauma Delta', start: '2026-10-01', end: '2027-01-31', lineCount: 2, memberCount: 17, step: 'draft' },
];

/** Jeu plus riche pour tester le regroupement En cours / À venir / Terminés. */
export const manyPlannings: Planning[] = [
  { id: 'urgences-nord', name: 'Urgences Nord', start: '2026-07-01', end: '2026-12-31', lineCount: 3, memberCount: 24, step: 'published' },
  ...plannings,
  { id: 'ortho-hiver', name: 'Orthopédie — Hiver', start: '2027-01-04', end: '2027-03-28', lineCount: 1, memberCount: 9, step: 'collect' },
  { id: 'trauma-gamma', name: 'Trauma Gamma', start: '2026-04-01', end: '2026-06-30', lineCount: 2, memberCount: 16, step: 'published' },
];
