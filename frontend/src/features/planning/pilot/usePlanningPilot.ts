import { useCallback, useEffect, useRef, useState } from 'react'
import { fetchCollectionStatus } from './api'
import type { CollectionStatus } from './types'

/**
 * The OWNER/ADMIN pilot data of a planning: who confirmed, the deadline, the
 * counters. Loaded only for someone allowed to see it (`enabled`); `reload`
 * refreshes it in place (no spinner) after a reminder, a settings change or a
 * generation, so the page keeps what it shows meanwhile.
 *
 * Per period (docs/planning-cycles.md §7.4, lot L6): another period empties
 * it at once, and an answer that arrives for a period no longer selected is
 * dropped — never shown under the wrong period.
 */
export function usePlanningPilot(planningStableId: string, enabled: boolean, periodStableId?: string) {
  const [loaded, setLoaded] = useState<{
    key: string
    status: CollectionStatus | null
    error: string | null
  }>({
    key: '',
    status: null,
    error: null,
  })
  const key = `${planningStableId}|${periodStableId ?? ''}`
  const currentKey = useRef(key)
  useEffect(() => {
    currentKey.current = key
  }, [key])

  const reload = useCallback(() => {
    const requested = `${planningStableId}|${periodStableId ?? ''}`
    return fetchCollectionStatus(planningStableId, periodStableId)
      .then((result) => {
        if (currentKey.current !== requested) return
        if (periodStableId && result.period && result.period.stableId !== periodStableId) return
        setLoaded({ key: requested, status: result, error: null })
      })
      .catch(() => {
        if (currentKey.current !== requested) return
        setLoaded((previous) => ({
          key: requested,
          status: previous.key === requested ? previous.status : null,
          error: 'Impossible de charger le suivi de la collecte.',
        }))
      })
  }, [planningStableId, periodStableId])

  useEffect(() => {
    if (enabled) {
      void reload()
    }
  }, [enabled, reload])

  // What was loaded for another period is never returned.
  const current = loaded.key === key
  return { status: current ? loaded.status : null, error: current ? loaded.error : null, reload }
}
