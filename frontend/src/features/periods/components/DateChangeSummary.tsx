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
        <li key={`${collection.periodNumber}-${collection.startsAt}-${collection.kind}`}>
          <CollectionImpact collection={collection} />
        </li>
      ))}
      {(preview.preparation ?? []).map((change) => (
        <li key={`${change.periodNumber}-${change.kind}-${change.from}`}>{preparationText(change)}</li>
      ))}
      {selected &&
        preview.changes.length === 1 &&
        preview.additions.length === 0 &&
        preview.collections.length === 0 && <li>Aucune garde attribuée n’est concernée.</li>}
    </ul>
  )
}

/**
 * What happens to a collection (docs/decisions.md D230): never closed, never reduced in silence, and nothing
 * about anybody's declared absences changes.
 */
function CollectionImpact({ collection }: { collection: DateChangePreview['collections'][number] }) {
  const range = `du ${formatDate(collection.newStartsAt, 'long')} au ${formatDate(collection.newLastDay, 'long')}`
  if (collection.kind === 'not_extended') {
    return (
      <>
        La collecte de la période {collection.periodNumber} reste {range} : elle ne couvre pas les{' '}
        {plural(collection.uncoveredAddedDays ?? 0, 'jour ajouté', 'jours ajoutés')}. Aucun message n’est
        envoyé ; ouvrez une collecte si vous voulez interroger les membres sur ces jours.
      </>
    )
  }
  if (collection.kind === 'follows') {
    return (
      <>
        La collecte de la période {collection.periodNumber} suit les nouvelles dates ({range}) : personne n’a
        encore répondu ni reçu de message.
      </>
    )
  }
  return (
    <>
      La collecte de la période {collection.periodNumber}
      {collection.open === false ? ', déjà close,' : ''} sera réduite : {range} (au lieu du{' '}
      {formatDate(collection.startsAt, 'long')} au {formatDate(collection.lastDay, 'long')}).{' '}
      {collection.open !== false && 'Elle reste ouverte. '}
      {collection.expected !== undefined &&
        `${collection.responses ?? 0} réponse${(collection.responses ?? 0) > 1 ? 's' : ''} sur ${plural(collection.expected, 'personne attendue', 'personnes attendues')} : les réponses et les indisponibilités déclarées sont conservées. `}
      {(collection.messagesSent ?? 0) > 0
        ? `${plural(collection.messagesSent ?? 0, 'message a déjà été envoyé', 'messages ont déjà été envoyés')} avec les anciennes dates ; aucun nouveau message n’est envoyé automatiquement. `
        : ''}
      {collection.deadline && collection.newDeadline && collection.newDeadline !== collection.deadline
        ? `Sa date limite passe du ${formatDate(collection.deadline, 'long')} au ${formatDate(collection.newDeadline, 'long')}.`
        : ''}
    </>
  )
}

/** docs/decisions.md D230: each date planned for the period that changes with it. */
function preparationText(change: NonNullable<DateChangePreview['preparation']>[number]): string {
  const period = `période ${change.periodNumber}`
  const from = formatDate(change.from, 'long')
  const to = change.to ? formatDate(change.to, 'long') : null
  switch (change.kind) {
    case 'announcement':
      return `L’annonce prévue le ${from} (${period}) tomberait après la fin : elle est annulée.`
    case 'target':
      return `La date cible de génération (${period}) passe du ${from} au ${to}.`
    case 'deadline':
    case 'collection_deadline':
      return `La date limite de déclaration (${period}) passe du ${from} au ${to}.`
    default:
      return to
        ? `Le rappel du ${from} (${period}) passe au ${to}.`
        : `Le rappel du ${from} (${period}) tomberait trop tard ou serait déjà passé : il est annulé.`
  }
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
          .filter((change) => !change.selected && !change.generated)
          .map((change) => (
            <p key={change.periodStableId} className="mv-field__hint">
              La période {change.periodNumber}, sans gardes, ira désormais du{' '}
              {formatDate(change.to.startsAt, 'long')} au {formatDate(change.to.lastDay, 'long')}.
            </p>
          ))}
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

        {(preview.blockCuts ?? []).length > 0 && (
          <p className="mv-banner mv-banner--warn">
            <Icon name="info" size={20} strokeWidth={1.9} />
            <span>
              Ces dates coupent {plural(preview.blockCuts?.length ?? 0, 'bloc', 'blocs')} :{' '}
              {preview.blockCuts
                ?.map(
                  (cut) =>
                    `« ${cut.blockName} » (${cut.lineName}), du ${formatDate(cut.firstDay, 'card')} au ${formatDate(cut.lastDay, 'card')}`,
                )
                .join(' ; ')}
              . Les jours de part et d’autre de la limite deviennent deux parties distinctes ; la partie de
              l’autre côté pourra revenir à une autre personne.
            </span>
          </p>
        )}

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
                {unit.keptDates && unit.keptDates.length > 0 && (
                  <span className="mv-confirm-cut">
                    Bloc coupé : {unit.keptDates.map((date) => formatDate(date, 'card')).join(', ')} reste
                    {unit.keptDates.length > 1 ? 'nt' : ''} attribué{unit.keptDates.length > 1 ? 's' : ''} à{' '}
                    {unit.holder ?? 'personne'}.
                  </span>
                )}
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
