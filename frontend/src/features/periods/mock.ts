import type { ISODate, Member, Period, Planning, Shift } from './types'
import { addDays, daysInclusive, toMs } from './dates'

/** Date du jour figée pour la démo (comme la capture : jour 10 sur 122). */
export const DEMO_TODAY: ISODate = '2026-10-10'

const NAMES = [
  'Camille Laurent',
  'Nadia Benali',
  'Hugo Martin',
  'Léa Dubois',
  'Mehdi Aksoy',
  'Sophie Lambert',
  'Thomas Peeters',
  'Inès Haddad',
  'Julien Claes',
  'Emma Janssens',
  'Lucas Wouters',
  'Sarah El Idrissi',
  'Antoine Mertens',
  'Clara Vermeulen',
  'Samy Ftaita',
]

const members: Member[] = NAMES.map((name, i) => ({ id: `m${i + 1}`, name, isMe: name === 'Samy Ftaita' }))

const closed = (
  number: number,
  start: ISODate,
  end: ISODate,
  responses: number,
  gen: ISODate,
  pub: ISODate,
  status: 'publiee' | 'archivee',
): Period => ({
  id: `p${number}`,
  number,
  start,
  end,
  status,
  collecte: { state: 'cloturee', participants: responses },
  generatedOn: gen,
  publishedOn: pub,
})

export type Scenario = '1' | '2' | '4' | '8'

export function demoPeriods(scenario: Scenario): Period[] {
  if (scenario === '1') {
    return [closed(1, '2026-10-01', '2027-01-30', 14, '2026-09-12', '2026-09-20', 'publiee')]
  }
  if (scenario === '2') {
    return [
      closed(1, '2026-10-01', '2027-01-30', 14, '2026-09-12', '2026-09-20', 'publiee'),
      {
        id: 'p2',
        number: 2,
        start: '2027-01-31',
        end: '2027-04-30',
        status: 'preparation',
        collecte: { state: 'non-ouverte' },
      },
    ]
  }
  const history: [ISODate, ISODate][] =
    scenario === '4'
      ? [
          ['2026-02-01', '2026-05-31'],
          ['2026-06-01', '2026-09-30'],
        ]
      : [
          ['2024-10-01', '2025-01-31'],
          ['2025-02-01', '2025-05-31'],
          ['2025-06-01', '2025-09-30'],
          ['2025-10-01', '2026-01-31'],
          ['2026-02-01', '2026-05-31'],
          ['2026-06-01', '2026-09-30'],
        ]
  const archived = history.map(([s, e], i) =>
    closed(i + 1, s, e, 14, addDays(s, -20), addDays(s, -16), 'archivee'),
  )
  const n = archived.length
  return [
    ...archived,
    closed(n + 1, '2026-10-01', '2027-01-30', 14, '2026-09-12', '2026-09-20', 'publiee'),
    {
      id: `p${n + 2}`,
      number: n + 2,
      start: '2027-01-31',
      end: '2027-04-30',
      status: 'collecte',
      collecte: { state: 'en-cours', participants: 14, closesOn: '2026-11-15' },
    },
  ]
}

export function demoPlannings(scenario: Scenario): Planning[] {
  return [
    {
      id: 'trauma-delta',
      name: 'Trauma Delta',
      active: true,
      role: 'Propriétaire',
      timezone: 'Europe/Brussels',
      lines: [
        { id: 'l1', name: 'Ligne principale' },
        { id: 'l2', name: 'Renfort de nuit' },
      ],
      members,
      periods: demoPeriods(scenario),
    },
    {
      id: 'pediatrie',
      name: 'Gardes pédiatrie',
      active: true,
      role: 'Membre',
      timezone: 'Europe/Brussels',
      lines: [{ id: 'l1', name: 'Pédiatrie' }],
      members: members.slice(0, 8),
      periods: [closed(1, '2026-07-01', '2026-12-31', 8, '2026-06-10', '2026-06-15', 'publiee')],
    },
  ]
}

/** Gardes fictives et déterministes pour l’onglet calendrier. À remplacer par l’API. */
export function demoShifts(planning: Planning, period: Period): Shift[] {
  if (!period.generatedOn) return []
  const out: Shift[] = []
  const n = daysInclusive(period.start, period.end)
  const m = planning.members.length
  for (let k = 0; k < n; k++) {
    const date = addDays(period.start, k)
    planning.lines.forEach((line, li) => {
      out.push({
        date,
        lineId: line.id,
        memberId: planning.members[(k * (4 + 3 * li) + period.number * 3 + li * 5) % m].id,
      })
    })
  }
  return out.sort((a, b) => toMs(a.date) - toMs(b.date))
}
