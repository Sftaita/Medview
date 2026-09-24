import { useLayoutEffect, useRef, useState } from 'react'

/** Largeur réelle d'un élément, mise à jour au redimensionnement. */
export function useElementWidth<T extends HTMLElement>(initial = 900) {
  const ref = useRef<T | null>(null)
  const [width, setWidth] = useState(initial)
  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    setWidth(el.getBoundingClientRect().width)
    if (typeof ResizeObserver === 'undefined') return
    const ro = new ResizeObserver((entries) => setWidth(entries[0].contentRect.width))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])
  return [ref, width] as const
}
