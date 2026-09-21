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
}

type Filter = 'ALL' | 'ACKNOWLEDGED' | 'PENDING'

const FILTER_LABEL: Record<Filter, string> = {
  ALL: 'Tous',
  ACKNOWLEDGED: 'Répondu',
  PENDING: 'À renseigner',
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
export function AvailabilityCollectionsPanel({ planningStableId, reloadToken = 0 }: Props) {
  const [collections, setCollections] = useState<AvailabilityCollection[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(() => {
    fetchPlanningCollections(planningStableId)
      .then((result) => {
        setCollections(result)
        setError(null)
      })
      .catch(() => setError('Impossible de charger les collectes de disponibilités.'))
  }, [planningStableId])

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
        <p className="muted">Aucune collecte de disponibilités pour ce planning.</p>
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
  const [filter, setFilter] = useState<Filter>('ALL')
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
        if (!cancelled) setRowsError('Impossible de charger les réponses.')
      })
    return () => {
      cancelled = true
    }
    // Reload the list when the counters change (someone answered, the collection was closed…).
  }, [expanded, collection.stableId, collection.canManage, progress?.acknowledged, collection.status])

  async function saveDeadline() {
    setBusy(true)
    setActionError(null)
    try {
      onChanged(await updateCollectionDeadline(collection.stableId, deadline || null))
      setEditingDeadline(false)
    } catch (err) {
      setActionError(
        err instanceof ApiError && err.status === 422
          ? 'Cette échéance est invalide (elle ne peut pas être dans le passé).'
          : "Impossible de modifier l'échéance.",
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

  const visibleRows = (rows ?? []).filter((row) => {
    if (filter === 'ALL') return true
    if (filter === 'ACKNOWLEDGED') return row.status === 'ACKNOWLEDGED'
    return row.status === 'PENDING'
  })
  const counts: Record<Filter, number> = {
    ALL: rows?.length ?? 0,
    ACKNOWLEDGED: (rows ?? []).filter((row) => row.status === 'ACKNOWLEDGED').length,
    PENDING: (rows ?? []).filter((row) => row.status === 'PENDING').length,
  }
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
            {collection.deadline && <> · Échéance {formatDateFull(collection.deadline)}</>}
            {collection.closedAt && <> · Clôturée le {formatMomentFull(collection.closedAt)}</>}
          </p>
        </div>
        {progress && (
          <div className="collection__count" aria-label="Réponses">
            <span className="tnum collection__count-value">
              {progress.acknowledged}/{progress.expected}
            </span>
            <span className="muted"> réponses</span>
          </div>
        )}
      </div>

      {progress && (
        <div
          className="progress"
          role="progressbar"
          aria-label="Avancement des réponses"
          aria-valuemin={0}
          aria-valuemax={progress.expected}
          aria-valuenow={progress.acknowledged}
        >
          <span
            className="progress__bar"
            style={{
              width: `${progress.expected === 0 ? 0 : (progress.acknowledged / progress.expected) * 100}%`,
            }}
          />
        </div>
      )}

      {open && collection.deadline && progress && (
        <p className="collection__deadline">
          Échéance : {formatDeadline(collection.deadline)}
          {progress.pending > 0 && ` · ${progress.pending} en attente`}
        </p>
      )}

      {/* A plain member: only their own answer. */}
      {!collection.canManage && mine && (
        <p className="collection__mine">
          {mine.status === 'ACKNOWLEDGED' && mine.acknowledgedAt ? (
            <>Vous avez confirmé vos disponibilités le {formatMoment(mine.acknowledgedAt)}.</>
          ) : open ? (
            <>
              Vos disponibilités sont à renseigner
              {collection.deadline && <> avant le {formatDeadline(collection.deadline)}</>}.{' '}
              <Link to={`/my-availability?collection=${collection.stableId}`}>
                Renseigner mes disponibilités
              </Link>
            </>
          ) : (
            <>Vous n&apos;avez pas répondu à cette collecte.</>
          )}
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
            {expanded ? 'Masquer les réponses' : 'Voir les réponses'}
          </button>
          {open && !editingDeadline && (
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => setEditingDeadline(true)}>
              {collection.deadline ? "Modifier l'échéance" : 'Fixer une échéance'}
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
            Échéance de réponse
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
              Enregistrer l&apos;échéance
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
        <div className="responses" aria-label={`Réponses ${label}`}>
          <div role="group" aria-label="Filtrer les réponses" className="segmented">
            {(Object.keys(FILTER_LABEL) as Filter[]).map((key) => (
              <button
                key={key}
                type="button"
                className="segmented__option"
                aria-pressed={filter === key}
                onClick={() => setFilter(key)}
              >
                {FILTER_LABEL[key]} <span className="tnum">({counts[key]})</span>
              </button>
            ))}
          </div>
          {rows === null && !rowsError && <p className="muted">Chargement des réponses…</p>}
          {rowsError && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{rowsError}</span>
            </p>
          )}
          {rows !== null && visibleRows.length === 0 && (
            <p className="muted">Personne dans cette catégorie.</p>
          )}
          <ul className="list responses__list">
            {visibleRows.map((row) => (
              <li key={row.stableId} className={`response response--${row.status.toLowerCase()}`}>
                <span className="response__mark" aria-hidden="true">
                  {row.status === 'ACKNOWLEDGED' ? '✓' : row.status === 'PENDING' ? '⏳' : '–'}
                </span>
                <span className="response__name">
                  {row.user.lastName} {row.user.firstName}
                </span>
                <span className="response__status">
                  {row.status === 'ACKNOWLEDGED' && row.acknowledgedAt
                    ? `répondu le ${formatMoment(row.acknowledgedAt)}${
                        row.acknowledgementKind === 'NO_UNAVAILABILITY' ? ' · aucune indisponibilité' : ''
                      }`
                    : row.status === 'PENDING'
                      ? 'à renseigner'
                      : 'a quitté le planning'}
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
