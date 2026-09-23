import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchGenerationPreflight, launchGeneration } from './api'
import { formatDateTime, formatLongDate, formatRange, overdueMessage, plural } from './format'
import type { GenerationPreflight, LaunchLineResult, LaunchResult, PreflightIssue } from './types'

type Props = {
  planningStableId: string
  timezone: string
  onClose: () => void
  /** A generation was created: the page refreshes what it shows. */
  onGenerated: () => void
}

/**
 * "Générer le planning" (docs/decisions.md D129). First the preflight — the
 * period, who confirmed, who did not, how many unavailabilities the period
 * holds, the deadline, warnings — then the explicit confirmation. Members
 * still pending and a passed deadline only ever produce *warnings*; the
 * button stays available ("Générer quand même"). Only a technical
 * impossibility (no rule set…) blocks, and says so.
 */
export function GenerationModal({ planningStableId, timezone, onClose, onGenerated }: Props) {
  const [preflight, setPreflight] = useState<GenerationPreflight | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [launching, setLaunching] = useState(false)
  const [launchError, setLaunchError] = useState<string | null>(null)
  const [result, setResult] = useState<LaunchResult | null>(null)
  // A ref, not state: two clicks in the same tick both read the state as "idle".
  const inFlight = useRef(false)

  useEffect(() => {
    let cancelled = false
    fetchGenerationPreflight(planningStableId)
      .then((value) => {
        if (!cancelled) setPreflight(value)
      })
      .catch(() => {
        if (!cancelled) setLoadError('Impossible de contrôler ce planning avant la génération.')
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId])

  async function generate() {
    if (inFlight.current) {
      return
    }
    inFlight.current = true
    setLaunching(true)
    setLaunchError(null)
    try {
      setResult(await launchGeneration(planningStableId))
      onGenerated()
    } catch (err) {
      setLaunchError(launchErrorMessage(err))
    } finally {
      inFlight.current = false
      setLaunching(false)
    }
  }

  const everybodyConfirmed = preflight !== null && preflight.pendingCount === 0
  const confirmLabel =
    everybodyConfirmed && preflight.warnings.length === 0 ? 'Générer' : 'Générer quand même'

  return (
    <Overlay
      title={result ? 'Génération terminée' : 'Générer le planning ?'}
      onClose={onClose}
      dismissible={!launching}
      footer={
        result ? (
          <button type="button" className="btn btn--primary" onClick={onClose} data-autofocus>
            Fermer
          </button>
        ) : (
          <>
            <button type="button" className="btn btn--secondary" onClick={onClose} disabled={launching}>
              Annuler
            </button>
            <button
              type="button"
              className="btn btn--primary"
              onClick={generate}
              disabled={!preflight || !preflight.canGenerate || launching}
              aria-busy={launching}
            >
              {launching ? 'Génération en cours…' : confirmLabel}
            </button>
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
      {launching && (
        <p role="status" className="muted">
          La génération peut durer quelques secondes…
        </p>
      )}
      {launchError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{launchError}</span>
        </p>
      )}
      {result && <ResultBody result={result} timezone={timezone} />}
    </Overlay>
  )
}

function PreflightBody({ preflight }: { preflight: GenerationPreflight }) {
  return (
    <>
      <ul className="preflight-facts">
        <li>
          <span className="muted">Période :</span>{' '}
          <strong className="tnum">
            {formatRange(preflight.planning.startsAt, preflight.planning.lastDay)}
          </strong>
        </li>
        <li>{plural(preflight.participantCount, 'membre participe', 'membres participent')}</li>
        <li>
          {preflight.pendingCount === 0
            ? 'Tous ont confirmé leurs disponibilités'
            : preflight.confirmedCount === 1
              ? '1 a confirmé ses disponibilités'
              : `${preflight.confirmedCount} ont confirmé leurs disponibilités`}
        </li>
        {preflight.pendingCount > 0 && (
          <li>{plural(preflight.pendingCount, 'n’a pas encore répondu', 'n’ont pas encore répondu')}</li>
        )}
        <li>
          {plural(
            preflight.unavailabilityCount,
            'indisponibilité est enregistrée',
            'indisponibilités sont enregistrées',
          )}
        </li>
        {preflight.availabilityDeadline && (
          <li>Fin souhaitée d’encodage : {formatLongDate(preflight.availabilityDeadline)}</li>
        )}
      </ul>

      {preflight.blockers.length > 0 && (
        <ul className="preflight-issues" aria-label="Points bloquants">
          {preflight.blockers.map((issue, index) => (
            <li key={`${issue.code}-${issue.lineStableId ?? index}`} className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{blockerText(issue)}</span>
            </li>
          ))}
        </ul>
      )}
      {preflight.warnings.length > 0 && (
        <ul className="preflight-issues" aria-label="Avertissements">
          {preflight.warnings.map((issue, index) => (
            <li key={`${issue.code}-${issue.lineStableId ?? index}`} className="alert alert--warning">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{warningText(issue, preflight)}</span>
            </li>
          ))}
        </ul>
      )}

      <p className="muted">Cette génération utilisera l’état actuel des données.</p>
    </>
  )
}

function ResultBody({ result, timezone }: { result: LaunchResult; timezone: string }) {
  return (
    <ul className="preflight-issues" aria-label="Résultat de la génération">
      {result.lines.map((line) => (
        <li key={line.lineStableId} className={`alert ${lineTone(line)}`}>
          <Icon name={lineTone(line) === 'alert--success' ? 'check' : 'alert'} size={18} strokeWidth={2} />
          <span>
            <strong>{line.lineName}</strong> — {lineText(line)}
            {line.snapshot && (
              <span className="muted">
                {' '}
                État figé le {formatDateTime(line.snapshot.capturedAt, timezone)} (
                {plural(line.snapshot.memberCount, 'membre')},{' '}
                {plural(line.snapshot.unavailableCount, 'indisponibilité')}).
              </span>
            )}
          </span>
        </li>
      ))}
    </ul>
  )
}

function lineTone(line: LaunchLineResult): string {
  return line.status === 'COMPLETED' && line.coverageStatus === 'COMPLETE'
    ? 'alert--success'
    : 'alert--warning'
}

function lineText(line: LaunchLineResult): string {
  if (line.error) {
    return 'la génération n’a pas pu aboutir.'
  }
  if (line.status !== 'COMPLETED') {
    return 'aucun résultat exploitable n’a été produit.'
  }
  const assigned = plural(line.assignmentCount ?? 0, 'garde affectée', 'gardes affectées')
  return line.coverageStatus === 'COMPLETE'
    ? `couverture complète, ${assigned}.`
    : `couverture incomplète, ${assigned}, ${plural(line.unassignedDutyCount ?? 0, 'garde non pourvue', 'gardes non pourvues')}.`
}

function blockerText(issue: PreflightIssue): string {
  const line = issue.lineName ? `Ligne « ${issue.lineName} » : ` : ''
  switch (issue.code) {
    case 'NO_ACTIVE_RULE_SET':
      return `${line}aucune règle de planning n’est active.`
    case 'NO_DUTIES':
      return `${line}aucune garde à répartir sur cette période.`
    case 'PERIOD_LOCKED':
      return `${line}la période est publiée ou archivée : une nouvelle génération est impossible.`
    case 'NO_SOLVER_PARAMETER_SET':
      return 'Le moteur de génération n’est pas configuré.'
    default:
      return `${line}génération impossible.`
  }
}

function warningText(issue: PreflightIssue, preflight: GenerationPreflight): string {
  const line = issue.lineName ? `Ligne « ${issue.lineName} » : ` : ''
  switch (issue.code) {
    case 'PENDING_MEMBERS':
      return `${plural(preflight.pendingCount, 'membre n’a pas', 'membres n’ont pas')} confirmé ${preflight.pendingCount > 1 ? 'leurs' : 'ses'} disponibilités : les indisponibilités déjà enregistrées seront prises en compte, les autres non.`
    case 'DEADLINE_PASSED':
      return `${overdueMessage(preflight.deadlineOverdueDays ?? 0)} Elle reste indicative et ne bloque pas la génération.`
    case 'VALIDATION_WILL_BE_INVALIDATED':
      return `${line}le planning est validé : générer à nouveau l’invalidera, il faudra le valider de nouveau.`
    case 'LINE_WITHOUT_MEMBERS':
      return `${line}aucun participant : ses gardes ne pourront pas être pourvues.`
    default:
      return issue.code
  }
}

function launchErrorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    const code = (err.body as { error?: string } | null)?.error
    if (code === 'generation_in_progress') {
      return 'Une génération de ce planning est déjà en cours. Patientez quelques instants.'
    }
    if (code === 'not_launchable') {
      return 'La génération est impossible : un prérequis manque.'
    }
  }
  return 'La génération a échoué.'
}
