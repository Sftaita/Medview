import { useLayoutEffect, useRef, useState } from 'react'

/**
 * Largeur réelle d'un élément (boîte de bordure), mise à jour au
 * redimensionnement. Toujours la même mesure, au montage comme ensuite :
 * mélanger boîte de bordure et boîte de contenu décalerait les paliers
 * selon le padding, lui-même dépendant du palier.
 */
export function useElementWidth<T extends HTMLElement>(initial = 900) {
  const ref = useRef<T | null>(null)
  const [width, setWidth] = useState(initial)
  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    setWidth(el.getBoundingClientRect().width)
    if (typeof ResizeObserver === 'undefined') return
    const ro = new ResizeObserver((entries) => {
      const box = entries[0].borderBoxSize?.[0]
      setWidth(box ? box.inlineSize : el.getBoundingClientRect().width)
    })
    ro.observe(el)
    return () => ro.disconnect()
  }, [])
  return [ref, width] as const
}
