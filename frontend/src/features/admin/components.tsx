import { useId, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Icon } from '../../components/Icon'
import { Overlay } from '../../components/Overlay'
import { STATUS_LABELS, formatNumber } from './format'
import { errorMessage } from './series'
import type { HealthStatus } from './types'

/** Shared building blocks of the /admin pages. */

export function StatusBadge({ status, label }: { status: HealthStatus; label?: string }) {
  return (
    <span className={`adm-status adm-status--${status}`}>
      <span className="adm-status__dot" aria-hidden="true" />
      {label ?? STATUS_LABELS[status]}
    </span>
  )
}

export function Kpi({
  label,
  value,
  hint,
  tone,
}: {
  label: string
  value: number | string
  hint?: ReactNode
  tone?: 'muted'
}) {
  return (
    <div className={`adm-kpi${tone === 'muted' ? ' adm-kpi--muted' : ''}`}>
      <dt className="adm-kpi__label">{label}</dt>
      <dd className="adm-kpi__value tnum">{typeof value === 'number' ? formatNumber(value) : value}</dd>
      {hint && <dd className="adm-kpi__hint">{hint}</dd>}
    </div>
  )
}

export function AdminPageHeader({
  title,
  lead,
  actions,
}: {
  title: string
  lead?: ReactNode
  actions?: ReactNode
}) {
  return (
    <header className="page__header adm-header">
      <div>
        <p className="eyebrow">Administration MedVue</p>
        <h1>{title}</h1>
        {lead && <p className="page__lead">{lead}</p>}
      </div>
      {actions && <div className="adm-header__actions">{actions}</div>}
    </header>
  )
}

export function LoadState({
  loading,
  error,
  onRetry,
}: {
  loading: boolean
  error: boolean
  onRetry: () => void
}) {
  if (error) {
    return (
      <div role="alert" className="alert alert--error adm-loadstate">
        <Icon name="alert" size={18} strokeWidth={2} />
        <span>Impossible de charger ces données.</span>
        <button type="button" className="btn btn--secondary btn--sm" onClick={onRetry}>
          Réessayer
        </button>
      </div>
    )
  }
  if (loading) {
    return (
      <p role="status" className="muted">
        Chargement…
      </p>
    )
  }
  return null
}

export function SegmentedChoice<T extends string>({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: T
  options: { value: T; label: string }[]
  onChange: (value: T) => void
}) {
  return (
    <div role="group" aria-label={label} className="segmented adm-segmented">
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          className="segmented__option"
          aria-pressed={value === option.value}
          onClick={() => onChange(option.value)}
        >
          {option.label}
        </button>
      ))}
    </div>
  )
}

export function Pagination({
  page,
  pageCount,
  total,
  onChange,
}: {
  page: number
  pageCount: number
  total: number
  onChange: (page: number) => void
}) {
  return (
    <nav className="adm-pagination" aria-label="Pagination">
      <span className="muted tnum">
        {formatNumber(total)} résultat{total > 1 ? 's' : ''} · page {page} / {pageCount}
      </span>
      <div className="adm-pagination__buttons">
        <button
          type="button"
          className="btn btn--secondary btn--sm"
          onClick={() => onChange(page - 1)}
          disabled={page <= 1}
          aria-label="Page précédente"
        >
          <Icon name="left" size={16} strokeWidth={2} />
        </button>
        <button
          type="button"
          className="btn btn--secondary btn--sm"
          onClick={() => onChange(page + 1)}
          disabled={page >= pageCount}
          aria-label="Page suivante"
        >
          <Icon name="right" size={16} strokeWidth={2} />
        </button>
      </div>
    </nav>
  )
}

/**
 * Confirmation of a sensitive action: what will happen, an optional reason (kept in the audit log) or the
 * administrator's password, and a single submit — the dialog stays open on error.
 */
export function ConfirmDialog({
  title,
  confirmLabel,
  danger,
  children,
  withReason,
  withPassword,
  onConfirm,
  onClose,
}: {
  title: string
  confirmLabel: string
  danger?: boolean
  children: ReactNode
  withReason?: boolean
  withPassword?: boolean
  onConfirm: (input: { reason: string; password: string }) => Promise<void>
  onClose: () => void
}) {
  const [reason, setReason] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)
  const inFlight = useRef(false)
  const formId = useId()

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (inFlight.current) return
    inFlight.current = true
    setSubmitting(true)
    setFailure(null)
    try {
      await onConfirm({ reason, password })
    } catch (error) {
      setFailure(errorMessage(error))
      setSubmitting(false)
      inFlight.current = false
    }
  }

  return (
    <Overlay
      title={title}
      onClose={onClose}
      dismissible={!submitting}
      footer={
        <>
          <button type="button" className="btn btn--secondary" onClick={onClose} disabled={submitting}>
            Annuler
          </button>
          <button
            type="submit"
            form={formId}
            className={danger ? 'btn btn--danger' : 'btn btn--primary'}
            disabled={submitting || (withPassword === true && password === '')}
          >
            {submitting ? 'En cours…' : confirmLabel}
          </button>
        </>
      }
    >
      <form id={formId} className="form" onSubmit={submit}>
        <div className="adm-confirm__body">{children}</div>
        {withReason && (
          <label className="field">
            <span className="field__label">Motif (facultatif, conservé dans le journal d’audit)</span>
            <textarea
              className="field__input adm-textarea"
              maxLength={500}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              data-autofocus
            />
          </label>
        )}
        {withPassword && (
          <div className="field">
            <label className="field__label" htmlFor={`${formId}-password`}>
              Votre mot de passe
            </label>
            <input
              id={`${formId}-password`}
              className="field__input"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              aria-describedby={`${formId}-password-hint`}
              data-autofocus
              required
            />
            <span id={`${formId}-password-hint`} className="field__hint">
              Requis pour toute modification des administrateurs de la plateforme.
            </span>
          </div>
        )}
        {failure && (
          <p role="alert" className="alert alert--error">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>{failure}</span>
          </p>
        )}
      </form>
    </Overlay>
  )
}
