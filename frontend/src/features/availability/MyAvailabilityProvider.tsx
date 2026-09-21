import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ApiError } from '../../lib/apiClient'
import { createCalendarPeriod, deleteCalendarPeriod, fetchMyCalendar, updateCalendarPeriod } from './api'
import { acknowledgeCollection, fetchMyCollections } from './collectionApi'
import type { AcknowledgementKind, AvailabilityCollection } from './collectionTypes'
import { MyAvailabilityContext, type MyAvailabilityContextValue } from './context'
import { periodsToRanges, planSync, rangeToInput } from './periodMapping'
import { sameRanges, type DayRange } from './selection'
import type { UserAvailabilityPeriod } from './types'

/** A save round re-plans against what the server now holds; it always converges in one or two rounds. */
const MAX_SYNC_ROUNDS = 10

function syncErrorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    return 'Une période de même type chevauche ou touche déjà ces dates. La modification a été annulée.'
  }
  if (err instanceof ApiError && err.status === 422) {
    return 'Cette période est invalide. La modification a été annulée.'
  }
  return "Impossible d'enregistrer votre modification : elle a été annulée. Vérifiez votre connexion puis réessayez."
}

/**
 * The single source of truth for the user's own calendar and their open
 * availability collections, shared by the dashboard and the calendar page
 * (docs/availability.md §9, docs/decisions.md D126).
 *
 * - **Optimistic**: `editRanges` changes what everyone reads immediately, and
 *   the change is saved in the background — one DELETE / PATCH / POST per
 *   changed period, computed by `planSync` from the difference between what
 *   the screen shows and what the server is known to hold.
 * - **One save at a time**: edits made while a save is in flight are simply
 *   picked up by the next round, so requests never overlap or reorder.
 * - **No silent divergence**: when any request fails, the screen goes back to
 *   what the server actually holds (re-read, not guessed) and the error is
 *   surfaced. Edits batched into the same round are rolled back together.
 * - It lives above the routes, so navigating away from the calendar page
 *   never cancels a save in flight.
 */
export function MyAvailabilityProvider({ children }: { children: ReactNode }) {
  const [ranges, setRanges] = useState<DayRange[] | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [syncing, setSyncing] = useState(false)
  const [syncError, setSyncError] = useState<string | null>(null)
  const [lastSavedAt, setLastSavedAt] = useState<number | null>(null)
  const [collections, setCollections] = useState<AvailabilityCollection[] | null>(null)

  const rangesRef = useRef<DayRange[] | null>(null)
  /** What the server is known to hold, updated after every successful request. */
  const serverRef = useRef<UserAvailabilityPeriod[]>([])
  const runningRef = useRef(false)
  /** Bumped by every edit: a slow read that started before an edit must never overwrite it. */
  const editVersionRef = useRef(0)

  const adopt = useCallback((next: DayRange[]) => {
    rangesRef.current = next
    setRanges(next)
  }, [])

  const refreshCollections = useCallback(async () => {
    try {
      setCollections(await fetchMyCollections())
    } catch {
      // The dashboard is a summary, never a gate: keep what we had (or an empty list on first failure).
      setCollections((previous) => previous ?? [])
    }
  }, [])

  const reload = useCallback(async () => {
    const versionAtStart = editVersionRef.current
    try {
      const periods = await fetchMyCalendar()
      if (runningRef.current || editVersionRef.current !== versionAtStart) {
        return
      }
      serverRef.current = periods
      const next = periodsToRanges(periods)
      if (rangesRef.current === null || !sameRanges(rangesRef.current, next)) {
        adopt(next)
      }
      setLoadError(null)
    } catch {
      if (rangesRef.current === null) {
        setLoadError('Impossible de charger votre calendrier.')
      }
    }
  }, [adopt])

  useEffect(() => {
    // Initial load, once per authenticated session.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void reload()
    void refreshCollections()
  }, [reload, refreshCollections])

  const sync = useCallback(async () => {
    if (runningRef.current) {
      // The running loop re-plans after its current requests: this edit is picked up there.
      return
    }
    runningRef.current = true
    setSyncing(true)

    try {
      for (let round = 0; ; round++) {
        const plan = planSync(serverRef.current, rangesRef.current ?? [])
        if (plan.toDelete.length + plan.toUpdate.length + plan.toCreate.length === 0) {
          break
        }
        if (round >= MAX_SYNC_ROUNDS) {
          throw new Error('The calendar did not converge with the server.')
        }

        // Deletions first, then in-place edits (shrinks before growths), then creations: the backend
        // refuses two periods of the same nature that overlap or touch, even for an instant.
        for (const period of plan.toDelete) {
          await deleteCalendarPeriod(period.stableId)
          serverRef.current = serverRef.current.filter((known) => known.stableId !== period.stableId)
        }
        for (const { period, range } of plan.toUpdate) {
          const saved = await updateCalendarPeriod(period.stableId, rangeToInput(range))
          serverRef.current = serverRef.current.map((known) =>
            known.stableId === saved.stableId ? saved : known,
          )
        }
        for (const range of plan.toCreate) {
          const saved = await createCalendarPeriod(rangeToInput(range))
          serverRef.current = [...serverRef.current, saved]
        }
      }
      setLastSavedAt(Date.now())
      // Touching the calendar inside a collection's window updates its "last change" server-side.
      void refreshCollections()
    } catch (err) {
      // Roll back to the truth: re-read the server rather than guess which requests went through.
      try {
        serverRef.current = await fetchMyCalendar()
      } catch {
        // Offline: fall back on what we knew after the last successful request.
      }
      adopt(periodsToRanges(serverRef.current))
      setSyncError(syncErrorMessage(err))
    } finally {
      runningRef.current = false
      setSyncing(false)
    }
  }, [adopt, refreshCollections])

  const editRanges = useCallback(
    (updater: (previous: DayRange[]) => DayRange[]) => {
      const current = rangesRef.current
      if (current === null) {
        return
      }
      const next = updater(current)
      if (sameRanges(current, next)) {
        return
      }
      editVersionRef.current += 1
      adopt(next)
      setSyncError(null)
      void sync()
    },
    [adopt, sync],
  )

  const acknowledge = useCallback(async (collectionStableId: string, kind: AcknowledgementKind) => {
    const updated = await acknowledgeCollection(collectionStableId, kind)
    setCollections((previous) =>
      (previous ?? []).map((collection) => (collection.stableId === updated.stableId ? updated : collection)),
    )
  }, [])

  const dismissSyncError = useCallback(() => setSyncError(null), [])

  const value = useMemo<MyAvailabilityContextValue>(
    () => ({
      ranges,
      loadError,
      syncing,
      syncError,
      lastSavedAt,
      editRanges,
      dismissSyncError,
      reload,
      collections,
      refreshCollections,
      acknowledge,
    }),
    [
      ranges,
      loadError,
      syncing,
      syncError,
      lastSavedAt,
      editRanges,
      dismissSyncError,
      reload,
      collections,
      refreshCollections,
      acknowledge,
    ],
  )

  return <MyAvailabilityContext.Provider value={value}>{children}</MyAvailabilityContext.Provider>
}
