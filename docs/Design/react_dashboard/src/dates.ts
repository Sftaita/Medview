const WD = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const MO = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const MO_CAP = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

/** ISO yyyy-mm-dd → Date locale à minuit (évite le décalage UTC de new Date('yyyy-mm-dd')). */
export function parseISO(s: string): Date {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d);
}

export function startOfToday(): Date {
  const n = new Date();
  return new Date(n.getFullYear(), n.getMonth(), n.getDate());
}

/** Écart en jours calendaires (b − a). */
export function daysBetween(a: Date, b: Date): number {
  return Math.round((b.getTime() - a.getTime()) / 864e5);
}

/** Nombre de jours inclus entre a et b. */
export function inclusiveDays(a: Date, b: Date): number {
  return daysBetween(a, b) + 1;
}

export const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

export function monthShort(d: Date): string {
  return MO[d.getMonth()].replace('.', '');
}

/** « jeu. 1 oct. » ; options : année, sans jour de semaine. */
export function formatDay(d: Date, opts: { year?: boolean; weekday?: boolean } = {}): string {
  const { year = false, weekday = true } = opts;
  return `${weekday ? WD[d.getDay()] + ' ' : ''}${d.getDate()} ${MO[d.getMonth()]}${year ? ' ' + d.getFullYear() : ''}`;
}

/** « Samedi 26 septembre 2026 » */
export function formatLongDate(d: Date): string {
  return cap(new Intl.DateTimeFormat('fr-BE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(d));
}

/** Plage d'indisponibilité : « Sam. 3 oct. », « Ven. 20 → dim. 22 nov. », « Lun. 30 nov. → mer. 2 déc. » */
export function formatUnavailability(a: Date, b: Date): string {
  if (daysBetween(a, b) === 0) return cap(formatDay(a));
  if (a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear())
    return `${cap(WD[a.getDay()])} ${a.getDate()} → ${formatDay(b)}`;
  return `${cap(formatDay(a))} → ${formatDay(b)}`;
}

export function relativeDays(n: number): string {
  if (n <= 0) return "Aujourd'hui";
  if (n === 1) return 'Demain';
  return `Dans ${n} jours`;
}

export interface MonthSegment { key: string; label: string; days: number }

/** Découpe [start, end] par mois calendaire, avec le nombre de jours couverts par mois. */
export function monthSegments(start: Date, end: Date): MonthSegment[] {
  const out: MonthSegment[] = [];
  for (let d = new Date(start); d <= end; d = new Date(d.getFullYear(), d.getMonth() + 1, 1)) {
    const last = new Date(d.getFullYear(), d.getMonth() + 1, 0);
    out.push({ key: `${d.getFullYear()}-${d.getMonth()}`, label: MO_CAP[d.getMonth()], days: inclusiveDays(d, last < end ? last : end) });
  }
  return out;
}
