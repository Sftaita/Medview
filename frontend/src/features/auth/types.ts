export type CurrentUser = {
  id: number
  /** The identifier the API uses everywhere else (URLs, comparisons) — never the numeric id. */
  stableId: string
  email: string
  firstName: string
  lastName: string
  /** E.164 ("+32470123456"); null for accounts created before the field existed. */
  phone?: string | null
  active: boolean
  createdAt: string
  updatedAt: string
}

export type RegisterInput = {
  email: string
  plainPassword: string
  firstName: string
  lastName: string
  /** Any real phone number; the backend normalizes it to E.164. */
  phone: string
  /** Raw token of an invitation link: the backend then fixes the email and attaches the teams. */
  invitationToken?: string
}

/** A team joined automatically through an invitation. */
export type JoinedTeam = {
  teamStableId: string
  teamName: string
  planningStableId: string
  planningName: string
}

export type RegisterResult = CurrentUser & { joinedTeams: JoinedTeam[] }
