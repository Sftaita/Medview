import { Icon } from '../../../components/Icon'
import type { LaunchLineResult, PlanningJob } from '../pilot/types'
import type { CompletionLineResult } from '../result/types'
import { isActiveJob } from './usePlanningJob'
import './jobs.css'

type Props = {
  /** The planning's latest job (usePlanningJob). */
  job: PlanningJob | null
  /** The job this screen saw finish — the only one whose success is announced. */
  finished: PlanningJob | null
  /** A manager may relaunch after a failure. */
  canRelaunch: boolean
  onRelaunch: (kind: PlanningJob['kind']) => void
  /** GENERATE finished: open the per-line detail (coverage, diagnostics). */
  onShowDetail?: (job: PlanningJob) => void
  onDismiss: () => void
}

const RUNNING_TITLE: Record<PlanningJob['kind'], string> = {
  GENERATE: 'Génération du planning en cours…',
  COMPLETE: 'Complétion du planning en cours…',
}

const FAILED_TITLE: Record<PlanningJob['kind'], string> = {
  GENERATE: 'La génération du planning a échoué.',
  COMPLETE: 'La complétion automatique a échoué.',
}

/** Plain-language cause of a failure — from its stable code, never an internal message. */
function failureText(job: PlanningJob): string {
  switch (job.failureCode) {
    case 'calendar_changed':
      return 'Le planning a été modifié pendant le calcul : rien n’a été enregistré.'
    case 'not_launchable':
      return 'Un prérequis de la génération manquait au moment du calcul.'
    case 'worker_lost':
      return 'Le calcul a été interrompu avant sa fin.'
    case 'never_started':
      return 'Le calcul n’a pas pu démarrer.'
    case 'generation_failed':
    case 'completion_failed':
      return 'Le moteur n’a pas pu produire de résultat exploitable.'
    default:
      return 'Une erreur technique est survenue.'
  }
}

function successText(job: PlanningJob): string {
  if (!job.outcome) {
    // A member sees the job's state, never its detail (docs/decisions.md D149): nothing is claimed.
    return job.kind === 'COMPLETE'
      ? 'Complétion terminée : le planning est à jour.'
      : 'Génération terminée : le planning est à jour.'
  }
  if (job.kind === 'COMPLETE') {
    const lines = (job.outcome?.lines ?? []) as CompletionLineResult[]
    const filled = lines.reduce((sum, line) => sum + line.filledUnitCount, 0)
    const remaining = lines.reduce((sum, line) => sum + line.remainingUncoveredRequiredUnitCount, 0)
    if (filled === 0 && remaining > 0) {
      return `Aucune garde n’a pu être attribuée : ${remaining} reste${remaining > 1 ? 'nt' : ''} non attribuée${remaining > 1 ? 's' : ''} (personne n’est disponible).`
    }
    if (filled === 0) {
      return 'Aucune garde non attribuée : rien à compléter.'
    }
    return (
      `${filled} garde${filled > 1 ? 's' : ''} ou bloc${filled > 1 ? 's' : ''} attribué${filled > 1 ? 's' : ''} automatiquement, sans toucher aux affectations existantes.` +
      (remaining > 0
        ? ` ${remaining} reste${remaining > 1 ? 'nt' : ''} non attribuée${remaining > 1 ? 's' : ''}.`
        : '')
    )
  }

  const lines = (job.outcome?.lines ?? []) as LaunchLineResult[]
  const uncovered = lines.reduce((sum, line) => sum + (line.unassignedDutyCount ?? 0), 0)
  return job.outcome?.coverage === 'INCOMPLETE'
    ? `Planning généré, couverture incomplète : ${uncovered} garde${uncovered > 1 ? 's' : ''} obligatoire${uncovered > 1 ? 's' : ''} sans titulaire.`
    : 'Planning généré : toutes les gardes obligatoires sont couvertes.'
}

/**
 * Where an engine job stands (docs/decisions.md D149), on every tab of the
 * planning: running (an indeterminate indicator — the solver gives no
 * reliable progress, so no invented percentage), failed (with "Relancer"),
 * or just finished on this screen.
 */
export function PlanningJobBanner({
  job,
  finished,
  canRelaunch,
  onRelaunch,
  onShowDetail,
  onDismiss,
}: Props) {
  if (job && isActiveJob(job)) {
    return (
      <section
        className="job-banner job-banner--running"
        role="status"
        aria-live="polite"
        aria-label="Calcul en cours"
      >
        <span className="job-spinner" aria-hidden />
        <div className="job-banner__text">
          <strong>{RUNNING_TITLE[job.kind]}</strong>
          <span>
            {job.status === 'QUEUED' ? 'En attente de démarrage. ' : ''}Vous pouvez quitter cette page. Le
            calcul continuera en arrière-plan.
          </span>
        </div>
      </section>
    )
  }

  if (job && job.status === 'FAILED' && (finished === null || finished.stableId === job.stableId)) {
    return (
      <section className="job-banner job-banner--failed" role="alert">
        <Icon name="alert" size={20} strokeWidth={2} />
        <div className="job-banner__text">
          <strong>{FAILED_TITLE[job.kind]}</strong>
          <span>{failureText(job)}</span>
        </div>
        {canRelaunch && (
          <button type="button" className="pd-btn pd-btn-primary" onClick={() => onRelaunch(job.kind)}>
            Relancer
          </button>
        )}
      </section>
    )
  }

  if (finished && finished.status === 'SUCCEEDED' && job?.stableId === finished.stableId) {
    const incomplete = finished.outcome?.coverage === 'INCOMPLETE'
    return (
      <section
        className={`job-banner ${incomplete ? 'job-banner--warning' : 'job-banner--success'}`}
        role="status"
      >
        <Icon name={incomplete ? 'alert' : 'check'} size={20} strokeWidth={2} />
        <div className="job-banner__text">
          <span>{successText(finished)}</span>
        </div>
        {finished.kind === 'GENERATE' && finished.outcome && onShowDetail && (
          <button type="button" className="pd-btn pd-btn-secondary" onClick={() => onShowDetail(finished)}>
            Voir le détail
          </button>
        )}
        <button type="button" className="pd-icon-btn" aria-label="Masquer" onClick={onDismiss}>
          <Icon name="x" size={18} strokeWidth={2.2} />
        </button>
      </section>
    )
  }

  return null
}
