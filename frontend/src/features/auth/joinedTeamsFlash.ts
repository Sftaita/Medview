import type { JoinedTeam } from './types'

const KEY = 'medvue.flash.joinedTeams'

/**
 * Hands the teams a new account just joined over to the dashboard, across
 * the redirect that follows registration. sessionStorage rather than router
 * state: PublicOnlyRoute also redirects to "/" the moment the session
 * appears, and that second navigation would drop router state depending on
 * timing.
 */
export function setJoinedTeamsFlash(teams: JoinedTeam[]): void {
  try {
    if (teams.length > 0) {
      sessionStorage.setItem(KEY, JSON.stringify(teams))
    }
  } catch {
    // Storage unavailable (private mode): the message is a nicety, not a requirement.
  }
}

/** Non-destructive, so it is safe inside a state initializer (StrictMode runs those twice). */
export function readJoinedTeamsFlash(): JoinedTeam[] {
  try {
    const raw = sessionStorage.getItem(KEY)
    return raw ? (JSON.parse(raw) as JoinedTeam[]) : []
  } catch {
    return []
  }
}

/** Called once the message has been displayed: it is shown a single time. */
export function clearJoinedTeamsFlash(): void {
  try {
    sessionStorage.removeItem(KEY)
  } catch {
    // Nothing to clear.
  }
}
