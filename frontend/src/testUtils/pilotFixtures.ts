import type {
  CollectionStatus,
  GenerationPreflight,
  MemberStatusDetail,
  PilotMemberRow,
} from '../features/planning/pilot/types'

/** A participant row; `overrides` win. A participant, no unavailability, never reminded. */
export function makeRow(
  id: string,
  lastName: string,
  overrides: Partial<PilotMemberRow> = {},
): PilotMemberRow {
  return {
    memberStableId: `m-${id}`,
    userStableId: `u-${id}`,
    firstName: 'Camille',
    lastName,
    role: 'MEMBER',
    team: { stableId: 'team-1', name: 'Seniors' },
    membershipEnd: null,
    participant: true,
    lastAvailabilityChangeAt: null,
    unavailabilityCount: 0,
    lastReminderAt: null,
    ...overrides,
  }
}

/** A row whose calendar was changed on 21 September (docs/decisions.md D215). */
export const CHANGED: Partial<PilotMemberRow> = {
  lastAvailabilityChangeAt: '2026-09-21T08:14:00+00:00',
}

/** dupont: 4 unavailabilities, calendar changed 21/09 · martin: 2, reminded 20/09 · leroy: 0, reminded 18/09. */
export function makeStatus(overrides: Partial<CollectionStatus> = {}): CollectionStatus {
  const members = [
    makeRow('1', 'Dupont', { ...CHANGED, unavailabilityCount: 4 }),
    makeRow('2', 'Martin', { unavailabilityCount: 2, lastReminderAt: '2026-09-20T09:00:00+00:00' }),
    makeRow('3', 'Leroy', { unavailabilityCount: 0, lastReminderAt: '2026-09-18T09:00:00+00:00' }),
  ]
  return {
    planning: {
      stableId: 'plan-1',
      name: 'Gardes 2027',
      startsAt: '2027-01-01',
      endsAt: '2027-04-01',
      lastDay: '2027-03-31',
      timezone: 'Europe/Brussels',
    },
    availabilityDeadline: null,
    deadlineOverdueDays: null,
    openCollectionCount: 1,
    summary: {
      participantCount: 3,
      concernedCount: 3,
      unavailabilityCount: 6,
    },
    members,
    ...overrides,
  }
}

export function makeDetail(
  member: PilotMemberRow,
  overrides: Partial<MemberStatusDetail> = {},
): MemberStatusDetail {
  return {
    member,
    participation: {
      membershipStart: '2027-01-01',
      membershipEnd: null,
      participationPeriods: [{ validFrom: '2027-01-01', validTo: null, participationFactor: 1 }],
      nonParticipationPeriods: [],
    },
    collections: [
      {
        collectionStableId: 'col-1',
        startsAt: '2027-01-01',
        lastDay: '2027-03-31',
        collectionStatus: 'OPEN',
        status: member.participant ? 'PARTICIPANT' : 'WITHDRAWN',
      },
    ],
    // 3–7 janvier, 21 janvier, 14 février 08:00 → 15 février 12:00 (Brussels).
    unavailabilities: [
      {
        stableId: 'p1',
        type: 'UNAVAILABLE',
        startsAt: '2027-01-02T23:00:00+00:00',
        endsAt: '2027-01-07T23:00:00+00:00',
      },
      {
        stableId: 'p2',
        type: 'UNAVAILABLE',
        startsAt: '2027-01-20T23:00:00+00:00',
        endsAt: '2027-01-21T23:00:00+00:00',
      },
      {
        stableId: 'p3',
        type: 'UNAVAILABLE',
        startsAt: '2027-02-14T07:00:00+00:00',
        endsAt: '2027-02-15T11:00:00+00:00',
      },
    ],
    preferences: [],
    reminders: [],
    ...overrides,
  }
}

export function makePreflight(overrides: Partial<GenerationPreflight> = {}): GenerationPreflight {
  return {
    planning: {
      stableId: 'plan-1',
      name: 'Gardes 2027',
      startsAt: '2027-01-01',
      endsAt: '2027-04-01',
      lastDay: '2027-03-31',
      timezone: 'Europe/Brussels',
    },
    participantCount: 16,
    unavailabilityCount: 42,
    availabilityDeadline: null,
    deadlineOverdueDays: null,
    lines: [
      {
        stableId: 'line-1',
        name: 'Seniors',
        type: 'PRIMARY',
        memberCount: 16,
        dutyCount: 30,
        periodStatus: 'DRAFT',
        hasActiveRuleSet: true,
        familyUnitCounts: { '': 20, 'Week-end': 10 },
        demandSourceLineName: null,
      },
    ],
    blockers: [],
    warnings: [],
    canGenerate: true,
    ...overrides,
  }
}
