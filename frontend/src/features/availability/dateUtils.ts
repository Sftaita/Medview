/** Local calendar date as 'YYYY-MM-DD', never UTC-shifted (contrast with Date#toISOString). */
export function toDateKey(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export function parseDateKey(key: string): Date {
  const [year, month, day] = key.split('-').map(Number)
  return new Date(year, month - 1, day)
}

/**
 * Sorts and splits a set of day keys into consecutive-day runs — the
 * calendar UI groups a multi-day selection into one API period per run,
 * since the backend's overlap-or-touch policy would otherwise reject
 * separately-created adjacent full-day periods (see docs/availability.md).
 */
export function groupContiguousDateKeys(keys: Iterable<string>): string[][] {
  const sorted = [...keys].sort()
  const runs: string[][] = []

  for (const key of sorted) {
    const previousRun = runs[runs.length - 1]
    const previousKey = previousRun?.[previousRun.length - 1]

    if (previousKey !== undefined) {
      const nextExpected = new Date(parseDateKey(previousKey))
      nextExpected.setDate(nextExpected.getDate() + 1)
      if (toDateKey(nextExpected) === key) {
        previousRun.push(key)
        continue
      }
    }

    runs.push([key])
  }

  return runs
}

/** Local midnight at the start of the given day key. */
export function startOfDay(dateKey: string): Date {
  return parseDateKey(dateKey)
}

/** Local midnight at the start of the day *after* the given day key — the exclusive end of a full-day run. */
export function startOfNextDay(dateKey: string): Date {
  const date = parseDateKey(dateKey)
  date.setDate(date.getDate() + 1)
  return date
}
