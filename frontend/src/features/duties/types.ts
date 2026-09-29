/** One duty unit held by the signed-in user (GET /api/me/duties, docs/decisions.md D168) — a block once, with all its days. */
export type MyDuty = {
  key: string
  planningStableId: string
  planningName: string
  lineStableId: string
  lineName: string
  dutyTypeName: string
  /** The block's name, null for a standalone duty. */
  blockName: string | null
  /** Calendar days in the planning's timezone ("yyyy-mm-dd"), sorted. */
  dates: string[]
  startsAt: string
  endsAt: string
  /** A reinforcement of a conditional line. */
  conditional: boolean
  /** Its live state (D165), null for an intrinsic duty. */
  coverageState: 'REQUIRED_ASSIGNED' | 'NOT_REQUIRED_ASSIGNED' | 'UNDETERMINED' | null
}
