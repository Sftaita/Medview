export type CollectionStatus = 'OPEN' | 'CLOSED'

/** PENDING ("À renseigner"), ACKNOWLEDGED ("Répondu"), WITHDRAWN (left the planning before answering). */
export type ResponseStatus = 'PENDING' | 'ACKNOWLEDGED' | 'WITHDRAWN'

export type AcknowledgementKind = 'CONFIRMED' | 'NO_UNAVAILABILITY'

/** The current user's own answer to a collection. */
export type MyCollectionResponse = {
  stableId: string
  status: ResponseStatus
  acknowledgedAt: string | null
  acknowledgementKind: AcknowledgementKind | null
  lastAvailabilityChangeAt: string | null
}

export type CollectionProgress = { expected: number; acknowledged: number; pending: number }

/**
 * "Who has reviewed their availabilities for this slice of the planning".
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
