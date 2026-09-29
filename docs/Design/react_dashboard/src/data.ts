import type { PlanningSummary, Unavailability, User } from './types';

// Données d'exemple — remplacez par vos appels API.
export const user: User = { firstName: 'Samy', lastName: 'Ftaita' };

export const plannings: PlanningSummary[] = [
  { id: 'trauma-delta', name: 'Trauma Delta', start: '2026-10-01', end: '2027-01-31', role: 'Première ligne', memberCount: 17, published: false },
];

export const unavailabilities: Unavailability[] = [
  { id: 'u1', start: '2026-10-03', end: '2026-10-04' },
  { id: 'u2', start: '2026-11-20', end: '2026-11-22' },
  { id: 'u3', start: '2026-11-26', end: '2026-11-26' },
  { id: 'u4', start: '2026-12-02', end: '2026-12-06' },
];
