import type { PeriodStatus } from '../types';
import { STATUS_LABEL } from '../lib/periods';

interface Props {
  status: PeriodStatus;
  size?: 'sm' | 'md';
  /** Pastille seule, sans libellé (le libellé reste lu par les lecteurs d’écran). */
  dotOnly?: boolean;
}

/** Couleur + forme de pastille différentes par statut : lisible sans la couleur. */
export function StatusPill({ status, size = 'md', dotOnly = false }: Props) {
  if (dotOnly) {
    return <span className={`mv-dot mv-dot--${status}`} role="img" aria-label={STATUS_LABEL[status]} />;
  }
  return (
    <span className={`mv-status mv-status--${status} mv-status--${size}`}>
      <span className={`mv-dot mv-dot--${status}`} aria-hidden="true" />
      {STATUS_LABEL[status]}
    </span>
  );
}
