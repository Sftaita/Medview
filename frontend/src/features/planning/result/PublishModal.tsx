import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchPublicationPreflight, publishPlanning } from './api'
import type { PublicationPreflight, PublicationResult } from './types'

type Props = {
  planningStableId: string
  onClose: () => void
  /** The planning was actually published: carries the real, server-returned per-line statuses. */
  onPublished: (result: PublicationResult) => void
}

/**
 * "Publier le planning" (docs/decisions.md D133). The preflight reads the
 * *current* calendar, never the solver's original historical result.
 * Explicit save, like every other write in this app: loading the preflight
 * never publishes anything — only the final "Publier" click does, and the
 * server always re-validates for real at that moment (§11/§19 of the
 * spec), never trusting what this modal showed when it opened.
 */
export function PublishModal({ planningStableId, onClose, onPublished }: Props) {
  const [preflight, setPreflight] = useState<PublicationPreflight | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [publishing, setPublishing] = useState(false)
  const [publishError, setPublishError] = useState<string | null>(null)
  const [result, setResult] = useState<PublicationResult | null>(null)
  // A ref, not state: two clicks on "Publier" in the same tick both read the state as "idle".
  const inFlight = useRef(false)

  useEffect(() => {
    let cancelled = false
    fetchPublicationPreflight(planningStableId)
      .then((value) => {
        if (!cancelled) setPreflight(value)
      })
      .catch(() => {
        if (!cancelled) setLoadError('Impossible de contrôler ce planning avant la publication.')
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId])

  async function confirmPublish() {
    if (inFlight.current) {
      return
    }
    inFlight.current = true
    setPublishing(true)
    setPublishError(null)
    try {
      const publicationResult = await publishPlanning(planningStableId)
      setResult(publicationResult)
      onPublished(publicationResult)
    } catch (err) {
      setPublishError(publishErrorMessage(err))
    } finally {
      inFlight.current = false
      setPublishing(false)
    }
  }

  return (
    <Overlay
      title={result ? 'Planning publié' : 'Publier ce planning ?'}
      onClose={onClose}
      dismissible={!publishing}
      footer={
        result ? (
          <button type="button" className="btn btn--primary" onClick={onClose} data-autofocus>
            Fermer
          </button>
        ) : (
          <>
            <button type="button" className="btn btn--secondary" onClick={onClose} disabled={publishing}>
              {preflight && !preflight.publishable ? 'Fermer' : 'Annuler'}
            </button>
            {preflight && preflight.publishable && (
              <button
                type="button"
                className="btn btn--primary"
                onClick={confirmPublish}
                disabled={publishing}
                aria-busy={publishing}
              >
                {publishing ? 'Publication en cours…' : 'Publier'}
              </button>
            )}
          </>
        )
      }
    >
      {!preflight && !loadError && (
        <p role="status" className="muted">
          Contrôle du planning…
        </p>
      )}
      {loadError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{loadError}</span>
        </p>
      )}

      {preflight && !result && <PreflightBody preflight={preflight} />}

      {publishError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{publishError}</span>
        </p>
      )}

      {result && (
        <ul className="preflight-issues" aria-label="Résultat de la publication">
          {result.lines.map((line) => (
            <li key={line.lineStableId} className="alert alert--success">
              <Icon name="check" size={18} strokeWidth={2} />
              <span>
                <strong>{line.lineName}</strong> — {line.alreadyPublished ? 'déjà publiée.' : 'publiée.'}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Overlay>
  )
}

function PreflightBody({ preflight }: { preflight: PublicationPreflight }) {
  if (preflight.publishable) {
    return (
      <p className="alert alert--success">
        <Icon name="check" size={18} strokeWidth={2} />
        <span>Le calendrier actuel est complet et cohérent : il peut être publié.</span>
      </p>
    )
  }

  const totalIssues =
    preflight.uncoveredDuties.length +
    preflight.inconsistentGroups.length +
    preflight.invalidAssignments.length +
    preflight.conflicts.length

  return (
    <>
      <p role="alert" className="alert alert--error">
        <Icon name="alert" size={18} strokeWidth={2} />
        <span>Publication impossible.</span>
      </p>
      <ul className="preflight-issues" aria-label="Points bloquants">
        {preflight.lines
          .filter((line) => !line.hasGeneration)
          .map((line) => (
            <li key={`no-gen-${line.lineStableId}`} className="alert alert--warning">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>Ligne « {line.lineName} » : aucun planning généré pour le moment.</span>
            </li>
          ))}
        {preflight.uncoveredDuties.length > 0 && (
          <li className="alert alert--warning">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>
              {plural(
                preflight.uncoveredDuties.length,
                'garde obligatoire reste',
                'gardes obligatoires restent',
              )}{' '}
              non couverte{preflight.uncoveredDuties.length > 1 ? 's' : ''}.
            </span>
          </li>
        )}
        {preflight.inconsistentGroups.length > 0 && (
          <li className="alert alert--warning">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>
              {plural(preflight.inconsistentGroups.length, 'bloc de garde a', 'blocs de garde ont')} une
              attribution incohérente entre ses journées.
            </span>
          </li>
        )}
        {preflight.invalidAssignments.length > 0 && (
          <li className="alert alert--warning">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>
              {plural(preflight.invalidAssignments.length, 'garde attribuée ne', 'gardes attribuées ne')} sont
              plus valides ({preflight.invalidAssignments[0].reason}
              {preflight.invalidAssignments.length > 1 ? ', …' : ''}).
            </span>
          </li>
        )}
        {preflight.conflicts.length > 0 && (
          <li className="alert alert--warning">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>
              {plural(preflight.conflicts.length, 'conflit', 'conflits')} détecté
              {preflight.conflicts.length > 1 ? 's' : ''} dans le calendrier actuel (
              {preflight.conflicts[0].reason}
              {preflight.conflicts.length > 1 ? ', …' : ''}).
            </span>
          </li>
        )}
      </ul>
      {totalIssues === 0 && preflight.lines.every((line) => !line.hasGeneration) && (
        <p className="muted">Aucune ligne de ce planning n&apos;a encore été générée.</p>
      )}
    </>
  )
}

function plural(count: number, singular: string, plural: string): string {
  return count === 1 ? `1 ${singular}` : `${count} ${plural}`
}

function publishErrorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    const code = (err.body as { error?: string } | null)?.error
    if (code === 'already_published') {
      return 'Ce planning est déjà publié.'
    }
    if (code === 'publication_in_progress') {
      return 'Une publication de ce planning est déjà en cours.'
    }
    if (code === 'not_publishable') {
      return 'Le planning a changé depuis l’ouverture de cette fenêtre et n’est plus publiable. Fermez et réessayez.'
    }
  }
  return 'La publication a échoué.'
}
