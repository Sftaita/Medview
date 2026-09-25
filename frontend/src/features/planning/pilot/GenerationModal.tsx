import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchGenerationPreflight, launchGeneration } from './api'
import { formatDateTime, formatLongDate, formatRange, overdueMessage, plural } from './format'
import type {
  GenerationPreflight,
  LaunchLineResult,
  LaunchResult,
  PreflightIssue,
  RestPolicyChoice,
  StructuralDiagnosticEntry,
  UnsatDiagnosticsPayload,
} from './types'

const NO_REST_POLICY: RestPolicyChoice = {
  legalMinRestEnabled: false,
  legalMinRestHours: null,
  teamMinRestEnabled: false,
  teamMinRestHours: null,
}

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
  const [restPolicy, setRestPolicy] = useState<RestPolicyChoice>(NO_REST_POLICY)
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
      setResult(await launchGeneration(planningStableId, restPolicy))
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

      {preflight && !result && (
        <PreflightBody
          preflight={preflight}
          restPolicy={restPolicy}
          onRestPolicyChange={setRestPolicy}
          disabled={launching}
        />
      )}
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

function PreflightBody({
  preflight,
  restPolicy,
  onRestPolicyChange,
  disabled,
}: {
  preflight: GenerationPreflight
  restPolicy: RestPolicyChoice
  onRestPolicyChange: (next: RestPolicyChoice) => void
  disabled: boolean
}) {
  const familyCounts = mergedFamilyUnitCounts(preflight)

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
        {familyCounts.length > 0 &&
          familyCounts.map(([name, count]) => (
            <li key={name || '__no_family'}>
              {plural(count, 'garde à répartir', 'gardes à répartir')} — {name || 'Sans famille'}
            </li>
          ))}
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

      <RestPolicyFields value={restPolicy} onChange={onRestPolicyChange} disabled={disabled} />

      <p className="muted">Cette génération utilisera l’état actuel des données.</p>
    </>
  )
}

/** One entry per AllocationFamily name across every active line, summed —
 * never a hardcoded "Week-end"/"Semaine" (docs/decisions.md D136/D137). */
function mergedFamilyUnitCounts(preflight: GenerationPreflight): [string, number][] {
  const merged: Record<string, number> = {}
  for (const line of preflight.lines) {
    for (const [name, count] of Object.entries(line.familyUnitCounts)) {
      merged[name] = (merged[name] ?? 0) + count
    }
  }
  return Object.entries(merged).sort(([a], [b]) => a.localeCompare(b))
}

/**
 * "Règles de repos" — the one generation rule genuinely consumed by the
 * solver today (docs/decisions.md D105/D137): LEGAL_MIN_REST/TEAM_MIN_REST,
 * both disabled by default (never a guessed regulatory value). Left
 * disabled, the launch is identical to before D137.
 */
function RestPolicyFields({
  value,
  onChange,
  disabled,
}: {
  value: RestPolicyChoice
  onChange: (next: RestPolicyChoice) => void
  disabled: boolean
}) {
  return (
    <fieldset className="rest-policy-fields" disabled={disabled}>
      <legend>Règles de repos</legend>
      <label className="rest-policy-fields__row">
        <input
          type="checkbox"
          checked={value.legalMinRestEnabled}
          onChange={(e) =>
            onChange({
              ...value,
              legalMinRestEnabled: e.target.checked,
              legalMinRestHours: e.target.checked ? (value.legalMinRestHours ?? 11) : null,
            })
          }
        />
        Repos légal minimum
        {value.legalMinRestEnabled && (
          <>
            {' '}
            <input
              type="number"
              min={1}
              aria-label="Repos légal minimum, en heures"
              value={value.legalMinRestHours ?? ''}
              onChange={(e) => onChange({ ...value, legalMinRestHours: Number(e.target.value) || null })}
            />{' '}
            heures
          </>
        )}
      </label>
      <label className="rest-policy-fields__row">
        <input
          type="checkbox"
          checked={value.teamMinRestEnabled}
          onChange={(e) =>
            onChange({
              ...value,
              teamMinRestEnabled: e.target.checked,
              teamMinRestHours: e.target.checked ? (value.teamMinRestHours ?? 24) : null,
            })
          }
        />
        Repos minimum d’équipe
        {value.teamMinRestEnabled && (
          <>
            {' '}
            <input
              type="number"
              min={1}
              aria-label="Repos minimum d’équipe, en heures"
              value={value.teamMinRestHours ?? ''}
              onChange={(e) => onChange({ ...value, teamMinRestHours: Number(e.target.value) || null })}
            />{' '}
            heures
          </>
        )}
      </label>
      <p className="muted">
        Une règle désactivée n’est jamais appliquée. Le repos minimum d’équipe est une règle propre à cette
        équipe — jamais une obligation légale.
      </p>
    </fieldset>
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
            {line.status === 'COMPLETED' && line.coverageStatus === 'COMPLETE' && (
              <span className="muted"> {optimalityText(line.strictSolverStatus)}</span>
            )}
          </span>
          {line.diagnostics && <DiagnosticsBody diagnostics={line.diagnostics} />}
        </li>
      ))}
    </ul>
  )
}

/** OPTIMAL vs FEASIBLE must never be conflated (docs/decisions.md D137,
 * §20 of the spec): a complete-but-not-proven-optimal result says so
 * explicitly, never "optimal planning". */
function optimalityText(strictSolverStatus: string | null): string {
  return strictSolverStatus === 'OPTIMAL'
    ? 'Planning complet trouvé et prouvé optimal.'
    : 'Planning complet trouvé. L’optimalité mathématique n’a pas pu être démontrée dans le temps de calcul disponible.'
}

/**
 * Reuses `UnsatReportPresenter`'s real diagnostic (docs/decisions.md D137)
 * — never a causality invented in React. A HARD reason (no eligible
 * candidate) is shown as-is, with no relaxation; a POLICY_HARD relaxation
 * is shown only when the backend itself produced one, using its own
 * `phrasing`/`disclaimer` verbatim.
 */
function DiagnosticsBody({ diagnostics }: { diagnostics: UnsatDiagnosticsPayload }) {
  const reasonCounts = new Map<string, number>()
  let criticalCount = 0
  for (const duty of diagnostics.unassignedDuties) {
    if (duty.critical) criticalCount++
    for (const candidate of duty.candidateExclusions) {
      for (const exclusion of candidate.exclusions) {
        reasonCounts.set(exclusion.reason, (reasonCounts.get(exclusion.reason) ?? 0) + 1)
      }
    }
  }

  return (
    <div className="diagnostics-body">
      <p>
        {plural(diagnostics.assignedDutyCount, 'garde affectée')} sur{' '}
        {plural(diagnostics.requiredDutyCount, 'requise')}.{' '}
        {criticalCount > 0 &&
          plural(criticalCount, 'garde non pourvue est bloquante', 'gardes non pourvues sont bloquantes')}
      </p>
      {reasonCounts.size > 0 && (
        <ul>
          {[...reasonCounts.entries()].map(([reason, count]) => (
            <li key={reason}>
              {reasonText(reason)} ({count})
            </li>
          ))}
        </ul>
      )}
      {diagnostics.structuralDiagnostics.length > 0 && (
        <ul>
          {dedupeStructural(diagnostics.structuralDiagnostics).map((code) => (
            <li key={code}>{structuralDiagnosticText(code)}</li>
          ))}
        </ul>
      )}
      {diagnostics.diagnosticRelaxations.length > 0 && (
        <ul aria-label="Relaxations possibles">
          {diagnostics.diagnosticRelaxations.map((relaxation, index) => (
            <li key={`${relaxation.ruleCode}-${index}`} className="alert alert--warning">
              <span>
                {relaxation.phrasing} <span className="muted">{relaxation.disclaimer}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function dedupeStructural(entries: StructuralDiagnosticEntry[]): string[] {
  return [...new Set(entries.map((entry) => entry.code))]
}

function structuralDiagnosticText(code: string): string {
  switch (code) {
    case 'NO_ELIGIBLE_CANDIDATE':
      return 'Au moins une garde n’a aucun candidat éligible.'
    case 'INSUFFICIENT_ELIGIBLE_CAPACITY':
      return 'Deux gardes liées ne peuvent pas être couvertes ensemble : un seul candidat est éligible pour les deux.'
    default:
      return code
  }
}

function reasonText(reason: string): string {
  switch (reason) {
    case 'USER_INACTIVE':
      return 'Compte désactivé'
    case 'NOT_TEAM_MEMBER':
      return 'N’appartient pas à l’équipe'
    case 'MEMBERSHIP_OUT_OF_RANGE':
      return 'Hors de la période d’appartenance à l’équipe'
    case 'UNAVAILABLE':
      return 'Indisponibilité déclarée'
    case 'NON_PARTICIPATION':
      return 'Non-participation administrative'
    case 'CONFLICT':
      return 'Conflit avec une autre garde déjà affectée'
    case 'LEGAL_MIN_REST':
      return 'Repos légal minimum'
    case 'TEAM_MIN_REST':
      return 'Repos minimum d’équipe'
    case 'MAX_DUTIES':
      return 'Nombre maximum de gardes atteint'
    case 'MAX_WEEKENDS':
      return 'Nombre maximum de week-ends atteint'
    case 'MAX_CONSECUTIVE_NIGHTS':
      return 'Nombre maximum de nuits consécutives atteint'
    default:
      return reason
  }
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
