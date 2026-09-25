import type { Collecte, Line, Member, Planning } from './types';

// Données d'exemple — à remplacer par l'API.
export const planning: Planning = {
  id: '01a0c259-e41c-75d8-bf64-26e102d4d573',
  name: 'Trauma Delta',
  status: 'brouillon',
  start: '2026-10-01',
  end: '2027-01-31',
  timeZone: 'Europe/Brussels',
  hasGeneration: false,
};

export const lines: Line[] = [
  { id: 'l1', name: 'Première ligne', kind: 'principale' },
  { id: 'l2', name: 'Assistant', kind: 'secondaire' },
];

const raw: [string, number, string][] = [
  ['Yorick Berger', 0, 'l1'], ['Bernard Bouillet', 4, 'l1'], ['Jerome De Muylder', 10, 'l1'], ['Arnaud Deltour', 5, 'l1'],
  ['Ioan Dunca', 6, 'l1'], ['Jean-Paul Dusabe', 9, 'l1'], ['Samy Ftaita', 3, 'l1'], ['Claire Gilson', 7, 'l1'],
  ['Nicolas Hardy', 2, 'l1'], ['Marie Lambert', 12, 'l1'], ['Thomas Leclercq', 0, 'l1'], ['Sophie Martens', 8, 'l1'],
  ['Olivier Peeters', 5, 'l1'], ['Stephane Urgyan', 6, 'l1'], ['Julien Vanderplasschen', 18, 'l1'],
  ['Laura Renard', 4, 'l2'], ['Hélène Wauters', 1, 'l2'],
];
export const members: Member[] = raw.map(([name, unavailableDays, lineId], i) => ({ id: 'm' + i, name, unavailableDays, lineId }));

export const currentUserId = 'm6';

export const initialCollecte: Collecte | null = null;
