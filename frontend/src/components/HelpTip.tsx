import { useEffect, useId, useRef, useState } from 'react'

type Props = {
  /** What the "?" explains, for screen readers ("Aide : …"). */
  label: string
  children: string
}

/**
 * A "?" that says one thing more, on a tap as well as a click: never a tooltip
 * that only a mouse can open. Closes on Escape or on a click elsewhere.
 */
export function HelpTip({ label, children }: Props) {
  const [open, setOpen] = useState(false)
  const id = useId()
  const anchor = useRef<HTMLSpanElement>(null)

  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false)
    const onDown = (event: MouseEvent) => {
      if (!anchor.current?.contains(event.target as Node)) setOpen(false)
    }
    window.addEventListener('keydown', onKey)
    window.addEventListener('mousedown', onDown)
    return () => {
      window.removeEventListener('keydown', onKey)
      window.removeEventListener('mousedown', onDown)
    }
  }, [open])

  return (
    <span className="help-tip" ref={anchor}>
      <button
        type="button"
        className="help-tip__button"
        aria-label={`Aide : ${label}`}
        aria-expanded={open}
        aria-controls={open ? id : undefined}
        onClick={() => setOpen((value) => !value)}
      >
        ?
      </button>
      {open && (
        <span id={id} role="note" className="help-tip__text">
          {children}
        </span>
      )}
    </span>
  )
}
