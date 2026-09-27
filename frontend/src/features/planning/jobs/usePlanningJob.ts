import { useCallback, useEffect, useRef, useState } from 'react'
import { fetchLatestJob } from '../pilot/api'
import type { PlanningJob } from '../pilot/types'

/** How often an active job is re-read. The solver gives no reliable progress, so no percentage either. */
export const JOB_POLL_INTERVAL_MS = 3000

export function isActiveJob(job: PlanningJob | null | undefined): boolean {
  return job?.status === 'QUEUED' || job?.status === 'RUNNING'
}

/**
 * The engine job of a planning, always read from the server
 * (docs/decisions.md D149): on mount — so leaving the page and coming back,
 * or opening it in another tab, finds a running generation again — then
 * every JOB_POLL_INTERVAL_MS while it is QUEUED/RUNNING, and never again
 * once it is terminal.
 *
 * `finished` is the job that this screen saw go from active to terminal —
 * the moment to refresh the calendar and say how it went; `onFinished` is
 * called exactly once for it. `track()` starts following a job just
 * launched from this screen.
 */
export function usePlanningJob(planningStableId: string, onFinished?: (job: PlanningJob) => void) {
  const [job, setJob] = useState<PlanningJob | null>(null)
  const [finished, setFinished] = useState<PlanningJob | null>(null)
  const [loaded, setLoaded] = useState(false)
  const lastSeen = useRef<PlanningJob | null>(null)
  const onFinishedRef = useRef(onFinished)
  useEffect(() => {
    onFinishedRef.current = onFinished
  }, [onFinished])

  const receive = useCallback((next: PlanningJob | null) => {
    const previous = lastSeen.current
    lastSeen.current = next
    setJob(next)
    setLoaded(true)
    if (
      previous &&
      next &&
      previous.stableId === next.stableId &&
      isActiveJob(previous) &&
      !isActiveJob(next)
    ) {
      setFinished(next)
      onFinishedRef.current?.(next)
    }
  }, [])

  const refresh = useCallback(() => {
    return fetchLatestJob(planningStableId)
      .then((value) => receive(value.job))
      .catch(() => setLoaded(true))
  }, [planningStableId, receive])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const active = isActiveJob(job)
  useEffect(() => {
    if (!active) {
      return
    }
    const timer = window.setTimeout(() => void refresh(), JOB_POLL_INTERVAL_MS)
    return () => window.clearTimeout(timer)
  }, [active, job, refresh])

  /** Follow a job this screen just launched (the POST answered with it, QUEUED). */
  const track = useCallback(
    (launched: PlanningJob) => {
      setFinished(null)
      receive(launched)
    },
    [receive],
  )

  const dismiss = useCallback(() => setFinished(null), [])

  return { job, finished, active, loaded, track, dismiss, refresh }
}
