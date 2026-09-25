import type {
  CollectionStatus,
  GenerationPreflight,
  MemberStatusDetail,
  PilotMemberRow,
} from '../features/planning/pilot/types'

/** A participant row; `overrides` win. Pending by default, no unavailability, never reminded. */
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
    collectionState: 'PENDING',
    acknowledgedAt: null,
    acknowledgementKind: null,
    lastAvailabilityChangeAt: null,
    pendingCollectionCount: 1,
    unavailabilityCount: 0,
    lastReminderAt: null,
    ...overrides,
  }
}

export const CONFIRMED: Partial<PilotMemberRow> = {
  collectionState: 'ACKNOWLEDGED',
  acknowledgedAt: '2026-09-21T08:14:00+00:00',
  acknowledgementKind: 'CONFIRMED',
  pendingCollectionCount: 0,
}

/** dupont: confirmed, 4 unavailabilities · martin: pending, 2, reminded 20/09 · leroy: pending, 0, reminded 18/09. */
export function makeStatus(overrides: Partial<CollectionStatus> = {}): CollectionStatus {
  const members = [
    makeRow('1', 'Dupont', { ...CONFIRMED, unavailabilityCount: 4 }),
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
      expectedCount: 3,
      confirmedCount: 1,
      pendingCount: 2,
      notExpectedCount: 0,
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
        status: member.collectionState === 'ACKNOWLEDGED' ? 'ACKNOWLEDGED' : 'PENDING',
        acknowledgedAt: member.acknowledgedAt,
        acknowledgementKind: member.acknowledgementKind,
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
    confirmedCount: 13,
    pendingCount: 3,
    notExpectedCount: 0,
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
      },
    ],
    blockers: [],
    warnings: [{ code: 'PENDING_MEMBERS', lineStableId: null, lineName: null }],
    canGenerate: true,
    ...overrides,
  }
}
