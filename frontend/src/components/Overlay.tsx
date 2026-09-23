import { useEffect, useId, useRef } from 'react'
import type { ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Icon } from './Icon'

type Props = {
  title: string
  onClose: () => void
  /** "modal": centred dialog. "drawer": side panel (a bottom sheet on a phone). */
  variant?: 'modal' | 'drawer'
  /** When false, Escape and a click on the backdrop do nothing (an operation is in progress). */
  dismissible?: boolean
  children: ReactNode
  footer?: ReactNode
}

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * A dialog over the page: Escape and the backdrop close it, focus moves in
 * and comes back to where it was, Tab stays inside, and the page behind does
 * not scroll. Used for the settings and generation modals and the member drawer.
 */
export function Overlay({ title, onClose, variant = 'modal', dismissible = true, children, footer }: Props) {
  const titleId = useId()
  const panelRef = useRef<HTMLDivElement>(null)
  // Read through refs so the effect below runs once per mount, not on every render.
  const onCloseRef = useRef(onClose)
  const dismissibleRef = useRef(dismissible)
  useEffect(() => {
    onCloseRef.current = onClose
    dismissibleRef.current = dismissible
  })

  useEffect(() => {
    const previouslyFocused = document.activeElement as HTMLElement | null
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    const panel = panelRef.current
    ;(panel?.querySelector<HTMLElement>('[data-autofocus]') ?? panel)?.focus()

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape' && dismissibleRef.current) {
        event.stopPropagation()
        onCloseRef.current()
        return
      }
      if (event.key !== 'Tab' || !panel) {
        return
      }
      const focusable = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE))
      if (focusable.length === 0) {
        event.preventDefault()
        return
      }
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.body.style.overflow = previousOverflow
      previouslyFocused?.focus?.()
    }
  }, [])

  return createPortal(
    <div
      className={`overlay overlay--${variant}`}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && dismissible) {
          onClose()
        }
      }}
    >
      <div
        ref={panelRef}
        className="overlay__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
      >
        <div className="overlay__header">
          <h2 id={titleId} className="overlay__title">
            {title}
          </h2>
          <button
            type="button"
            className="btn btn--ghost btn--sm overlay__close"
            aria-label="Fermer le panneau"
            onClick={onClose}
            disabled={!dismissible}
          >
            <Icon name="x" size={18} strokeWidth={2} />
          </button>
        </div>
        <div className="overlay__body">{children}</div>
        {footer && <div className="overlay__footer">{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}
