import { describe, expect, it } from 'vitest'
import { addDays } from './dates'
import {
  MAX_REMINDERS,
  dueOf,
  freeReminderOffset,
  noFreeReminderReason,
  type ReminderContext,
} from './preparationRules'

/** Trauma Delta, period 2 in production: announcement 17 Jan, target 24 Jan, a reminder 2 days before. */
const TRAUMA: ReminderContext = {
  today: '2026-10-28',
  target: '2027-01-24',
  announceOn: '2027-01-17',
  taken: ['2027-01-22'],
  count: 1,
}

describe('dueOf', () => {
  it('counts days and weeks back from the target date', () => {
    expect(dueOf('2027-01-24', 2, 'DAY')).toBe('2027-01-22')
    expect(dueOf('2027-01-24', 1, 'WEEK')).toBe('2027-01-17')
    expect(dueOf('2027-01-24', 2, 'WEEK')).toBe('2027-01-10')
  })
})

describe('freeReminderOffset — what "Ajouter un rappel" proposes', () => {
  it('Trauma Delta: never 7 days (the announcement day), but 6 days, the 18th', () => {
    expect(freeReminderOffset(TRAUMA)).toBe(6)
    expect(noFreeReminderReason(TRAUMA)).toBeNull()
  })

  it('keeps the usual week when that day is free and after the announcement', () => {
    expect(freeReminderOffset({ ...TRAUMA, announceOn: '2027-01-10', taken: [] })).toBe(7)
    expect(freeReminderOffset({ ...TRAUMA, announceOn: null, taken: [] })).toBe(7)
  })

  it('skips every day already taken, by a reminder in days or in weeks', () => {
    const taken = ['2027-01-18', '2027-01-22', dueOf('2027-01-24', 1, 'WEEK')]
    expect(freeReminderOffset({ ...TRAUMA, announceOn: '2027-01-10', taken })).toBe(5)
  })

  it('goes beyond a week when every day under it is taken', () => {
    const taken = [1, 2, 3, 4, 5, 6, 7].map((d) => addDays('2027-01-24', -d))
    expect(freeReminderOffset({ ...TRAUMA, announceOn: null, taken, count: 7 })).toBe(8)
  })

  it('never proposes a day on or before the announcement, nor a day already past', () => {
    for (let a = 1; a <= 20; a++) {
      const announceOn = addDays('2027-01-24', -a)
      const offset = freeReminderOffset({ ...TRAUMA, announceOn, taken: [] })
      if (offset !== null) expect(addDays('2027-01-24', -offset) > announceOn).toBe(true)
    }
    // Target in 3 days, no announcement: at most 3 days before (today itself), never yesterday.
    expect(
      freeReminderOffset({
        today: '2026-10-28',
        target: '2026-10-31',
        announceOn: null,
        taken: [],
        count: 0,
      }),
    ).toBe(3)
  })

  it('has nothing left when every day between the announcement and the target is taken', () => {
    const full = { ...TRAUMA, announceOn: '2027-01-21', taken: ['2027-01-22', '2027-01-23'], count: 2 }
    expect(freeReminderOffset(full)).toBeNull()
    expect(noFreeReminderReason(full)).toBe('no_free_reminder_day')
  })

  it('asks for the target date first, and stops at ten reminders', () => {
    expect(noFreeReminderReason({ ...TRAUMA, target: null })).toBe('reminders_need_target')
    expect(freeReminderOffset({ ...TRAUMA, target: null })).toBeNull()
    expect(noFreeReminderReason({ ...TRAUMA, announceOn: null, count: MAX_REMINDERS })).toBe(
      'too_many_reminders',
    )
  })
})
