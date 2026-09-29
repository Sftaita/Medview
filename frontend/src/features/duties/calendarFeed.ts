import { apiFetch, apiUrl } from '../../lib/apiClient'

/** The secret subscription address of "Mes gardes" (docs/decisions.md D170). */
export type CalendarFeed = {
  token: string
  createdAt: string
  /** Last time a calendar app fetched it (refreshed at most hourly), null until one did. */
  lastFetchedAt: string | null
}

type FeedResponse = { feed: CalendarFeed | null }

export function fetchCalendarFeed(): Promise<CalendarFeed | null> {
  return apiFetch<FeedResponse>('/api/me/calendar-feed').then((body) => body.feed)
}

export function enableCalendarFeed(): Promise<CalendarFeed> {
  return apiFetch<FeedResponse>('/api/me/calendar-feed', { method: 'POST' }).then((body) => body.feed!)
}

export function regenerateCalendarFeed(): Promise<CalendarFeed> {
  return apiFetch<FeedResponse>('/api/me/calendar-feed/regenerate', { method: 'POST' }).then(
    (body) => body.feed!,
  )
}

export function disableCalendarFeed(): Promise<void> {
  return apiFetch<null>('/api/me/calendar-feed', { method: 'DELETE' }).then(() => undefined)
}

export const CALENDAR_NAME = 'MedVue — Mes gardes'

export type SubscriptionLinks = {
  /** The feed itself (https in production): what "copy the link" gives. */
  feed: string
  /** Same address with the webcal scheme: opens the subscription dialog of Apple Calendar (iPhone, Mac). */
  webcal: string
  google: string
  outlookCom: string
  outlook365: string
}

/**
 * Every way to add the feed to a calendar app. Google takes the webcal address through `cid`; Outlook's
 * "add from web" takes the https one. None of them copies the events: each keeps polling the address.
 */
export function subscriptionLinks(
  token: string,
  feedUrl = apiUrl(`/api/calendar-feeds/${token}.ics`),
): SubscriptionLinks {
  const webcal = feedUrl.replace(/^https?:\/\//, 'webcal://')
  const outlook = (host: string) =>
    `https://${host}/calendar/0/addfromweb?url=${encodeURIComponent(feedUrl)}&name=${encodeURIComponent(CALENDAR_NAME)}`
  return {
    feed: feedUrl,
    webcal,
    google: `https://calendar.google.com/calendar/render?cid=${encodeURIComponent(webcal)}`,
    outlookCom: outlook('outlook.live.com'),
    outlook365: outlook('outlook.office.com'),
  }
}
