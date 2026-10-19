import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchPublicationPreflight, republishPlanning } from '../result/api'
import { undeliveredMessage } from '../result/publicationDelivery'
import type { PublicationChange, PublicationPreflight, PublicationResult } from '../result/types'
import { NewLinesChooser } from './NewLinesChooser'
import { PreflightIssueList } from './PreflightIssueList'
import { blockingIssues } from './preflightIssues'

type Props = {
  planningStableId: string
  /** The period republished (docs/planning-cycles.md §7.4). */
  periodStableId?: string
  changes: PublicationChange[]
  onClose: () => void
  onRepublished: (result: PublicationResult) => void
  /** "Voir dans le calendrier" on a blocking issue: the dialog closes and the calendar shows the duty. */
  onLocate?: (dutyStableId: string) => void
}

const DAY = new Intl.DateTimeFormat('fr-BE', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  timeZone: 'UTC',
})

/** docs/decisions.md D166: `shown: false` is a reinforcement nobody needs — never presented as a missing holder. */
function holder(person: { firstName: string; lastName: string } | null, shown = true): string {
  if (person) return `${person.firstName} ${person.lastName}`
  return shown ? 'Non attribué' : 'Pas de renfort'
}

/**
 * "Republier les modifications" (docs/decisions.md D143, D172): lists what
 * changed since the last diffusion and who will be told — only the people
 * whose own duties change, each with their own changes. The server re-checks the calendar and
 * recomputes the changes at the moment of the click; this list is a preview.
 */
export function RepublishModal({
  planningStableId,
  periodStableId,
  changes,
  onClose,
  onRepublished,
  onLocate,
}: Props) {
  const [preflight, setPreflight] = useState<PublicationPreflight | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<PublicationResult | null>(null)
  // docs/decisions.md D196: lines never published yet go out only when chosen here — none by default.
  const [newLines, setNewLines] = useState<Set<string>>(new Set())
  const inFlight = useRef(false)

  useEffect(() => {
    let cancelled = false
    fetchPublicationPreflight(planningStableId, periodStableId)
      .then((value) => {
        if (!cancelled) setPreflight(value)
      })
      .catch(() => {
        if (!cancelled) setLoadError('Impossible de contrôler le planning.')
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId, periodStableId])

  async function confirm() {
    if (inFlight.current) return
    inFlight.current = true
    setSending(true)
    setError(null)
    try {
      const value = await republishPlanning(planningStableId, [...newLines], periodStableId)
      setResult(value)
      onRepublished(value)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      inFlight.current = false
      setSending(false)
    }
  }

  const publishedIds = new Set(
    (preflight?.lines ?? []).filter((line) => line.published !== false).map((line) => line.lineStableId),
  )
  const unpublished = (preflight?.lines ?? []).filter((line) => line.published === false)
  const hasSomethingToSend = changes.length > 0 || newLines.size > 0

  // One row per changed unit: a block's days share its group id and move together.
  const units: { key: string; dates: string[]; change: PublicationChange }[] = []
  for (const change of changes) {
    const key = change.groupInstanceStableId ?? change.dutyStableId
    const existing = units.find((unit) => unit.key === key)
    if (existing) existing.dates.push(change.date)
    else units.push({ key, dates: [change.date], change })
  }

  return (
    <Overlay
      title={
        result
          ? 'Modifications republiées'
          : changes.length > 0
            ? 'Republier les modifications ?'
            : 'Publier de nouvelles lignes ?'
      }
      onClose={onClose}
      dismissible={!sending}
      footer={
        result ? (
          <button type="button" className="btn btn--primary" onClick={onClose} data-autofocus>
            Fermer
          </button>
        ) : (
          <>
            <button type="button" className="btn btn--secondary" onClick={onClose} disabled={sending}>
              Annuler
            </button>
            {preflight?.republishable && (
              <button
                type="button"
                className="btn btn--primary"
                onClick={confirm}
                disabled={sending || !hasSomethingToSend}
                aria-busy={sending}
              >
                {sending ? 'Envoi…' : changes.length > 0 ? 'Republier' : 'Publier'}
              </button>
            )}
          </>
        )
      }
    >
      {result ? (
        <>
          <p className="alert alert--success">
            <Icon name="check" size={18} strokeWidth={2} />
            <span>{republishedMessage(result)}</span>
          </p>
          {undeliveredMessage(result) && (
            <p role="alert" className="alert alert--warning">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{undeliveredMessage(result)}</span>
            </p>
          )}
        </>
      ) : (
        <>
          {/* What blocks comes first: with dozens of changes listed, a refusal at the bottom goes unseen. */}
          {!preflight && !loadError && (
            <p role="status" className="muted">
              Contrôle du planning…
            </p>
          )}
          {preflight && !preflight.republishable && (
            <RepublishBlockers preflight={preflight} publishedIds={publishedIds} onLocate={onLocate} />
          )}
          <ul className="cal-changes" aria-label="Modifications non publiées">
            {units.map(({ key, dates, change }) => (
              <li key={key}>
                <span className="cal-changes__when">
                  {dates.length > 1
                    ? `Du ${DAY.format(new Date(`${dates[0]}T00:00:00Z`))} au ${DAY.format(new Date(`${dates[dates.length - 1]}T00:00:00Z`))}`
                    : DAY.format(new Date(`${dates[0]}T00:00:00Z`))}{' '}
                  · {change.lineName}
                </span>
                <span>
                  {holder(change.before, change.beforeShown)} →{' '}
                  <strong>{holder(change.after, change.afterShown)}</strong>
                </span>
              </li>
            ))}
          </ul>
          <NewLinesChooser
            lines={unpublished}
            publishedIds={publishedIds}
            selected={newLines}
            onChange={setNewLines}
            legend="Nouvelles lignes à publier"
            disabled={sending}
          />
          {newLines.size > 0 && (
            <p className="muted">
              Les membres des lignes cochées recevront le planning en PDF, comme pour une première
              publication.
            </p>
          )}
          <p className="muted">
            Seules les personnes dont les gardes changent reçoivent un email : l’ancien titulaire (garde
            retirée) et le nouveau (garde ajoutée). Chacune n’y voit que ses propres changements, avec le
            planning actualisé en PDF.
          </p>
        </>
      )}
      {(loadError || error) && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{loadError ?? error}</span>
        </p>
      )}
    </Overlay>
  )
}

/**
 * Why the calendar cannot go out as it is — each issue with its duty or
 * block, its line, the person, the rule and the fix (never only "des
 * affectations ne sont plus valides").
 */
function RepublishBlockers({
  preflight,
  publishedIds,
  onLocate,
}: {
  preflight: PublicationPreflight
  publishedIds: ReadonlySet<string>
  onLocate?: (dutyStableId: string) => void
}) {
  // docs/decisions.md D196: only the published lines decide a republication.
  const issues = blockingIssues(preflight, 'republish', publishedIds)
  return (
    <>
      <p role="alert" className="alert alert--error">
        <Icon name="alert" size={18} strokeWidth={2} />
        <span>
          {issues.length === 0
            ? 'Republication impossible : le calendrier n’est pas prêt à être diffusé.'
            : `Republication impossible : ${issues.length === 1 ? '1 point à corriger' : `${issues.length} points à corriger`} d’abord.`}
        </span>
      </p>
      <PreflightIssueList issues={issues} onLocate={onLocate} />
    </>
  )
}

/** docs/decisions.md D172: one personal email per person whose own duties changed. */
function republishedMessage(result: PublicationResult): string {
  const firstLines = result.lines
    .filter((line) => line.kind === 'FIRST_FOR_LINE')
    .map((line) => `« ${line.lineName} »`)
  if (firstLines.length > 0 && !result.lines.some((line) => line.kind === 'UPDATED'))
    return `${firstLines.length > 1 ? 'Lignes publiées' : 'Ligne publiée'} : ${firstLines.join(', ')}. Leurs membres reçoivent le planning en PDF.`
  if (result.recipientCount === 0)
    return 'Planning republié. Aucune personne n’est concernée : aucun email envoyé.'
  const people =
    result.recipientCount > 1 ? `${result.recipientCount} personnes concernées` : '1 personne concernée'
  // Never "each one receives" while some emails are still to be retried: the warning below says which part is late.
  if (result.sentCount < result.recipientCount)
    return `Planning republié. ${people} : un email personnel avec ses propres changements et le planning actualisé en PDF.`
  return `Planning republié. ${people} — chacune reçoit le détail de ses propres changements et le planning actualisé en PDF.`
}

function errorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    const code = (err.body as { error?: string } | null)?.error
    if (code === 'no_changes') return 'Il n’y a plus aucune modification à publier.'
    if (code === 'publication_in_progress') return 'Une publication de ce planning est déjà en cours.'
    if (code === 'not_publishable')
      return 'Le calendrier a changé et ne peut plus être republié tel quel. Fermez et réessayez.'
    if (code === 'line_not_publishable' || code === 'source_line_not_published')
      return 'Une des nouvelles lignes cochées ne peut plus être publiée telle quelle. Fermez et réessayez.'
  }
  return 'La republication a échoué.'
}
