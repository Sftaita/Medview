import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '../../components/Icon'
import { ApiError } from '../../lib/apiClient'
import {
  closeCollection,
  fetchCollectionResponses,
  fetchPlanningCollections,
  updateCollectionDeadline,
} from '../availability/collectionApi'
import {
  formatDateFull,
  formatDeadline,
  formatMoment,
  formatMomentFull,
  formatWindow,
  formatWindowShort,
} from '../availability/collectionFormat'
import type { AvailabilityCollection, CollectionResponseRow } from '../availability/collectionTypes'

type Props = {
  planningStableId: string
  /** Bumped by the parent when something changed elsewhere (an extension opened a collection): reloads the list. */
  reloadToken?: number
  /** Only the collections of this period (docs/planning-cycles.md §5.9); every one without it. */
  periodStableId?: string
}

/** "10/12/2026" for an open date, "Ouverte le …". */
function openedLabel(collection: AvailabilityCollection): string {
  return `Ouverte le ${formatMomentFull(collection.openedAt)}`
}

/**
 * Follow-up of the availability collections of a planning
 * (docs/availability-collection.md §9, §12). For someone who manages them:
 * the X/Y counter, the deadline, and who has answered / who is late; the
 * closed ones stay listed as history and are never recomputed. For a plain
 * member: only their own answer to each collection.
 */
export function AvailabilityCollectionsPanel({ planningStableId, reloadToken = 0, periodStableId }: Props) {
  const [collections, setCollections] = useState<AvailabilityCollection[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(() => {
    fetchPlanningCollections(planningStableId)
      .then((result) => {
        setCollections(
          periodStableId
            ? result.filter((collection) => collection.period?.stableId === periodStableId)
            : result,
        )
        setError(null)
      })
      .catch(() => setError('Impossible de charger les collectes de disponibilités.'))
  }, [planningStableId, periodStableId])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load, reloadToken])

  const replace = (updated: AvailabilityCollection) =>
    setCollections((previous) => (previous ?? []).map((c) => (c.stableId === updated.stableId ? updated : c)))

  return (
    <section className="card availability-panel" aria-label="Collecte des disponibilités">
      <div className="section-title">
        <h2>Collecte des disponibilités</h2>
      </div>
      {collections === null && !error && (
        <p role="status" className="muted">
          Chargement…
        </p>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
      {collections !== null && collections.length === 0 && (
        <p className="muted">
          {periodStableId
            ? 'Aucune collecte de disponibilités pour cette période.'
            : 'Aucune collecte de disponibilités pour ce planning.'}
        </p>
      )}
      <ul className="list collections">
        {(collections ?? []).map((collection, index) => (
          <CollectionItem
            key={collection.stableId}
            collection={collection}
            // The freshest open collection is what an admin comes here for.
            initiallyOpen={collection.canManage && collection.status === 'OPEN' && index === 0}
            onChanged={replace}
          />
        ))}
      </ul>
    </section>
  )
}

function CollectionItem({
  collection,
  initiallyOpen,
  onChanged,
}: {
  collection: AvailabilityCollection
  initiallyOpen: boolean
  onChanged: (collection: AvailabilityCollection) => void
}) {
  const [expanded, setExpanded] = useState(initiallyOpen)
  const [rows, setRows] = useState<CollectionResponseRow[] | null>(null)
  const [rowsError, setRowsError] = useState<string | null>(null)
  const [editingDeadline, setEditingDeadline] = useState(false)
  const [deadline, setDeadline] = useState(collection.deadline ?? '')
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const progress = collection.progress
  const open = collection.status === 'OPEN'
  const label = formatWindow(collection.startsAt, collection.lastDay)

  useEffect(() => {
    if (!expanded || !collection.canManage) {
      return
    }
    let cancelled = false
    fetchCollectionResponses(collection.stableId)
      .then((result) => {
        if (!cancelled) setRows(result)
      })
      .catch(() => {
        if (!cancelled) setRowsError('Impossible de charger les participants.')
      })
    return () => {
      cancelled = true
    }
    // Reload the list when the count changes (someone joined or left, the collection was closed…).
  }, [expanded, collection.stableId, collection.canManage, progress?.participants, collection.status])

  async function saveDeadline() {
    setBusy(true)
    setActionError(null)
    try {
      onChanged(await updateCollectionDeadline(collection.stableId, deadline || null))
      setEditingDeadline(false)
    } catch (err) {
      setActionError(
        err instanceof ApiError && err.status === 422
          ? 'Cette date limite est invalide (elle ne peut pas être dans le passé).'
          : 'Impossible de modifier la date limite indicative.',
      )
    } finally {
      setBusy(false)
    }
  }

  async function close() {
    setBusy(true)
    setActionError(null)
    try {
      onChanged(await closeCollection(collection.stableId))
    } catch {
      setActionError('Impossible de clôturer cette collecte.')
    } finally {
      setBusy(false)
    }
  }

  const visibleRows = rows ?? []
  const mine = collection.myResponse

  return (
    <li className="collection" data-collection={collection.stableId}>
      <div className="collection__head">
        <div className="collection__main">
          <h3 className="collection__title">
            {formatWindowShort(collection.startsAt, collection.lastDay)}{' '}
            <span className={`tag ${open ? 'tag--green' : ''}`}>{open ? 'Ouverte' : 'Clôturée'}</span>
          </h3>
          <p className="muted collection__meta">
            {openedLabel(collection)}
            {collection.deadline && <> · Date limite indicative {formatDateFull(collection.deadline)}</>}
            {collection.closedAt && <> · Clôturée le {formatMomentFull(collection.closedAt)}</>}
          </p>
        </div>
        {progress && (
          <div className="collection__count" aria-label="Participants">
            <span className="tnum collection__count-value">{progress.participants}</span>
            <span className="muted"> participant{progress.participants > 1 ? 's' : ''}</span>
          </div>
        )}
      </div>

      {open && collection.deadline && (
        <p className="collection__deadline">
          Date limite indicative : {formatDeadline(collection.deadline)} — le calendrier reste modifiable
          après.
        </p>
      )}

      {/* A plain member: only their own participation (D214: nothing to confirm). */}
      {!collection.canManage && mine && open && mine.status === 'PARTICIPANT' && (
        <p className="collection__mine">
          Indiquez vos éventuelles indisponibilités ; sans indisponibilité renseignée, vous êtes considéré
          comme disponible.{' '}
          <Link to={`/my-availability?collection=${collection.stableId}`}>Ouvrir mon calendrier</Link>
        </p>
      )}

      {collection.canManage && (
        <div className="collection__actions">
          <button
            type="button"
            className="btn btn--secondary btn--sm"
            aria-expanded={expanded}
            onClick={() => setExpanded((value) => !value)}
          >
            <Icon name={expanded ? 'down' : 'users'} size={16} strokeWidth={2} />
            {expanded ? 'Masquer les participants' : 'Voir les participants'}
          </button>
          {open && !editingDeadline && (
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => setEditingDeadline(true)}>
              {collection.deadline
                ? 'Modifier la date limite indicative'
                : 'Fixer une date limite indicative'}
            </button>
          )}
          {open && (
            <button type="button" className="btn btn--ghost btn--sm" onClick={close} disabled={busy}>
              Clôturer
            </button>
          )}
        </div>
      )}

      {editingDeadline && (
        <div className="collection__deadline-form">
          <label className="field__label" htmlFor={`deadline-${collection.stableId}`}>
            Date limite indicative
          </label>
          <input
            id={`deadline-${collection.stableId}`}
            type="date"
            className="field__input"
            value={deadline}
            onChange={(event) => setDeadline(event.target.value)}
          />
          <div className="form-actions">
            <button type="button" className="btn btn--primary btn--sm" onClick={saveDeadline} disabled={busy}>
              Enregistrer la date
            </button>
            <button
              type="button"
              className="btn btn--secondary btn--sm"
              onClick={() => setEditingDeadline(false)}
              disabled={busy}
            >
              Annuler
            </button>
          </div>
        </div>
      )}

      {actionError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{actionError}</span>
        </p>
      )}

      {collection.canManage && expanded && (
        <div className="responses" aria-label={`Participants ${label}`}>
          {rows === null && !rowsError && <p className="muted">Chargement des participants…</p>}
          {rowsError && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{rowsError}</span>
            </p>
          )}
          {rows !== null && visibleRows.length === 0 && <p className="muted">Aucun participant.</p>}
          <ul className="list responses__list">
            {visibleRows.map((row) => (
              <li key={row.stableId} className={`response response--${row.status.toLowerCase()}`}>
                <span className="response__mark" aria-hidden="true">
                  {row.status === 'WITHDRAWN' ? '–' : '•'}
                </span>
                <span className="response__name">
                  {row.user.lastName} {row.user.firstName}
                </span>
                <span className="response__status">
                  {row.status === 'WITHDRAWN' ? 'a quitté le planning' : 'participant'}
                  {row.lastAvailabilityChangeAt && row.status !== 'WITHDRAWN' && (
                    <span className="muted">
                      {' '}
                      · calendrier modifié le {formatMoment(row.lastAvailabilityChangeAt)}
                    </span>
                  )}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </li>
  )
}
