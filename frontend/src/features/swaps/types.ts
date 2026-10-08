/** Duty swaps between members (docs/duty-swaps.md, docs/decisions.md D178) — the API's shapes. */

/** One duty unit — a block once, with all its days. */
export type SwapUnit = {
  dutyStableId: string
  planningStableId: string
  planningName: string
  lineStableId: string
  lineName: string
  dutyTypeName: string
  blockName: string | null
  /** Calendar days in the planning's timezone ("yyyy-mm-dd"), sorted. */
  dates: string[]
  startsAt: string
  endsAt: string
}

export type SwapPerson = { userStableId: string; firstName: string; lastName: string }

export type SwapRequestStatus = 'OPEN' | 'COMPLETED' | 'REFUSED' | 'CANCELLED' | 'EXPIRED' | 'OBSOLETE'

export type SwapProposalStatus =
  'PENDING' | 'ACCEPTED' | 'REFUSED' | 'WITHDRAWN' | 'NOT_SELECTED' | 'CANCELLED' | 'EXPIRED' | 'OBSOLETE'

export type SwapProposal = {
  stableId: string
  status: SwapProposalStatus
  author: SwapPerson
  /** Who holds `counterpartUnit` today (and would take the request's unit). */
  counterpart: SwapPerson
  /** The one person who may accept or refuse it. */
  decider: SwapPerson
  counterpartUnit: SwapUnit
  createdAt: string
  decidedAt: string | null
  actions: { accept: boolean; refuse: boolean; withdraw: boolean }
}

export type SwapEventType =
  | 'REQUEST_CREATED'
  | 'REQUEST_SENT'
  | 'PROPOSAL_CREATED'
  | 'PROPOSAL_WITHDRAWN'
  | 'PROPOSAL_REFUSED'
  | 'PROPOSAL_ACCEPTED'
  | 'PROPOSAL_NOT_SELECTED'
  | 'PROPOSAL_OBSOLETE'
  | 'SWAP_COMPLETED'
  | 'REQUEST_REFUSED'
  | 'REQUEST_CANCELLED'
  | 'REQUEST_EXPIRED'
  | 'REQUEST_OBSOLETE'
  | 'SWAP_VALIDATION_FAILED'

export type SwapEvent = {
  stableId: string
  type: SwapEventType
  occurredAt: string
  actor: SwapPerson | null
  proposalStableId: string | null
  data: Record<string, unknown>
}

export type SwapRequest = {
  stableId: string
  kind: 'AGREED' | 'SEARCH'
  audience: 'SELECTED' | 'ALL'
  status: SwapRequestStatus
  createdAt: string
  closedAt: string | null
  expiresAt: string
  planning: { stableId: string; name: string }
  line: { stableId: string; name: string }
  requester: SwapPerson
  offered: SwapUnit
  /** Shown to the requester, the recipients and managers only. */
  recipients: SwapPerson[]
  viewerRole: 'REQUESTER' | 'RECIPIENT' | 'TEAM' | 'MANAGER'
  acceptedProposalStableId: string | null
  proposals: SwapProposal[]
  actions: { cancel: boolean; propose: boolean }
}

export type SwapRequestDetail = SwapRequest & {
  history: SwapEvent[]
  /** The viewer's own future units on the same line, when they may propose. */
  proposableUnits: SwapUnit[]
  /** Set by "accept": true when the swap had already been applied (a repeated click). */
  alreadyApplied?: boolean
}

export type SwapOverview = { mine: SwapRequest[]; received: SwapRequest[]; team: SwapRequest[] }

export type SwapColleague = SwapPerson & { units: SwapUnit[] }

export type SwapOptions = {
  offered: SwapUnit
  colleagues: SwapColleague[]
  openRequestStableId: string | null
}

export type CreateSwapRequestBody = {
  dutyStableId: string
  kind: 'AGREED' | 'SEARCH'
  audience: 'SELECTED' | 'ALL'
  recipientUserStableIds: string[]
  counterpartDutyStableId?: string
  acknowledgedResponsibility: boolean
}
