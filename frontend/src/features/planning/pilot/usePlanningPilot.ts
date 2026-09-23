import { useCallback, useEffect, useState } from 'react'
import { fetchCollectionStatus } from './api'
import type { CollectionStatus } from './types'

/**
 * The OWNER/ADMIN pilot data of a planning: who confirmed, the deadline, the
 * counters. Loaded only for someone allowed to see it (`enabled`); `reload`
 * refreshes it in place (no spinner) after a reminder, a settings change or a
 * generation, so the page keeps what it shows meanwhile.
 */
export function usePlanningPilot(planningStableId: string, enabled: boolean) {
  const [status, setStatus] = useState<CollectionStatus | null>(null)
  const [error, setError] = useState<string | null>(null)

  const reload = useCallback(() => {
    return fetchCollectionStatus(planningStableId)
      .then((result) => {
        setStatus(result)
        setError(null)
      })
      .catch(() => setError('Impossible de charger le suivi de la collecte.'))
  }, [planningStableId])

  useEffect(() => {
    if (enabled) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      void reload()
    }
  }, [enabled, reload])

  return { status, error, reload }
}
