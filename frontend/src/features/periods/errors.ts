import { ApiError } from '../../lib/apiClient'
import type { BlockCut, DateReason } from './api'
import { formatDate } from './dates'

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

/** A cut block, for a doctor: « Ven · Sam · Dim » (Seniors), du ven. 30 avr. au dim. 2 mai. */
export function describeCut(cut: BlockCut): string {
  const line = cut.lineName ? ` (${cut.lineName})` : ''
  return `« ${cut.blockName} »${line}, du ${formatDate(cut.firstDay, 'wdm')} au ${formatDate(cut.lastDay, 'wdm')}`
}

/**
 * Why a date is refused, or why no date can be chosen (docs/decisions.md D207) —
 * the same codes the server answers when a date is written.
 */
export function dateReasonMessage(reason: DateReason): string {
  switch (reason.code) {
    case 'period_too_short':
      return 'Trop court : une période dure au moins 7 jours.'
    case 'next_period_too_short':
      return 'La période suivante durerait moins de 7 jours.'
    case 'period_boundary_splits_block':
      return reason.cuts && reason.cuts.length > 0
        ? `Couperait le bloc ${reason.cuts.map(describeCut).join(' et le bloc ')} : un bloc ne se partage jamais entre deux périodes.`
        : 'Couperait un bloc de gardes : un bloc ne se partage jamais entre deux périodes.'
    case 'period_has_generation':
      return 'La période est déjà générée : elle ne peut plus être raccourcie.'
    case 'period_has_duties_after':
      return 'Des gardes sont déjà préparées après ce jour.'
    case 'period_has_duties_before':
      return 'Des gardes sont déjà préparées avant ce jour.'
    case 'collection_beyond_end':
      return 'Une collecte des disponibilités de cette période couvre des jours après ce jour.'
    case 'collection_before_start':
      return 'Une collecte des disponibilités de cette période commence avant ce jour.'
    case 'collection_announced':
      return 'Les participants ont déjà été sollicités pour la collecte des disponibilités (annonce ou rappel envoyé) : elle n’est plus redimensionnée, et ce jour la laisserait hors de la période.'
    case 'preparation_past':
      return 'Ce jour est déjà passé.'
    case 'preparation_after_period':
      return 'Ce jour est après la fin de la période.'
    case 'preparation_target_not_after_announcement':
      return 'La date cible de génération vient après l’annonce.'
    case 'preparation_announcement_not_before_target':
      return 'L’annonce vient avant la date cible de génération.'
    case 'collection_answered':
      return 'Des participants ont déjà répondu à la collecte des disponibilités ou modifié leurs absences : elle n’est plus redimensionnée, et ce jour la laisserait hors de la période.'
    case 'period_locked':
      return 'Cette période est publiée ou validée : ses dates ne changent plus.'
    case 'period_not_blank':
      return 'Cette période a déjà une génération, une publication, un calcul ou des gardes préparées : cette date ne bouge plus.'
    case 'next_period_not_blank':
      return 'La période suivante a déjà une génération, une publication, un calcul ou des gardes préparées : la frontière entre les deux ne bouge plus.'
    case 'period_start_follows_previous':
      return 'Le premier jour suit toujours le dernier jour de la période précédente. Pour le déplacer, modifiez la fin de la période précédente.'
    case 'job_in_progress':
      return 'Une génération ou une complétion est en cours. Réessayez quand elle sera terminée.'
    case 'period_management_disabled':
      return 'La gestion des périodes n’est pas activée.'
  }
  return 'Cette date n’est pas possible.'
}

/** The errors of PATCH …/periods/{p}: the reason, said like in the calendar. */
export function periodDatesError(err: unknown): string {
  if (!(err instanceof ApiError)) {
    return 'Les dates n’ont pas pu être enregistrées. Vérifiez votre connexion et réessayez.'
  }
  const body = (err.body ?? {}) as { error?: string; details?: BlockCut[] }
  if (body.error === 'period_changed' || body.error === 'period_sequence_changed') {
    return 'Les dates de la période ont changé entre-temps. Rechargez avant de continuer.'
  }
  if (err.status === 403) return 'Seul le créateur du planning peut modifier les dates.'
  if (body.error) return dateReasonMessage({ code: body.error, cuts: body.details })
  return 'Les dates n’ont pas pu être enregistrées. Réessayez.'
}

/** docs/decisions.md D210: why the preparation of a period was not saved, said for a doctor. */
const PREPARATION_MESSAGES: Record<string, string> = {
  period_management_disabled: 'La gestion des périodes n’est pas activée.',
  period_generation_started: 'La génération de cette période a été lancée : sa préparation ne change plus.',
  period_locked: 'Cette période est validée ou publiée : sa préparation ne change plus.',
  period_over: 'Cette période est terminée.',
  announcement_in_past: 'La date d’annonce est déjà passée.',
  announcement_after_period_end: 'La date d’annonce est après la fin de la période.',
  announcement_already_done: 'L’annonce a déjà été faite : elle ne change plus.',
  target_in_past: 'La date cible de génération est déjà passée.',
  target_after_period_end: 'La date cible de génération est après la fin de la période.',
  target_before_announcement: 'La date cible de génération doit venir après l’annonce.',
  reminders_need_target:
    'Choisissez d’abord la date cible de génération : les rappels se placent avant elle.',
  invalid_reminder: 'Un rappel se place 1 à 365 jours, ou 1 à 52 semaines, avant la date cible.',
  duplicate_reminder: 'Deux rappels tombent le même jour.',
  reminder_in_past: 'Un rappel tomberait un jour déjà passé.',
  reminder_before_announcement: 'Un rappel doit venir après l’annonce.',
  reminder_not_editable: 'Ce rappel a déjà été envoyé : il ne change plus.',
  reminder_not_found: 'Ce rappel n’existe plus. Rechargez la page.',
  too_many_reminders: 'Dix rappels au plus par période.',
  invalid_date: 'Une des dates n’est pas valide.',
}

export function preparationErrorMessage(code: string): string {
  return PREPARATION_MESSAGES[code] ?? 'La préparation n’a pas pu être enregistrée.'
}

export function preparationSaveError(err: unknown): string {
  if (!(err instanceof ApiError)) {
    return 'La préparation n’a pas pu être enregistrée. Vérifiez votre connexion et réessayez.'
  }
  if (err.status === 403) return 'Seuls le créateur et les responsables du planning préparent ses périodes.'
  const body = (err.body ?? {}) as { error?: string }
  return body.error
    ? preparationErrorMessage(body.error)
    : 'La préparation n’a pas pu être enregistrée. Réessayez.'
}
