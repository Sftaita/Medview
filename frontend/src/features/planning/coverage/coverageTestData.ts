import type { DemandPolicyView } from './types'

export const SENIORS = 'line-seniors'
export const AUTRE = 'line-autre'
export const RENFORT = 'line-renfort'

export const DR_A = { userStableId: 'u-a', firstName: 'Anne', lastName: 'Admin' }
export const DR_B = { userStableId: 'u-b', firstName: 'Alice', lastName: 'Bernard' }
export const DR_C = { userStableId: 'u-c', firstName: 'Bob', lastName: 'Claes' }

/** GET /api/planning-lines/{id}/demand-policy for the "Renfort" line, independent by default. */
export function policyView(overrides: Partial<DemandPolicyView> = {}): DemandPolicyView {
  return {
    schemaVersion: 1,
    line: { stableId: RENFORT, name: 'Renfort', type: 'SECONDARY' },
    mode: 'INDEPENDENT',
    policy: null,
    source: null,
    triggers: [],
    weekdays: ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'],
    sourceOptions: [
      // Dr B holds two membership stints on "Seniors": the backend may list them — one row only.
      { lineStableId: SENIORS, name: 'Seniors', type: 'PRIMARY', people: [DR_A, DR_B, DR_C, DR_B] },
      { lineStableId: AUTRE, name: 'Autre', type: 'SECONDARY', people: [DR_C] },
    ],
    targetStructure: { configured: true, excludedWeekdays: [], blocks: [] },
    warnings: [],
    ...overrides,
  }
}

/** The running example, saved: Dr A no day, Dr B every day, Dr C Friday to Sunday. */
export function conditionalView(overrides: Partial<DemandPolicyView> = {}): DemandPolicyView {
  return policyView({
    mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
    policy: { stableId: 'policy-1', version: 1, createdAt: '2026-12-01T10:00:00+00:00' },
    source: { lineStableId: SENIORS, name: 'Seniors' },
    triggers: [
      { ...DR_B, weekdays: ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'], increment: 1 },
      { ...DR_C, weekdays: ['FRIDAY', 'SATURDAY', 'SUNDAY'], increment: 1 },
    ],
    ...overrides,
  })
}
