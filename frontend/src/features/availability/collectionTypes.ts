export type CollectionStatus = 'OPEN' | 'CLOSED'

/**
 * docs/decisions.md D214: PARTICIPANT (told by the announcement and the
 * reminders) or WITHDRAWN (left the planning before the window). Nobody
 * confirms anything: without any unavailability, a person is available.
 */
export type ResponseStatus = 'PARTICIPANT' | 'WITHDRAWN'

/** The current user's own participation in a collection. */
export type MyCollectionResponse = {
  stableId: string
  status: ResponseStatus
  lastAvailabilityChangeAt: string | null
}

export type CollectionProgress = { participants: number }

/**
 * A window of a planning whose participants are invited to keep their calendar up to date.
 * Dates are calendar dates ("YYYY-MM-DD") in the planning's timezone;
 * `endsAt` is exclusive, `lastDay` is the inclusive last day for display.
 */
export type AvailabilityCollection = {
  stableId: string
  planningStableId: string
  planningName: string
  startsAt: string
  endsAt: string
  lastDay: string
  openedAt: string
  deadline: string | null
  status: CollectionStatus
  closedAt: string | null
  timezone: string
  canManage: boolean
  myResponse: MyCollectionResponse | null
  /** The period it belongs to (docs/planning-cycles.md I12). */
  period?: { stableId: string; number: number } | null
  /** Only present for someone who may manage the collection. */
  progress?: CollectionProgress
}

/** One row of the manager's follow-up list. */
export type CollectionResponseRow = MyCollectionResponse & {
  user: { stableId: string; firstName: string; lastName: string }
}

export type CreateCollectionInput = {
  startsAt: string
  endsAt: string
  deadline?: string
}

export type ExtendPlanningInput = {
  startsAt?: string
  endsAt?: string
  deadline?: string
}

export type ExtendPlanningResult = {
  planning: { stableId: string; startsAt: string; endsAt: string }
  collections: { stableId: string; startsAt: string; endsAt: string; deadline: string | null }[]
}
