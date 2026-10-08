import type {
  SwapOptions,
  SwapPerson,
  SwapProposal,
  SwapRequestDetail,
  SwapUnit,
} from '../features/swaps/types'

/** Fixtures for the swap screens (docs/duty-swaps.md): Alice offers Tue 5 Jan, Bob holds Tue 12 Jan. */

export const ALICE: SwapPerson = { userStableId: 'u-alice', firstName: 'Alice', lastName: 'Martin' }
export const BOB: SwapPerson = { userStableId: 'u-bob', firstName: 'Bob', lastName: 'Durand' }
export const ADAM: SwapPerson = { userStableId: 'u-adam', firstName: 'Adam', lastName: 'Leroy' }

export function makeUnit(dates: string[], overrides: Partial<SwapUnit> = {}): SwapUnit {
  return {
    dutyStableId: `duty-${dates[0]}`,
    planningStableId: 'p1',
    planningName: 'Gardes 2027',
    lineStableId: 'l1',
    lineName: 'Seniors',
    dutyTypeName: 'Garde',
    blockName: null,
    dates,
    startsAt: `${dates[0]}T00:00:00+01:00`,
    endsAt: `${dates[dates.length - 1]}T23:59:00+01:00`,
    ...overrides,
  }
}

export function makeProposal(overrides: Partial<SwapProposal> = {}): SwapProposal {
  return {
    stableId: 'prop-1',
    status: 'PENDING',
    author: ALICE,
    counterpart: BOB,
    decider: BOB,
    counterpartUnit: makeUnit(['2027-01-12']),
    createdAt: '2026-12-10T09:00:00+00:00',
    decidedAt: null,
    actions: { accept: false, refuse: false, withdraw: false },
    ...overrides,
  }
}

export function makeRequest(overrides: Partial<SwapRequestDetail> = {}): SwapRequestDetail {
  return {
    stableId: 'req-1',
    kind: 'AGREED',
    audience: 'SELECTED',
    status: 'OPEN',
    createdAt: '2026-12-10T09:00:00+00:00',
    closedAt: null,
    expiresAt: '2027-01-04T23:00:00+00:00',
    planning: { stableId: 'p1', name: 'Gardes 2027' },
    line: { stableId: 'l1', name: 'Seniors' },
    requester: ALICE,
    offered: makeUnit(['2027-01-05']),
    recipients: [BOB],
    viewerRole: 'REQUESTER',
    acceptedProposalStableId: null,
    proposals: [makeProposal()],
    actions: { cancel: true, propose: false },
    history: [
      {
        stableId: 'e1',
        type: 'REQUEST_CREATED',
        occurredAt: '2026-12-10T09:00:00+00:00',
        actor: ALICE,
        proposalStableId: null,
        data: {},
      },
    ],
    proposableUnits: [],
    ...overrides,
  }
}

export function makeOptions(overrides: Partial<SwapOptions> = {}): SwapOptions {
  return {
    offered: makeUnit(['2027-01-05']),
    colleagues: [
      { ...ADAM, units: [makeUnit(['2027-01-19'])] },
      {
        ...BOB,
        units: [makeUnit(['2027-01-12']), makeUnit(['2027-01-23', '2027-01-24'], { blockName: 'Week-end' })],
      },
    ],
    openRequestStableId: null,
    ...overrides,
  }
}
