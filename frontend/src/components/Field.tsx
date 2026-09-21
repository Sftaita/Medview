import { useId, useState, type InputHTMLAttributes, type ReactNode } from 'react'
import { Icon } from './Icon'

type FieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> & {
  label: string
  hint?: ReactNode
  /** Field-level error message; also flags the input as invalid. */
  error?: string
  /** Shown in the right-hand 48px slot (lock icon, show-password button…). */
  trailing?: ReactNode
}

/** Label + input + hint/error, wired with htmlFor / aria-describedby. */
export function Field({ label, hint, error, trailing, className, ...inputProps }: FieldProps) {
  const id = useId()
  const messageId = `${id}-message`
  const hasMessage = Boolean(hint || error)

  return (
    <div className="field">
      <label htmlFor={id} className="field__label">
        {label}
      </label>
      <div className="field__control">
        <input
          {...inputProps}
          id={id}
          className={['field__input', trailing ? 'field__input--trailing' : '', className]
            .filter(Boolean)
            .join(' ')}
          aria-invalid={error ? true : undefined}
          aria-describedby={hasMessage ? messageId : undefined}
        />
        {trailing}
      </div>
      {hasMessage && (
        <small id={messageId} className="field__hint">
          {error ?? hint}
        </small>
      )}
    </div>
  )
}

/** Password input with a show/hide toggle (48px touch target). */
export function PasswordField(props: Omit<FieldProps, 'type' | 'trailing'>) {
  const [visible, setVisible] = useState(false)

  return (
    <Field
      {...props}
      type={visible ? 'text' : 'password'}
      trailing={
        <button
          type="button"
          className="field__trailing"
          aria-label={visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
          aria-pressed={visible}
          onClick={() => setVisible((current) => !current)}
        >
          <Icon name={visible ? 'eyeOff' : 'eye'} size={20} strokeWidth={1.9} />
        </button>
      }
    />
  )
}

/** Lock glyph used as the trailing slot of a read-only field. */
export function LockedIndicator() {
  return (
    <span className="field__trailing" aria-hidden="true">
      <Icon name="lock" size={18} strokeWidth={1.9} />
    </span>
  )
}
