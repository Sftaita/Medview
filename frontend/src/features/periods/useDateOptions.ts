import { useCallback, useEffect, useRef, useState } from 'react'
import type { BlockCut, DateOptions, DateReason, DayOption } from './api'
import { addDays } from './dates'
import type { ISODate } from './types'

/** Loads the options of the days from `from` to `to` (inclusive) — one call per month shown. */
export type OptionsLoader = (from: ISODate, to: ISODate) => Promise<DateOptions>

/** "2027-01" for a date. */
export function monthOf(date: ISODate): string {
  return date.slice(0, 7)
}

/** The month after (+1) or before (-1) `month`. */
export function shiftMonth(month: string, delta: number): string {
  const [y, m] = month.split('-').map(Number)
  const d = new Date(Date.UTC(y, m - 1 + delta, 1))
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`
}

/** Every day the calendar shows for `month`: whole weeks, Monday to Sunday. */
export function monthGrid(month: string): ISODate[] {
  const first = `${month}-01`
  const weekday = (new Date(`${first}T00:00:00Z`).getUTCDay() + 6) % 7 // Monday = 0
  const start = addDays(first, -weekday)
  const last = addDays(`${shiftMonth(month, 1)}-01`, -1)
  const lastWeekday = (new Date(`${last}T00:00:00Z`).getUTCDay() + 6) % 7
  const end = addDays(last, 6 - lastWeekday)
  const days: ISODate[] = []
  for (let day = start; day <= end; day = addDays(day, 1)) days.push(day)
  return days
}

export type OptionsMeta = {
  editable: boolean
  blockers: DateReason[]
  historicalStartCuts: BlockCut[]
  fixedStart: string | null
  current: string | null
}

/**
 * The options of the date picker (docs/decisions.md D207), loaded month by
 * month and kept: the server says, for each day, whether it may be chosen
 * and why not. `resetKey` drops everything when what is chosen changes (another
 * period, another edge, new dates after a save).
 */
export function useDateOptions(loader: OptionsLoader | undefined, resetKey: string) {
  const [days, setDays] = useState<Record<string, DayOption>>({})
  const [meta, setMeta] = useState<OptionsMeta | null>(null)
  const [failed, setFailed] = useState(false)
  const [shownKey, setShownKey] = useState(resetKey)
  const loaded = useRef(new Set<string>())
  const loaderRef = useRef(loader)
  const keyRef = useRef(resetKey)

  useEffect(() => {
    loaderRef.current = loader
  }, [loader])

  // Declared before the caller's effects: they run after this one, on the new key.
  useEffect(() => {
    keyRef.current = resetKey
    loaded.current = new Set()
  }, [resetKey])

  if (shownKey !== resetKey) {
    setShownKey(resetKey)
    setDays({})
    setMeta(null)
    setFailed(false)
  }

  const ensure = useCallback((month: string) => {
    const load = loaderRef.current
    if (!load || loaded.current.has(month)) return
    loaded.current.add(month)
    const key = keyRef.current
    const grid = monthGrid(month)
    load(grid[0], grid[grid.length - 1]).then(
      (options) => {
        if (keyRef.current !== key) return // an answer for what is no longer on screen
        setDays((previous) => ({ ...previous, ...Object.fromEntries(options.days.map((d) => [d.date, d])) }))
        setMeta({
          editable: options.editable,
          blockers: options.blockers,
          historicalStartCuts: options.historicalStartCuts,
          fixedStart: options.fixedStart,
          current: options.current,
        })
      },
      () => {
        if (keyRef.current !== key) return
        loaded.current.delete(month)
        setFailed(true)
      },
    )
  }, [])

  return { days, meta, failed, ensure }
}
