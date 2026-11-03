import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Icon, type IconName } from '../../../components/Icon'

export type ActionMenuItem = {
  label: string
  icon: IconName
  onSelect: () => void
  danger?: boolean
}

type Props = {
  /** Accessible name of the "⋯" button. */
  label: string
  items: ActionMenuItem[]
  /** Square 56 px button (page header) instead of the 48 px icon button (list rows). */
  large?: boolean
}

/** The "⋯" button and its dropdown. Closes on Escape, on a click outside, or once an item is chosen. */
export function ActionMenu({ label, items, large = false }: Props) {
  const [open, setOpen] = useState(false)
  const [upward, setUpward] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)

  function close() {
    setOpen(false)
    setUpward(false)
  }

  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') close()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  // Opened downward, the last item can end up out of reach: under the phone's bottom bar, clipped by
  // its card, or below the page's end (the last line of the list, 390 px check — D224). When it is not
  // the element actually shown at its own place, the menu opens upward instead.
  useLayoutEffect(() => {
    if (!open || upward || typeof document.elementFromPoint !== 'function') return
    const menu = menuRef.current
    const last = menu?.lastElementChild
    if (!menu || !last) return
    const box = last.getBoundingClientRect()
    const shown = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)
    if (!shown || !menu.contains(shown)) setUpward(true)
  }, [open, upward])

  if (items.length === 0) return null

  return (
    <div className="pd-menu-anchor">
      <button
        type="button"
        className={large ? 'pd-btn pd-btn-secondary pd-btn-lg pd-btn-square' : 'pd-icon-btn pd-icon-btn-lg'}
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => (open ? close() : setOpen(true))}
      >
        <Icon name="more" size={20} strokeWidth={3} />
      </button>
      {open && (
        <>
          <div className="pd-menu-scrim" onClick={close} />
          <div
            ref={menuRef}
            className={upward ? 'pd-menu pd-menu--up' : 'pd-menu'}
            role="menu"
            aria-label={label}
          >
            {items.map((item) => (
              <button
                key={item.label}
                type="button"
                role="menuitem"
                className={item.danger ? 'pd-menu-item pd-danger' : 'pd-menu-item'}
                onClick={() => {
                  close()
                  item.onSelect()
                }}
              >
                <Icon name={item.icon} size={18} strokeWidth={2} />
                {item.label}
              </button>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
