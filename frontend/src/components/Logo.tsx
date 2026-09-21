import { Icon } from './Icon'

type Props = {
  size?: number
  /** White mark on a dark (green) surface. */
  inverse?: boolean
}

/** Placeholder MedVue mark: no official logo has been supplied yet. */
export function Logo({ size = 32, inverse = false }: Props) {
  return (
    <span
      aria-hidden="true"
      style={{
        display: 'inline-flex',
        flex: 'none',
        alignItems: 'center',
        justifyContent: 'center',
        width: size,
        height: size,
        borderRadius: Math.round(size * 0.3),
        background: inverse ? 'var(--white)' : 'var(--green-700)',
        color: inverse ? 'var(--green-800)' : 'var(--white)',
      }}
    >
      <Icon name="pulse" size={Math.round(size * 0.6)} strokeWidth={2.2} />
    </span>
  )
}
