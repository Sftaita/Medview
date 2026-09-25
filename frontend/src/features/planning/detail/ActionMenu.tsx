import { useEffect, useState } from 'react'
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

  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false)
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  if (items.length === 0) return null

  return (
    <div className="pd-menu-anchor">
      <button
        type="button"
        className={large ? 'pd-btn pd-btn-secondary pd-btn-lg pd-btn-square' : 'pd-icon-btn pd-icon-btn-lg'}
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        <Icon name="more" size={20} strokeWidth={3} />
      </button>
      {open && (
        <>
          <div className="pd-menu-scrim" onClick={() => setOpen(false)} />
          <div className="pd-menu" role="menu" aria-label={label}>
            {items.map((item) => (
              <button
                key={item.label}
                type="button"
                role="menuitem"
                className={item.danger ? 'pd-menu-item pd-danger' : 'pd-menu-item'}
                onClick={() => {
                  setOpen(false)
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
