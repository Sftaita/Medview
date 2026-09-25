const DAY = 86_400_000;

const toUTC = (iso: string) => {
  const [y, m, d] = iso.split('-').map(Number);
  return Date.UTC(y, m - 1, d);
};
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

/** Date du jour (yyyy-mm-dd) dans le fuseau du planning */
export function todayISO(timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/** "Jeu. 1 oct. 2026" */
export function formatLongDate(iso: string): string {
  const f = new Intl.DateTimeFormat('fr-BE', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
  return cap(f.format(new Date(toUTC(iso))));
}

/** "30 sept." */
export function formatShortDate(iso: string): string {
  return new Intl.DateTimeFormat('fr-BE', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(toUTC(iso)));
}

export function daysInclusive(start: string, end: string): number {
  return Math.round((toUTC(end) - toUTC(start)) / DAY) + 1;
}

export interface MonthSegment { key: string; label: string; days: number }

/** Découpe la période en mois, avec le nombre de jours de chaque mois compris dans la période */
export function monthSegments(start: string, end: string): MonthSegment[] {
  const s = new Date(toUTC(start)), e = toUTC(end);
  const out: MonthSegment[] = [];
  let y = s.getUTCFullYear(), m = s.getUTCMonth();
  const multiYear = new Date(e).getUTCFullYear() !== y;
  const fmt = new Intl.DateTimeFormat('fr-BE', { month: 'short', timeZone: 'UTC' });
  for (;;) {
    const first = Math.max(Date.UTC(y, m, 1), toUTC(start));
    const last = Math.min(Date.UTC(y, m + 1, 0), e);
    if (first > e) break;
    const label = cap(fmt.format(new Date(Date.UTC(y, m, 1))));
    out.push({ key: `${y}-${m}`, label: multiYear && m === 0 && out.length ? `${label} ${y}` : label, days: Math.round((last - first) / DAY) + 1 });
    m++; if (m > 11) { m = 0; y++; }
  }
  return out;
}

export function monthTitles(start: string, end: string): string[] {
  const fmt = new Intl.DateTimeFormat('fr-BE', { month: 'long', year: 'numeric', timeZone: 'UTC' });
  return monthSegments(start, end).map(seg => {
    const [y, m] = seg.key.split('-').map(Number);
    return cap(fmt.format(new Date(Date.UTC(y, m, 1))));
  });
}

export type PeriodState =
  | { kind: 'upcoming'; label: string }
  | { kind: 'running'; label: string; progress: number }
  | { kind: 'past'; label: string };

export function periodState(start: string, end: string, today: string): PeriodState {
  const t = toUTC(today), s = toUTC(start), e = toUTC(end);
  if (t < s) {
    const n = Math.round((s - t) / DAY);
    return { kind: 'upcoming', label: n === 1 ? 'Commence demain' : `Commence dans ${n} jours` };
  }
  if (t > e) return { kind: 'past', label: 'Période terminée' };
  const day = Math.round((t - s) / DAY) + 1, total = daysInclusive(start, end);
  return { kind: 'running', label: `En cours · jour ${day} sur ${total}`, progress: (day - 0.5) / total };
}
