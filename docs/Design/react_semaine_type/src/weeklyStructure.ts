/* ==================================================================
   Semaine type — logique pure, sans React
   ------------------------------------------------------------------
   Une semaine = 7 jours (0 = lundi … 6 = dimanche). Chaque jour a un rôle :
     - 'solo'  : garde isolée, une unité à attribuer
     - 'block' : membre d'un bloc, attribué d'un seul tenant
     - 'none'  : pas de garde — le jour n'existe pas dans la demande
   Un bloc réunit au moins 2 jours, consécutifs ou non. 4 blocs maximum (A–D).
   Toutes les fonctions sont immuables : elles renvoient une nouvelle structure.
   ================================================================== */

export type DayIndex = 0 | 1 | 2 | 3 | 4 | 5 | 6;
export type DayCode = 'LUN' | 'MAR' | 'MER' | 'JEU' | 'VEN' | 'SAM' | 'DIM';
export type BlockId = 'A' | 'B' | 'C' | 'D';

export type DayRole =
  | { mode: 'solo' }
  | { mode: 'block'; block: BlockId }
  | { mode: 'none' };

export interface Block { id: BlockId; name: string }

export interface WeekStructure {
  days: [DayRole, DayRole, DayRole, DayRole, DayRole, DayRole, DayRole];
  blocks: Block[];
}

/** Forme envoyée au back / au solveur. */
export interface WeekStructurePayload {
  blocks: { id: BlockId; name: string; days: DayCode[] }[];
  solo: DayCode[];
  excluded: DayCode[];
}

export const DAY_NAMES = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'] as const;
export const DAY_SHORT = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as const;
export const DAY_CODES: readonly DayCode[] = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];
export const BLOCK_IDS: readonly BlockId[] = ['A', 'B', 'C', 'D'];
export const MIN_BLOCK_DAYS = 2;
export const LONG_BLOCK_DAYS = 5;

const ALL: DayIndex[] = [0, 1, 2, 3, 4, 5, 6];

/* ---------- construction ---------- */

export function allSolo(): WeekStructure {
  return { days: ALL.map(() => ({ mode: 'solo' as const })) as WeekStructure['days'], blocks: [] };
}

export type PresetId = 'vsd' | 'vd' | 'nosun' | 'two' | 'solo';

export const PRESETS: { id: PresetId; label: string }[] = [
  { id: 'vsd', label: 'V·S·D en bloc' },
  { id: 'vd', label: 'Bloc V·D, samedi seul' },
  { id: 'nosun', label: 'Sans dimanche' },
  { id: 'two', label: 'Bloc semaine + bloc week-end' },
  { id: 'solo', label: 'Tous isolés' },
];

export function preset(id: PresetId): WeekStructure {
  let s = allSolo();
  if (id === 'vsd') s = createBlock(s, [4, 5, 6], 'Week-end');
  if (id === 'vd') s = createBlock(s, [4, 6], 'Vendredi + dimanche');
  if (id === 'nosun') s = setNone(s, [6]);
  if (id === 'two') s = createBlock(createBlock(s, [0, 1, 2, 3], 'Semaine'), [4, 5, 6], 'Week-end');
  return s;
}

/* ---------- lecture ---------- */

export function members(s: WeekStructure, id: BlockId): DayIndex[] {
  return ALL.filter(i => { const d = s.days[i]; return d.mode === 'block' && d.block === id; });
}
export function soloDays(s: WeekStructure): DayIndex[] { return ALL.filter(i => s.days[i].mode === 'solo'); }
export function excludedDays(s: WeekStructure): DayIndex[] { return ALL.filter(i => s.days[i].mode === 'none'); }
export function freeBlockIds(s: WeekStructure): BlockId[] { return BLOCK_IDS.filter(id => !s.blocks.some(b => b.id === id)); }
export function isContiguous(idx: DayIndex[]): boolean {
  return idx.length > 0 && idx[idx.length - 1] - idx[0] + 1 === idx.length;
}

/** Unités à attribuer par semaine : une par garde isolée, une par bloc. */
export function unitsPerWeek(s: WeekStructure): number { return soloDays(s).length + s.blocks.length; }
/** Jours couverts par une garde. */
export function dutyDaysPerWeek(s: WeekStructure): number { return 7 - excludedDays(s).length; }

export function canCreateBlock(s: WeekStructure, selection: DayIndex[]): boolean {
  return selection.length >= MIN_BLOCK_DAYS && freeBlockIds(s).length > 0;
}

/* ---------- écriture ---------- */

/** Un bloc tombé sous 2 jours est dissous : ses jours redeviennent des gardes isolées. */
function normalize(s: WeekStructure): WeekStructure {
  const days = s.days.slice() as WeekStructure['days'];
  const blocks = s.blocks.filter(b => {
    const m = ALL.filter(i => { const d = days[i]; return d.mode === 'block' && d.block === b.id; });
    if (m.length >= MIN_BLOCK_DAYS) return true;
    m.forEach(i => { days[i] = { mode: 'solo' }; });
    return false;
  });
  return { days, blocks };
}

function assign(s: WeekStructure, idx: DayIndex[], role: DayRole): WeekStructure {
  const days = s.days.slice() as WeekStructure['days'];
  idx.forEach(i => { days[i] = role; });
  return normalize({ days, blocks: s.blocks });
}

export function setSolo(s: WeekStructure, idx: DayIndex[]): WeekStructure { return assign(s, idx, { mode: 'solo' }); }
export function setNone(s: WeekStructure, idx: DayIndex[]): WeekStructure { return assign(s, idx, { mode: 'none' }); }

/** Crée un bloc avec la première lettre libre. Sans effet si la sélection est trop courte ou si A–D sont pris. */
export function createBlock(s: WeekStructure, idx: DayIndex[], name?: string): WeekStructure {
  const sorted = [...new Set(idx)].sort((a, b) => a - b) as DayIndex[];
  if (!canCreateBlock(s, sorted)) return s;
  const id = freeBlockIds(s)[0];
  const next = assign(s, sorted, { mode: 'block', block: id });
  return { days: next.days, blocks: [...next.blocks, { id, name: name ?? sorted.map(i => DAY_SHORT[i]).join(' · ') }] };
}

export function dissolveBlock(s: WeekStructure, id: BlockId): WeekStructure { return setSolo(s, members(s, id)); }

export function renameBlock(s: WeekStructure, id: BlockId, name: string): WeekStructure {
  return { days: s.days, blocks: s.blocks.map(b => (b.id === id ? { ...b, name } : b)) };
}

/* ---------- avertissements ---------- */

export interface Warning { tone: 'warn' | 'info'; text: string }

export function warnings(s: WeekStructure): Warning[] {
  const out: Warning[] = [];
  const none = excludedDays(s);
  if (none.length === 7) out.push({ tone: 'warn', text: 'Aucune garde ne sera générée pour cette ligne de planning.' });
  s.blocks.forEach(b => {
    const n = members(s, b.id).length;
    if (n >= LONG_BLOCK_DAYS) out.push({ tone: 'warn', text: `Le bloc ${b.id} couvre ${n} jours : une seule personne sera de garde presque toute la semaine.` });
  });
  if (none.length && none.length < 7) {
    out.push({ tone: 'info', text: `${none.map(i => DAY_SHORT[i]).join(' · ')} : aucune garde ne sera créée. Ce n’est pas une indisponibilité — le jour n’existe simplement pas dans la demande.` });
  }
  return out;
}

/* ---------- sérialisation ---------- */

export function toPayload(s: WeekStructure): WeekStructurePayload {
  return {
    blocks: s.blocks.map(b => ({ id: b.id, name: b.name, days: members(s, b.id).map(i => DAY_CODES[i]) })),
    solo: soloDays(s).map(i => DAY_CODES[i]),
    excluded: excludedDays(s).map(i => DAY_CODES[i]),
  };
}

/** Relit une structure enregistrée. Un jour absent des trois listes devient une garde isolée. */
export function fromPayload(p: WeekStructurePayload): WeekStructure {
  let s = allSolo();
  const idx = (codes: DayCode[]) => codes.map(c => DAY_CODES.indexOf(c) as DayIndex).filter(i => i >= 0);
  s = setNone(s, idx(p.excluded));
  const days = s.days.slice() as WeekStructure['days'];
  const blocks: Block[] = [];
  p.blocks.forEach(b => {
    if (!BLOCK_IDS.includes(b.id) || blocks.some(x => x.id === b.id)) return;
    idx(b.days).forEach(i => { days[i] = { mode: 'block', block: b.id }; });
    blocks.push({ id: b.id, name: b.name });
  });
  return normalize({ days, blocks });
}
