import type { DateChangePreview } from '../api'
import { formatDate } from '../dates'
import { Icon } from './Icon'

const plural = (count: number, one: string, many: string) => `${count} ${count > 1 ? many : one}`

/**
 * What new dates do, in words (docs/decisions.md D225): the other period that
 * follows, the days not covered yet, the collections reduced. Shown as soon as a
 * date is chosen, before anything is saved.
 */
export function DateChangeSummary({ preview }: { preview: DateChangePreview }) {
  const others = preview.changes.filter((change) => !change.selected)
  const selected = preview.changes.find((change) => change.selected)
  return (
    <ul className="mv-date-summary" aria-label="Conséquences de ces dates">
      {others.map((change) => (
        <li key={change.periodStableId}>
          {change.to.startsAt !== change.from.startsAt
            ? `La période ${change.periodNumber} commencera le ${formatDate(change.to.startsAt, 'long')}.`
            : `La période ${change.periodNumber} se terminera le ${formatDate(change.to.lastDay, 'long')}.`}
        </li>
      ))}
      {preview.additions.map((addition) => (
        <li key={`${addition.periodNumber}-${addition.line}-${addition.firstDay}`}>
          <strong>{addition.line}</strong> : du {formatDate(addition.firstDay, 'long')} au{' '}
          {formatDate(addition.lastDay, 'long')},{' '}
          {addition.dutyCount === null
            ? 'les renforts dépendront des gardes de la ligne source'
            : `${plural(addition.dutyCount, 'nouvelle garde non attribuée', 'nouvelles gardes non attribuées')}`}
          . Utilisez ensuite « Compléter la période {addition.periodNumber} ».
          {addition.published && ' Ces nouvelles gardes resteront à republier.'}
        </li>
      ))}
      {preview.collections.map((collection) => (
        <li key={`${collection.periodNumber}-${collection.startsAt}`}>
          La collecte des disponibilités de la période {collection.periodNumber} ira du{' '}
          {formatDate(collection.newStartsAt, 'long')} au {formatDate(collection.newLastDay, 'long')}
          {collection.contacted
            ? ' : les participants ont déjà été sollicités sur les anciennes dates, aucun message ne leur sera envoyé.'
            : collection.answered
              ? ' : des participants y ont déjà répondu, leurs indisponibilités ne changent pas.'
              : '.'}
        </li>
      ))}
      {selected &&
        preview.changes.length === 1 &&
        preview.additions.length === 0 &&
        preview.collections.length === 0 && <li>Aucune garde attribuée n’est concernée.</li>}
    </ul>
  )
}

const NOTIFY_BLOCKED: Record<string, string> = {
  nobody_to_notify: 'Personne à avertir : ces gardes n’ont jamais été publiées, aucun membre ne les a vues.',
  pending_changes:
    'Impossible d’avertir maintenant : d’autres modifications attendent d’être publiées. Republiez-les d’abord, ou confirmez sans avertir.',
  not_republishable:
    'Impossible d’avertir maintenant : le calendrier publié ne peut pas être republié tel quel. Confirmez sans avertir, puis corrigez-le.',
}

type ConfirmProps = {
  preview: DateChangePreview
  saving: boolean
  error: string | null
  onBack: () => void
  onConfirm: (notify: boolean) => void
}

/**
 * The confirmation of new dates that touch a calendar (docs/decisions.md
 * D225): the exact duties and holders, what happens to the publication and who
 * would be told — and three explicit choices.
 */
export function DateChangeConfirmation({ preview, saving, error, onBack, onConfirm }: ConfirmProps) {
  const selected = preview.changes.find((change) => change.selected)
  const shortened = selected !== undefined && selected.to.lastDay < selected.from.lastDay
  const republished = preview.republishPeriods.length > 0
  return (
    <>
      <div className="mv-dialog__body">
        {selected && shortened && (
          <p className="mv-confirm-lead">
            Cette période se terminera désormais le {formatDate(selected.to.lastDay, 'long')}. Les gardes
            situées après cette date ne feront plus partie de la période.
          </p>
        )}
        {preview.changes
          .filter((change) => !change.selected && change.generated)
          .map((change) => (
            <p key={change.periodStableId} className="mv-banner mv-banner--warn">
              <Icon name="info" size={20} strokeWidth={1.9} />
              <span>
                La période {change.periodNumber}, déjà générée
                {change.published ? ' et publiée' : ''}, irait désormais du{' '}
                {formatDate(change.to.startsAt, 'long')} au {formatDate(change.to.lastDay, 'long')} (au lieu
                du {formatDate(change.from.startsAt, 'long')} au {formatDate(change.from.lastDay, 'long')}).
              </span>
            </p>
          ))}

        <h3 className="mv-confirm-title">
          {preview.retiredDutyCount === 0
            ? 'Aucune garde retirée'
            : `${plural(preview.retiredDutyCount, 'garde retirée du planning', 'gardes retirées du planning')}, dont ${plural(preview.retiredAssignedCount, 'attribuée', 'attribuées')}`}
        </h3>
        {preview.retiredUnits.length > 0 && (
          <ul className="mv-confirm-list" aria-label="Gardes retirées">
            {preview.retiredUnits.map((unit) => (
              <li key={`${unit.line}-${unit.dates[0]}`}>
                <span>
                  {unit.dates.length > 1
                    ? `${formatDate(unit.dates[0], 'card')} → ${formatDate(unit.dates[unit.dates.length - 1], 'card')}`
                    : formatDate(unit.dates[0], 'card')}
                  {unit.block && ` · ${unit.block}, bloc entier`}
                </span>
                <span className="mv-muted">
                  {unit.line} · {unit.holder ?? 'non attribuée'}
                  {unit.published && ' · publiée'}
                </span>
              </li>
            ))}
          </ul>
        )}

        <DateChangeSummary preview={{ ...preview, changes: [] }} />

        {preview.swapRequestCount > 0 && (
          <p className="mv-field__hint">
            {plural(
              preview.swapRequestCount,
              'demande d’échange en cours sera clôturée',
              'demandes d’échange en cours seront clôturées',
            )}
            .
          </p>
        )}
        {republished && (
          <p className="mv-field__hint">
            Le planning a été diffusé : le dernier PDF reste conservé tel quel. Sans avertir, ces retraits
            apparaissent dans « Modifications non publiées » et seront annoncés à la prochaine republication.
          </p>
        )}
        <p className="mv-field__hint">
          Rien n’est effacé : les gardes retirées et leurs titulaires restent dans l’historique.
        </p>
        {preview.notifyBlock && <p className="mv-field__hint">{NOTIFY_BLOCKED[preview.notifyBlock]}</p>}
        {preview.canNotify && (
          <p className="mv-field__hint">
            « Confirmer et avertir » republie le planning (nouveau PDF) et écrit uniquement à :{' '}
            {preview.recipients.join(', ')}.
          </p>
        )}
        {error && (
          <div role="alert" className="mv-banner mv-banner--error">
            <Icon name="info" size={20} strokeWidth={1.9} />
            <span>{error}</span>
          </div>
        )}
      </div>
      <footer className="mv-dialog__foot mv-dialog__foot--three">
        <button type="button" className="mv-btn mv-btn--secondary" onClick={onBack} disabled={saving}>
          Annuler
        </button>
        <button
          type="button"
          className="mv-btn mv-btn--secondary"
          onClick={() => onConfirm(false)}
          disabled={saving}
          aria-busy={saving}
        >
          Confirmer
        </button>
        <button
          type="button"
          className="mv-btn mv-btn--primary"
          onClick={() => onConfirm(true)}
          disabled={saving || !preview.canNotify}
          aria-busy={saving}
        >
          Confirmer et avertir
        </button>
      </footer>
    </>
  )
}
