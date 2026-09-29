import { describe, expect, it } from 'vitest'
import { apiUrl } from '../../lib/apiClient'
import { subscriptionLinks } from './calendarFeed'

const TOKEN = 'ab'.repeat(32)
const FEED = `https://api.medvue.be/api/calendar-feeds/${TOKEN}.ics`

describe('subscriptionLinks', () => {
  it('points at the backend feed of the token by default', () => {
    expect(subscriptionLinks(TOKEN).feed).toBe(apiUrl(`/api/calendar-feeds/${TOKEN}.ics`))
  })

  it('gives Apple Calendar the webcal address', () => {
    expect(subscriptionLinks(TOKEN, FEED).webcal).toBe(
      `webcal://api.medvue.be/api/calendar-feeds/${TOKEN}.ics`,
    )
    expect(subscriptionLinks(TOKEN, 'http://localhost:8010/x.ics').webcal).toBe(
      'webcal://localhost:8010/x.ics',
    )
  })

  it('gives Google Calendar the encoded webcal address as cid', () => {
    const google = new URL(subscriptionLinks(TOKEN, FEED).google)
    expect(google.origin + google.pathname).toBe('https://calendar.google.com/calendar/render')
    expect(google.searchParams.get('cid')).toBe(`webcal://api.medvue.be/api/calendar-feeds/${TOKEN}.ics`)
  })

  it('gives both Outlooks the https address and the calendar name', () => {
    const links = subscriptionLinks(TOKEN, FEED)
    for (const [href, host] of [
      [links.outlookCom, 'outlook.live.com'],
      [links.outlook365, 'outlook.office.com'],
    ]) {
      const url = new URL(href)
      expect(url.host).toBe(host)
      expect(url.pathname).toBe('/calendar/0/addfromweb')
      expect(url.searchParams.get('url')).toBe(FEED)
      expect(url.searchParams.get('name')).toBe('MedVue — Mes gardes')
    }
  })
})
