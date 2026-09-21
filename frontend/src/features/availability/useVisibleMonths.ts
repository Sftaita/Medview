import { useEffect, useState } from 'react'

// Two months need ~1280px (sidebar + calendar + summary); below that the desktop layout shows one, larger.
const TWO_MONTHS_QUERY = '(min-width: 1280px)'

function visibleMonths(): number {
  // No matchMedia (tests, very old browsers): the desktop layout.
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
    return 2
  }
  return window.matchMedia(TWO_MONTHS_QUERY).matches ? 2 : 1
}

/** Two months side by side on a wide screen, one otherwise. */
export function useVisibleMonths(): number {
  const [visible, setVisible] = useState(visibleMonths)

  useEffect(() => {
    if (typeof window.matchMedia !== 'function') {
      return
    }
    const query = window.matchMedia(TWO_MONTHS_QUERY)
    const update = () => setVisible(query.matches ? 2 : 1)
    query.addEventListener('change', update)
    return () => query.removeEventListener('change', update)
  }, [])

  return visible
}
