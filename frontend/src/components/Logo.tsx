type Props = {
  size?: number
  /** Green mark on a white badge, for dark (green) surfaces. */
  inverse?: boolean
}

/**
 * MedVue mark (docs/Design/pwa_medvue): a week grid where two days merge into
 * one block — same drawing as the favicon and PWA icons.
 */
export function Logo({ size = 32, inverse = false }: Props) {
  const badge = inverse ? 'var(--white)' : 'var(--green-500)'
  const cells = inverse ? 'var(--green-500)' : 'var(--white)'
  return (
    <svg
      aria-hidden="true"
      data-testid="medvue-logo"
      viewBox="0 0 48 48"
      width={size}
      height={size}
      style={{ display: 'block', flex: 'none' }}
    >
      <rect width="48" height="48" rx="14" fill={badge} />
      <g fill={cells}>
        <rect x="5.5" y="9.25" width="10" height="13" rx="3" />
        <rect x="19" y="9.25" width="10" height="13" rx="3" />
        <rect x="32.5" y="9.25" width="10" height="13" rx="3" />
        <rect x="5.5" y="25.75" width="23.5" height="13" rx="6.5" />
        <rect x="32.5" y="25.75" width="10" height="13" rx="3" />
      </g>
    </svg>
  )
}
