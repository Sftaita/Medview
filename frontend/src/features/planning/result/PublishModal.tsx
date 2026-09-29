import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchPublicationPreflight, publishPlanning } from './api'
import { PreflightIssueList } from '../calendar/PreflightIssueList'
import { blockingIssues } from '../calendar/preflightIssues'
import type { PublicationPreflight, PublicationResult } from './types'

type Props = {
  planningStableId: string
  onClose: () => void
  /** The planning was actually published: carries the real, server-returned per-line statuses. */
  onPublished: (result: PublicationResult) => void
  /** "Voir dans le calendrier" on a blocking issue: the dialog closes and the calendar shows the duty. */
  onLocate?: (dutyStableId: string) => void
}

/**
 * "Publier le planning" (docs/decisions.md D133). The preflight reads the
 * *current* calendar, never the solver's original historical result.
 * Explicit save, like every other write in this app: loading the preflight
 * never publishes anything — only the final "Publier" click does, and the
 * server always re-validates for real at that moment (§11/§19 of the
 * spec), never trusting what this modal showed when it opened.
 */
export function PublishModal({ planningStableId, onClose, onPublished, onLocate }: Props) {
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

      {preflight && !result && <PreflightBody preflight={preflight} onLocate={onLocate} />}

      {publishError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{publishError}</span>
        </p>
      )}

      {result && (
        <p className="alert alert--success">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>
            Planning publié. {result.recipientCount} participant{result.recipientCount > 1 ? 's ont' : ' a'}{' '}
            reçu l’email de publication avec le PDF du planning.
          </span>
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

function PreflightBody({
  preflight,
  onLocate,
}: {
  preflight: PublicationPreflight
  onLocate?: (dutyStableId: string) => void
}) {
  if (preflight.publishable) {
    return (
      <>
        <p className="alert alert--success">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>
            Le calendrier actuel est complet et cohérent : il peut être publié. Chaque participant recevra un
            email avec le PDF du planning.
          </span>
        </p>
        <SuperfluousWarnings items={preflight.superfluousCoverages} />
      </>
    )
  }

  // docs/decisions.md D167: a missing reinforcement is named as one — the backend says which duty is conditional.
  const missingDuties = preflight.uncoveredDuties.filter((duty) => !duty.conditional)
  const missingReinforcements = preflight.uncoveredDuties.filter((duty) => duty.conditional)

  const totalIssues =
    preflight.uncoveredDuties.length +
    preflight.inconsistentGroups.length +
    preflight.invalidAssignments.length +
    preflight.conflicts.length +
    preflight.undeterminedDuties.length

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
        {missingDuties.length > 0 && (
          <li className="alert alert--warning">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>
              {plural(missingDuties.length, 'garde obligatoire reste', 'gardes obligatoires restent')} non couverte
              {missingDuties.length > 1 ? 's' : ''}.
            </span>
          </li>
        )}
        {missingReinforcements.length > 0 && (
          <li className="alert alert--warning">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>
              {plural(missingReinforcements.length, 'renfort requis n’est', 'renforts requis ne sont')} pas attribué
              {missingReinforcements.length > 1 ? 's' : ''}
              {missingReinforcements[0].lineName ? ` (ligne « ${missingReinforcements[0].lineName} »)` : ''}.
            </span>
          </li>
        )}
        {preflight.undeterminedDuties.length > 0 && (
          <li className="alert alert--warning">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>
              {plural(preflight.undeterminedDuties.length, 'renfort ne peut', 'renforts ne peuvent')} pas être
              évalué{preflight.undeterminedDuties.length > 1 ? 's' : ''} : la garde dont{' '}
              {preflight.undeterminedDuties.length > 1 ? 'ils dépendent' : 'il dépend'} n’a pas de titulaire.
            </span>
          </li>
        )}
      </ul>
      {/* Each refused assignment on its own — duty or block, person, rule, fix — never a count and a first reason. */}
      <PreflightIssueList
        issues={blockingIssues(preflight, 'publish').filter(
          (issue) => issue.kind === 'assignment' || issue.kind === 'inconsistent',
        )}
        onLocate={onLocate}
        label="Affectations à corriger"
      />
      {totalIssues === 0 && preflight.lines.every((line) => !line.hasGeneration) && (
        <p className="muted">Aucune ligne de ce planning n&apos;a encore été générée.</p>
      )}
      <SuperfluousWarnings items={preflight.superfluousCoverages} />
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

/**
 * docs/decisions.md D166: a reinforcement no longer required but still held
 * — shown, never blocking, never removed automatically.
 */
function SuperfluousWarnings({ items }: { items: PublicationPreflight['superfluousCoverages'] }) {
  if (items.length === 0) return null
  return (
    <>
      <h3 className="preflight-heading">Avertissements — ils n’empêchent pas la publication</h3>
      <ul className="preflight-issues" aria-label="Avertissements">
      {items.map((item) => (
        <li key={item.unitStableKey} className="alert alert--warning">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>
            Ligne « {item.lineName} » : {item.explanation}
          </span>
        </li>
      ))}
      </ul>
    </>
  )
}
