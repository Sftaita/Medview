import { addDays } from './dates'
import type { ReminderUnit } from './api'
import type { ISODate } from './types'

/**
 * docs/decisions.md D210 — the client-side twin of the server's reminder
 * rules (PeriodPreparationService::applyReminders), so the panel says why
 * before saving and never proposes a reminder the server would refuse. The
 * server checks them all again: nothing here loosens a rule.
 *
 * A reminder runs on the target date minus its offset, at 08:00. It must
 * fall on a day not past (unless it is an existing reminder left on its
 * day), strictly after the announcement, and never on the same day as
 * another reminder.
 */

export const MAX_REMINDERS = 10
export const MAXIMUM: Record<ReminderUnit, number> = { DAY: 365, WEEK: 52 }

/** The usual first choice, then the closest offsets: a week before, else the latest day after the announcement. */
const DEFAULT_DAYS = 7

export function dueOf(target: ISODate, value: number, unit: ReminderUnit): ISODate {
  return addDays(target, -(unit === 'WEEK' ? 7 * value : value))
}

export interface ReminderContext {
  today: ISODate
  target: ISODate | null
  announceOn: ISODate | null
  /** The days already taken by the other reminders of the draft. */
  taken: ISODate[]
  count: number
}

export type NoFreeReminder = 'too_many_reminders' | 'reminders_need_target' | 'no_free_reminder_day'

/**
 * The offset, in days, proposed for a new reminder: 7 days if that day is
 * free and allowed, otherwise the closest allowed day under a week (the
 * latest one after the announcement), otherwise the closest beyond a week.
 * Null when no day is left, with the reason from noFreeReminderReason.
 */
export function freeReminderOffset(ctx: ReminderContext): number | null {
  if (ctx.count >= MAX_REMINDERS || !ctx.target) return null
  const target = ctx.target
  const candidates = [DEFAULT_DAYS]
  for (let d = DEFAULT_DAYS - 1; d >= 1; d--) candidates.push(d)
  for (let d = DEFAULT_DAYS + 1; d <= MAXIMUM.DAY; d++) candidates.push(d)
  return (
    candidates.find((days) => {
      const due = addDays(target, -days)
      return due >= ctx.today && (!ctx.announceOn || due > ctx.announceOn) && !ctx.taken.includes(due)
    }) ?? null
  )
}

export function noFreeReminderReason(ctx: ReminderContext): NoFreeReminder | null {
  if (ctx.count >= MAX_REMINDERS) return 'too_many_reminders'
  if (!ctx.target) return 'reminders_need_target'
  return freeReminderOffset(ctx) === null ? 'no_free_reminder_day' : null
}
