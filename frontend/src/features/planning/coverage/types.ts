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
  /** docs/decisions.md D224 — the blocks a reinforcement of this line would inherit, shown before saving. */
  structure?: InheritedStructure
}

/** A source line's weekly structure, read-only on the reinforcement line (D224). */
export type InheritedStructure = {
  blocks: { name: string; weekdays: ApiWeekday[] }[]
  soloWeekdays: ApiWeekday[]
  excludedWeekdays: ApiWeekday[]
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
  /** docs/decisions.md D224 — INHERITED for every reinforcement configured now; OWN for one recorded before. */
  structureSource?: 'OWN' | 'INHERITED' | null
  /** The line whose blocks this one inherits ($targetStructure is then its structure). */
  inheritedFrom?: { lineStableId: string; name: string } | null
}

/** POST .../demand-policy/preview — the reinforcements a configuration would ask for (D224), nothing saved. */
export type DemandPreview = {
  periods: {
    period: { stableId: string; number: number | null; startsAt: string; endsAt: string }
    /** Already generated: its frozen rules stay; this configuration applies to the next periods. */
    generated: boolean
    /** The source line has a calendar in that period — otherwise nothing can be known yet. */
    sourceGenerated: boolean
    dutyCount: number
    units: { dates: string[]; block: boolean; sourceHolder: DemandPerson | null }[]
  }[]
}

/** The PUT body (schema version 1). */
export type DemandPolicyInput = {
  schemaVersion: 1
  mode: DemandMode
  source: { lineStableId: string } | null
  triggers: { userStableId: string; weekdays: ApiWeekday[]; increment: number }[]
}
