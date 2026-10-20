import { ApiError } from '../../lib/apiClient'
import type { BlockCut } from './api'

export type PeriodCreationErrorKind =
  'historical' | 'split' | 'stale' | 'too_short' | 'busy' | 'disabled' | 'forbidden' | 'other'

/** Why a period was not created, said for a doctor — and the blocks concerned, when that is why. */
export class PeriodCreationError extends Error {
  readonly kind: PeriodCreationErrorKind
  readonly cuts: BlockCut[]

  constructor(kind: PeriodCreationErrorKind, message: string, cuts: BlockCut[] = []) {
    super(message)
    this.name = 'PeriodCreationError'
    this.kind = kind
    this.cuts = cuts
  }
}

/** The errors of POST …/periods (docs/planning-cycles.md §7.3, D189, D192). */
export function periodCreationError(err: unknown): PeriodCreationError {
  if (!(err instanceof ApiError)) {
    return new PeriodCreationError(
      'other',
      'La période n’a pas pu être créée. Vérifiez votre connexion et réessayez.',
    )
  }
  const body = (err.body ?? {}) as { error?: string; details?: BlockCut[] }
  switch (body.error) {
    case 'historical_block_cut_confirmation_required':
      return new PeriodCreationError(
        'historical',
        'La fin de la période précédente, qui ne peut plus changer, coupe un bloc de gardes. Confirmez que les jours restants deviennent la suite de ce bloc dans la nouvelle période.',
        body.details ?? [],
      )
    case 'period_boundary_splits_block':
      return new PeriodCreationError(
        'split',
        'Cette date de fin couperait un bloc de gardes en deux : un bloc ne se partage jamais entre deux périodes. Choisissez une autre date.',
        body.details ?? [],
      )
    case 'period_sequence_changed':
      return new PeriodCreationError(
        'stale',
        'Les périodes du planning ont changé entre-temps (une autre période a été créée ou supprimée). Rechargez avant de continuer.',
      )
    case 'period_too_short':
      return new PeriodCreationError('too_short', 'Une période dure au moins 7 jours.')
    case 'invalid_date':
      return new PeriodCreationError('too_short', 'La date de fin n’est pas valide.')
    case 'job_in_progress':
      return new PeriodCreationError(
        'busy',
        'Une génération ou une complétion est en cours sur ce planning. Réessayez quand elle sera terminée.',
      )
    case 'period_creation_disabled':
      return new PeriodCreationError('disabled', 'La création de périodes n’est pas activée.')
  }
  if (err.status === 403) {
    return new PeriodCreationError('forbidden', 'Seul le créateur du planning peut créer une période.')
  }
  return new PeriodCreationError('other', 'La période n’a pas pu être créée. Réessayez.')
}

/** The errors of POST …/periods/{p}/availability-collections (§5.9). */
export function collectionOpeningMessage(err: unknown): string {
  const code = err instanceof ApiError ? (err.body as { error?: string } | null)?.error : undefined
  switch (code) {
    case 'period_fully_collected':
      return 'Tous les jours de cette période sont déjà couverts par une collecte.'
    case 'period_management_disabled':
      return 'La gestion des périodes n’est pas activée.'
    case 'deadline_in_past':
      return 'La date souhaitée est déjà passée.'
  }
  if (err instanceof ApiError && err.status === 403)
    return 'Vous ne pouvez pas ouvrir de collecte sur ce planning.'
  return 'La collecte n’a pas pu être ouverte. Réessayez.'
}
