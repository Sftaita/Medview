import { useEffect, useId, useRef, type ReactNode } from 'react'
import { Icon } from '../../../components/Icon'

type Props = {
  title: string
  onClose: () => void
  children: ReactNode
  /** Wider sheet for richer content (member management). */
  wide?: boolean
}

/**
 * Dialog of the planning detail page: centred on a computer, a bottom sheet on a
 * phone. Closes with Escape, the close button or a click on the backdrop.
 */
export function Sheet({ title, onClose, children, wide = false }: Props) {
  const titleId = useId()
  const dialogRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  useEffect(() => {
    dialogRef.current?.focus()
  }, [])

  return (
    <div className="pd-overlay" onClick={onClose}>
      <div
        ref={dialogRef}
        className={wide ? 'pd-sheet pd-sheet--wide' : 'pd-sheet'}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="pd-sheet-head">
          <h2 id={titleId}>{title}</h2>
          <button type="button" className="pd-icon-btn" aria-label="Fermer" onClick={onClose}>
            <Icon name="x" size={20} strokeWidth={2.2} />
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}
