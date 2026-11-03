import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchFillPreview, requestFill } from './api'
import { fillPlanText, fillResultText } from './fillText'
import {
  RestPolicyFields,
  SurgicalHubCaveats,
  SurgicalHubPeople,
  type GenerationPeriod,
} from './GenerationModal'
import { NO_REST_POLICY, surgicalHubRefusal } from './generationShared'
import type {
  FillLineResult,
  FillPreview,
  PlanningJob,
  RestPolicyChoice,
  SurgicalHubParticipant,
  SurgicalHubStaleRefusal,
} from './types'
import './fill.css'

type Props = {
  planningStableId: string
  period?: GenerationPeriod
  timezone: string
  /** "Générer cette ligne": only this line is offered. */
  onlyLineStableId?: string
  onClose: () => void
  /** The run was accepted (a QUEUED job): the page follows it like a generation. */
  onLaunched: (job: PlanningJob) => void
}

const plural = (count: number, one: string, many: string) => `${count} ${count > 1 ? many : one}`

/**
 * "Compléter la période N" and "Générer cette ligne" (docs/decisions.md D222,
 * D228). The preview comes from the server: for each line, what would happen —
 * its first generation, the completion of its holes, or nothing — with the
 * assignments it holds, which are never changed. The whole period is one action:
 * every line that can receive something does, a published line only the new dates
 * of an extension (D226), whose additions are unpublished changes (nothing is
 * republished nor sent). The run is queued (202), the page follows the job.
 */
export function FillModal({
  planningStableId,
  period,
  timezone,
  onlyLineStableId,
  onClose,
  onLaunched,
}: Props) {
  const [preview, setPreview] = useState<FillPreview | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [restPolicy, setRestPolicy] = useState<RestPolicyChoice>(NO_REST_POLICY)
  const [launching, setLaunching] = useState(false)
  const [launchError, setLaunchError] = useState<string | null>(null)
  const [stale, setStale] = useState<SurgicalHubStaleRefusal | null>(null)
  const [override, setOverride] = useState(false)
  const [caveats, setCaveats] = useState<{
    warnings: SurgicalHubParticipant[]
    overridden: SurgicalHubParticipant[]
  } | null>(null)
  const inFlight = useRef(false)

  useEffect(() => {
    let cancelled = false
    fetchFillPreview(planningStableId, period?.stableId)
      .then((value) => {
        if (cancelled) return
        setPreview(value)
        setSelected(
          new Set(
            value.lines
              .filter((line) =>
                onlyLineStableId
                  ? line.lineStableId === onlyLineStableId && line.selectable
                  : line.selectedByDefault,
              )
              .map((line) => line.lineStableId),
          ),
        )
      })
      .catch(() => {
        if (!cancelled) setLoadError('Impossible de préparer l’opération. Réessayez.')
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId, period?.stableId, onlyLineStableId])

  const lines = (preview?.lines ?? []).filter(
    (line) => !onlyLineStableId || line.lineStableId === onlyLineStableId,
  )
  const chosen = lines.filter(
    (line) => selected.has(line.lineStableId) && (line.action === 'GENERATE' || line.action === 'COMPLETE'),
  )
  const protectedCount = lines.reduce((sum, line) => sum + line.protectedAssignmentCount, 0)
  const toComplete = chosen
    .filter((line) => line.action === 'COMPLETE')
    .reduce((sum, line) => sum + (line.uncoveredRequiredDutyCount ?? 0), 0)
  const toGenerate = chosen.filter((line) => line.action === 'GENERATE')

  async function run() {
    if (inFlight.current || chosen.length === 0) return
    inFlight.current = true
    setLaunching(true)
    setLaunchError(null)
    try {
      const response = await requestFill(
        planningStableId,
        chosen.map((line) => line.lineStableId),
        toGenerate.length > 0 ? restPolicy : undefined,
        stale !== null && override,
        period?.stableId,
      )
      onLaunched(response.job)
      const hub = response.surgicalHub
      if (hub && hub.warnings.length + hub.overridden.length > 0) {
        setStale(null)
        setCaveats(hub)
      } else {
        onClose()
      }
    } catch (err) {
      const refusal = surgicalHubRefusal(err)
      if (refusal) {
        setStale(refusal)
        setOverride(false)
      } else {
        setLaunchError(fillErrorMessage(err))
      }
    } finally {
      inFlight.current = false
      setLaunching(false)
    }
  }

  const whole = period ? `Compléter la période ${period.number}` : 'Compléter la période'
  const title = onlyLineStableId ? 'Générer cette ligne ?' : `${whole} ?`

  return (
    <Overlay
      title={title}
      onClose={onClose}
      dismissible={!launching}
      footer={
        caveats ? (
          <button type="button" className="btn btn--primary" onClick={onClose}>
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
              onClick={run}
              disabled={
                !preview ||
                chosen.length === 0 ||
                preview.blockers.length > 0 ||
                launching ||
                (stale !== null && (!stale.canOverride || !override))
              }
              aria-busy={launching}
            >
              {launching ? 'Envoi…' : onlyLineStableId ? 'Générer cette ligne' : whole}
            </button>
          </>
        )
      }
    >
      {!preview && !loadError && (
        // The analysis takes several seconds (up to ~20 s on a large planning): a visible, announced wait,
        // with the launch button unavailable until it is over.
        <div role="status" aria-live="polite" className="fill-loading">
          <span className="fill-spinner" aria-hidden />
          <span>
            <strong>Analyse des lignes en cours…</strong>
            <span className="fill-loading__hint">
              Cela peut prendre jusqu’à une vingtaine de secondes. Rien n’est modifié pendant l’analyse.
            </span>
          </span>
        </div>
      )}
      {loadError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{loadError}</span>
        </p>
      )}
      {caveats && <SurgicalHubCaveats caveats={caveats} timeZone={timezone} />}

      {preview && !caveats && (
        <>
          <p className="fill-intro">
            Les gardes déjà attribuées ne sont jamais modifiées. Seules les gardes sans titulaire sont
            complétées, ligne par ligne, chaque groupe avec ses propres règles d’équité. Aucun e-mail n’est
            envoyé.
          </p>

          <ul className="fill-lines" aria-label="Lignes concernées">
            {lines.map((line) => {
              const writable = line.action === 'GENERATE' || line.action === 'COMPLETE'
              return (
                <li key={line.lineStableId} className="fill-line" data-action={line.action}>
                  <p className="fill-line__head">
                    <strong>{line.lineName}</strong>
                    {!writable && !line.followsSource && <span className="muted"> — inchangée</span>}
                  </p>
                  <p id={`fill-${line.lineStableId}`} className="fill-line__text">
                    {fillPlanText(line)}
                  </p>
                  {line.protectedAssignmentCount > 0 && (
                    <p className="fill-line__protected">
                      <Icon name="check" size={15} strokeWidth={2.4} />
                      {plural(
                        line.protectedAssignmentCount,
                        'garde attribuée protégée',
                        'gardes attribuées protégées',
                      )}
                    </p>
                  )}
                </li>
              )
            })}
          </ul>

          <ul className="preflight-facts" aria-label="Résumé">
            <li>
              {plural(protectedCount, 'affectation existante protégée', 'affectations existantes protégées')}{' '}
              (jamais modifiées)
            </li>
            {toComplete > 0 && (
              <li>
                {plural(toComplete, 'garde non pourvue à compléter', 'gardes non pourvues à compléter')}
              </li>
            )}
            {toGenerate.length > 0 && (
              <li>
                {plural(
                  toGenerate.length,
                  'ligne reçoit sa première génération',
                  'lignes reçoivent leur première génération',
                )}
              </li>
            )}
          </ul>

          {preview.blockers.length > 0 && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>Le moteur de génération n’est pas configuré : aucune ligne ne peut être complétée.</span>
            </p>
          )}

          {stale && (
            <div role="alert" className="alert alert--error sh-stale">
              <Icon name="alert" size={18} strokeWidth={2} />
              <div>
                <p>
                  Les congés SurgicalHub de ces personnes n’ont pas pu être actualisés et datent de plus de 24
                  heures (ou n’ont jamais été repris) :
                </p>
                <SurgicalHubPeople people={stale.participants} timeZone={timezone} />
                {stale.canOverride ? (
                  <label className="sh-stale__override">
                    <input
                      type="checkbox"
                      checked={override}
                      onChange={(event) => setOverride(event.target.checked)}
                    />
                    Je génère quand même avec leurs derniers congés connus (ce choix est enregistré).
                  </label>
                ) : (
                  <p>Seul le créateur du planning peut générer malgré tout.</p>
                )}
              </div>
            </div>
          )}

          {toGenerate.length > 0 && (
            <RestPolicyFields value={restPolicy} onChange={setRestPolicy} disabled={launching} />
          )}
        </>
      )}

      {launchError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{launchError}</span>
        </p>
      )}
    </Overlay>
  )
}

function fillErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    const body = err.body as { error?: string } | null
    if (body?.error === 'nothing_to_fill') {
      return 'Aucune des lignes choisies n’a de garde à générer ou à compléter : rien n’a été modifié.'
    }
    if (body?.error === 'job_in_progress') {
      return 'Un calcul est déjà en cours sur ce planning. Attendez sa fin.'
    }
    if (err.status === 403) {
      return 'Vous n’avez pas le droit de générer ce planning.'
    }
  }
  return 'L’opération n’a pas pu être lancée. Réessayez.'
}

/**
 * The report of a run, line by line (docs/decisions.md D222) — every count
 * measured by the server on the visible calendar before and after.
 */
export function FillResultBody({ outcome }: { outcome: NonNullable<PlanningJob['outcome']> }) {
  const lines = outcome.lines as FillLineResult[]
  return (
    <div className="fill-result">
      <p className={(outcome.modifiedExistingCount ?? 0) === 0 ? 'fill-result__safe' : 'alert alert--error'}>
        <strong>Affectations existantes modifiées : {outcome.modifiedExistingCount ?? 0}</strong>
        {' · '}
        {plural(outcome.keptExistingCount ?? 0, 'affectation conservée', 'affectations conservées')}
        {' · '}
        {plural(outcome.addedCount ?? 0, 'nouvelle garde attribuée', 'nouvelles gardes attribuées')}
      </p>
      <div className="fill-result__scroll">
        <table className="fill-table">
          <thead>
            <tr>
              <th scope="col">Ligne</th>
              <th scope="col">Gardes existantes conservées</th>
              <th scope="col">Nouvelles gardes attribuées</th>
              <th scope="col">Gardes toujours non pourvues</th>
              <th scope="col">Affectations existantes modifiées</th>
            </tr>
          </thead>
          <tbody>
            {lines.map((line) => (
              <tr key={line.lineStableId}>
                <th scope="row">
                  {line.lineName}
                  <span className="fill-table__status">{fillResultText(line)}</span>
                </th>
                <td className="tnum" data-label="Gardes existantes conservées">
                  {line.keptCount}
                </td>
                <td className="tnum" data-label="Nouvelles gardes attribuées">
                  {line.addedCount}
                </td>
                <td className="tnum" data-label="Gardes toujours non pourvues">
                  {line.remainingUncoveredCount ?? '—'}
                </td>
                <td className="tnum" data-label="Affectations existantes modifiées">
                  <strong>{line.modifiedCount}</strong>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
