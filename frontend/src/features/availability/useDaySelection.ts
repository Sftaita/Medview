import { useCallback, useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { monthIndexOfDay, type MonthInfo } from './calendarAxis'
import { addRange, cutRange, mergeRanges, toggleDay, type DayRange } from './selection'
import type { UserAvailabilityType } from './types'

/** Pointer must stay this long past the edge before the rail advances one month… */
const EDGE_DWELL_MS = 450
/** …and this long between two advances. Without it the rail jumps several months and the gesture is unusable. */
const EDGE_REPEAT_MS = 900
const EDGE_TICK_MS = 100

type Drag = { from: number; to: number; type: UserAvailabilityType; moved: boolean }

type Options = {
  months: MonthInfo[]
  /** Months shown side by side (1 on a phone, 2 on desktop). */
  visible: number
  initialPage: number
  initialRanges?: DayRange[]
}

/**
 * State and gestures of the multi-selection calendar. One set of pointer
 * gestures serves mouse and touch: tap toggles a day, press-and-drag draws a
 * period (each drag *adds* one), dragging to the edge scrolls the rail.
 */
export function useDaySelection({ months, visible, initialPage, initialRanges = [] }: Options) {
  const [ranges, setRanges] = useState<DayRange[]>(initialRanges)
  const [type, setType] = useState<UserAvailabilityType>('UNAVAILABLE')
  const [requestedPage, setPageState] = useState(initialPage)
  const [drag, setDrag] = useState<Drag | null>(null)
  const [anchor, setAnchor] = useState<number | null>(null)

  const dragRef = useRef<Drag | null>(null)
  const railRef = useRef<HTMLElement | null>(null)
  const pointerX = useRef<number | null>(null)
  const pendingPage = useRef<number | null>(null)
  const pendingSince = useRef(0)
  const lastAdvance = useRef(0)
  const timer = useRef<ReturnType<typeof setInterval> | null>(null)

  const maxPage = Math.max(0, months.length - visible)
  // The number of visible months changes with the viewport: derive a page that is always valid.
  const page = Math.max(0, Math.min(maxPage, requestedPage))
  const pageRef = useRef(page)

  useEffect(() => {
    dragRef.current = drag
  }, [drag])
  useEffect(() => {
    pageRef.current = page
  }, [page])

  const setPage = useCallback((next: number) => setPageState(Math.max(0, Math.min(maxPage, next))), [maxPage])

  const stopTimer = useCallback(() => {
    if (timer.current !== null) {
      clearInterval(timer.current)
      timer.current = null
    }
  }, [])

  /** The page the rail should move to, or null when the pointer is not asking for one. */
  const wantedPage = useCallback((): number | null => {
    const current = dragRef.current
    const rail = railRef.current
    if (!current || !current.moved || !rail || pointerX.current === null) {
      return null
    }
    const page = pageRef.current
    const rect = rail.getBoundingClientRect()
    const band = visible === 1 ? 36 : 64
    let want = page
    if (pointerX.current < rect.left + band) {
      want = page - 1
    } else if (pointerX.current > rect.right - band) {
      want = page + 1
    } else {
      const monthIndex = monthIndexOfDay(months, current.to)
      if (monthIndex > page + visible - 1) want = page + 1
      else if (monthIndex >= 0 && monthIndex < page) want = page - 1
    }
    return want < 0 || want > maxPage || want === page ? null : want
  }, [months, visible, maxPage])

  const tick = useCallback(() => {
    const want = wantedPage()
    const now = Date.now()
    if (want === null) {
      pendingPage.current = null
      return
    }
    if (now - lastAdvance.current < EDGE_REPEAT_MS) {
      return
    }
    if (pendingPage.current !== want) {
      pendingPage.current = want
      pendingSince.current = now
      return
    }
    if (now - pendingSince.current < EDGE_DWELL_MS) {
      return
    }
    lastAdvance.current = now
    pendingPage.current = null
    setPage(want)
  }, [wantedPage, setPage])

  const commit = useCallback(() => {
    stopTimer()
    const current = dragRef.current
    if (!current) {
      return
    }
    dragRef.current = null
    setDrag(null)
    if (!current.moved) {
      setRanges((previous) => toggleDay(previous, current.from, current.type))
    } else {
      setRanges((previous) => addRange(previous, current.from, current.to, current.type))
    }
    setAnchor(current.from)
  }, [stopTimer])

  useEffect(() => {
    window.addEventListener('pointerup', commit)
    window.addEventListener('pointercancel', commit)
    return () => {
      window.removeEventListener('pointerup', commit)
      window.removeEventListener('pointercancel', commit)
      stopTimer()
    }
  }, [commit, stopTimer])

  const startDrag = useCallback(
    (day: number, event: ReactPointerEvent<HTMLElement>) => {
      // Mouse: only the main button. Touch / pen report button 0 as well.
      if (event.button !== undefined && event.button > 0) {
        return
      }
      event.preventDefault()
      try {
        event.currentTarget.setPointerCapture?.(event.pointerId)
      } catch {
        // Capture is a nicety (keeps events flowing while the finger moves off the cell).
      }

      if (event.metaKey || event.ctrlKey) {
        setRanges((previous) => mergeRanges(cutRange(previous, day, day)))
        return
      }
      if (event.shiftKey && anchor !== null) {
        setRanges((previous) => addRange(previous, anchor, day, type))
        return
      }

      pendingPage.current = null
      lastAdvance.current = 0
      pointerX.current = event.clientX ?? null
      railRef.current = event.currentTarget.closest('[data-rail]') as HTMLElement | null
      const next: Drag = { from: day, to: day, type, moved: false }
      dragRef.current = next
      setDrag(next)
      stopTimer()
      timer.current = setInterval(tick, EDGE_TICK_MS)
    },
    [anchor, type, tick, stopTimer],
  )

  /** pointermove on the grid: resolves the day under the pointer (a touch stays bound to its first cell). */
  const trackPointer = useCallback((event: ReactPointerEvent<HTMLElement>) => {
    const current = dragRef.current
    if (!current) {
      return
    }
    pointerX.current = event.clientX ?? pointerX.current
    const under =
      typeof document.elementFromPoint === 'function'
        ? document.elementFromPoint(event.clientX, event.clientY)
        : null
    const host = ((under ?? event.target) as Element | null)?.closest?.('[data-day]')
    const day = host ? Number(host.getAttribute('data-day')) : NaN
    if (Number.isNaN(day) || day === current.to) {
      return
    }
    const next = { ...current, to: day, moved: true }
    dragRef.current = next
    setDrag(next)
  }, [])

  /** Keyboard activation (Enter / Space on a focused day): a click without a pointer. */
  const toggleFromKeyboard = useCallback(
    (day: number) => {
      setRanges((previous) => toggleDay(previous, day, type))
      setAnchor(day)
    },
    [type],
  )

  const effectiveRanges = drag && drag.moved ? addRange(ranges, drag.from, drag.to, drag.type) : ranges

  return {
    ranges,
    setRanges,
    effectiveRanges,
    type,
    setType,
    page,
    setPage,
    maxPage,
    drag,
    startDrag,
    trackPointer,
    toggleFromKeyboard,
    removeRange: (start: number, end: number) =>
      setRanges((previous) => mergeRanges(cutRange(previous, start, end))),
    clear: () => {
      setRanges([])
      setAnchor(null)
    },
    /** Called by the rail when the pointer moves over it (used for edge scrolling). */
    trackRailPointer: (event: ReactPointerEvent<HTMLElement>) => {
      if (dragRef.current) {
        pointerX.current = event.clientX ?? pointerX.current
      }
    },
  }
}
