/**
 * The demand policy of a planning line (docs/decisions.md D162), as the API
 * returns it: `GET|PUT /api/planning-lines/{id}/demand-policy`.
 */

/** The API's weekday names — never compared with the screen's "Lun…Dim" (see weekdays.ts). */
export type ApiWeekday = 'MONDAY' | 'TUESDAY' | 'WEDNESDAY' | 'THURSDAY' | 'FRIDAY' | 'SATURDAY' | 'SUNDAY'

export type DemandMode = 'INDEPENDENT' | 'CONDITIONAL_ON_SOURCE_ASSIGNMENT'

export type DemandPerson = { userStableId: string; firstName: string; lastName: string }

export type DemandTrigger = DemandPerson & { weekdays: ApiWeekday[]; increment: number }

/** A line the backend accepts as "ligne à renforcer", with the people who hold its duties. */
export type DemandSourceOption = {
  lineStableId: string
  name: string
  type: 'PRIMARY' | 'SECONDARY'
  people: DemandPerson[]
}

/** A structured, non-blocking warning computed by the backend — never recomputed here. */
export type DemandPolicyWarning = {
  code:
    | 'TARGET_HAS_NO_WEEK_STRUCTURE'
    | 'TRIGGER_PERSON_NOT_IN_SOURCE_LINE'
    | 'TRIGGER_DAY_EXCLUDED_FROM_TARGET'
    | 'TRIGGER_PARTIALLY_COVERS_TARGET_BLOCK'
    | string
  details: Record<string, unknown>
  /** Already in plain French. */
  message: string
}

export type DemandPolicyView = {
  schemaVersion: number
  line: { stableId: string; name: string; type: 'PRIMARY' | 'SECONDARY' }
  mode: DemandMode
  /** The active version, null when the line never had a policy (independent). */
  policy: { stableId: string; version: number; createdAt: string } | null
  source: { lineStableId: string; name: string | null } | null
  triggers: DemandTrigger[]
  weekdays: ApiWeekday[]
  sourceOptions: DemandSourceOption[]
  /** The line's own weekly structure: the days it has no duty, and its blocks. */
  targetStructure: {
    configured: boolean
    excludedWeekdays: ApiWeekday[]
    blocks: { name: string; weekdays: ApiWeekday[] }[]
  }
  warnings: DemandPolicyWarning[]
}

/** The PUT body (schema version 1). */
export type DemandPolicyInput = {
  schemaVersion: 1
  mode: DemandMode
  source: { lineStableId: string } | null
  triggers: { userStableId: string; weekdays: ApiWeekday[]; increment: number }[]
}
