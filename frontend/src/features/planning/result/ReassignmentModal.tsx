import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchReassignmentCandidates, reassignDuty, unassignDuty } from './api'
import { impactSummary } from './impacts'
import type { DependentImpact, ReassignmentCandidate, ReassignmentCandidatesView } from './types'

type Props = {
  planningStableId: string
  dutyStableId: string
  onClose: () => void
  /**
   * Something was actually saved (a replacement or a removal): the caller refreshes the calendar. `impacts`:
   * what it did to the reinforcements depending on this duty (docs/decisions.md D165), as the backend computed it.
   */
  onChanged: (impacts: DependentImpact[]) => void
}

type Done = ({ kind: 'replaced'; who: ReassignmentCandidate } | { kind: 'removed' }) & { impacts: DependentImpact[] }

/**
 * The assignment editor of the calendar (docs/decisions.md D131/D144):
 * replace the holder of a duty — or of its whole block, never one day of it —
 * or remove them without replacement ("Retirer l'affectation", which leaves
 * the duty deliberately NON ATTRIBUÉE).
 *
 * The candidate list only ever contains people of the duty's own line who
 * can really take it right now: an impossible candidate is absent, not
 * disabled. Nothing is persisted until "Remplacer" or the confirmed removal
 * succeeds; the server revalidates for real at that moment, the list is
 * never an authority.
 */
export function ReassignmentModal({ planningStableId, dutyStableId, onClose, onChanged }: Props) {
  const [view, setView] = useState<ReassignmentCandidatesView | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [selected, setSelected] = useState<ReassignmentCandidate | null>(null)
  const [confirmingRemoval, setConfirmingRemoval] = useState(false)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [done, setDone] = useState<Done | null>(null)
  // A ref, not state: two clicks in the same tick both read the state as "idle".
  const inFlight = useRef(false)

  useEffect(() => {
    let cancelled = false
    fetchReassignmentCandidates(planningStableId, dutyStableId)
      .then((value) => {
        if (!cancelled) setView(value)
      })
      .catch(() => {
        if (!cancelled) setLoadError('Impossible de charger les candidats pour cette garde.')
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId, dutyStableId])

  async function run(
    action: () => Promise<{ dependentImpacts?: DependentImpact[] }>,
    result: { kind: 'replaced'; who: ReassignmentCandidate } | { kind: 'removed' },
  ) {
    if (inFlight.current) {
      return
    }
    inFlight.current = true
    setSaving(true)
    setSaveError(null)
    try {
      const answer = await action()
      const impacts = answer.dependentImpacts ?? []
      setDone({ ...result, impacts })
      onChanged(impacts)
    } catch (err) {
      setSaveError(saveErrorMessage(err))
    } finally {
      inFlight.current = false
      setSaving(false)
    }
  }

  function replace() {
    if (null === selected || null === view) return
    void run(
      () =>
        reassignDuty(
          planningStableId,
          dutyStableId,
          selected.teamMemberStableId,
          view.currentTeamMemberStableId,
        ),
      { kind: 'replaced', who: selected },
    )
  }

  function remove() {
    if (null === view || null === view.currentTeamMemberStableId) return
    const holder = view.currentTeamMemberStableId
    void run(() => unassignDuty(planningStableId, dutyStableId, holder), { kind: 'removed' })
  }

  const isBlock = null !== view && view.blockDuties.length > 1
  // docs/decisions.md D167: a reinforcement nobody needs any more, still held — its removal is the only action.
  const superfluous = view?.demand?.state === 'NOT_REQUIRED_ASSIGNED'
  const title = done
    ? 'Modification enregistrée'
    : isBlock
      ? 'Modifier le bloc de garde'
      : 'Modifier la garde'

  return (
    <Overlay
      title={title}
      onClose={onClose}
      dismissible={!saving}
      footer={
        done ? (
          <button type="button" className="btn btn--primary" onClick={onClose} data-autofocus>
            Fermer
          </button>
        ) : confirmingRemoval ? (
          <>
            <button
              type="button"
              className="btn btn--secondary"
              onClick={() => setConfirmingRemoval(false)}
              disabled={saving}
            >
              Retour
            </button>
            <button
              type="button"
              className="btn btn--danger"
              onClick={remove}
              disabled={saving}
              aria-busy={saving}
            >
              {saving ? 'Retrait…' : 'Confirmer le retrait'}
            </button>
          </>
        ) : (
          <>
            <button type="button" className="btn btn--secondary" onClick={onClose} disabled={saving}>
              {view?.assignable === false ? 'Fermer' : 'Annuler'}
            </button>
            {/* docs/decisions.md D167: no new holder can be written — never a (disabled) "Remplacer" to wonder about. */}
            {view?.assignable !== false && (
              <button
                type="button"
                className="btn btn--primary"
                onClick={replace}
                disabled={null === selected || saving}
                aria-busy={saving}
              >
                {saving ? 'Enregistrement…' : 'Remplacer'}
              </button>
            )}
          </>
        )
      }
    >
      {!view && !loadError && (
        <p role="status" className="muted">
          Chargement des candidats…
        </p>
      )}
      {loadError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{loadError}</span>
        </p>
      )}

      {view && !done && (
        <>
          <BlockSummary view={view} />
          {view.demand && <DemandLine view={view} />}
          <p className="reassignment-current">
            Actuellement :{' '}
            {view.currentAssignee ? (
              <strong>{fullName(view.currentAssignee)}</strong>
            ) : (
              <strong className="cal-uncovered">⚠ Non attribué</strong>
            )}
          </p>

          {confirmingRemoval ? (
            <p className="alert alert--warning">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>
                {superfluous ? (
                  <>
                    {view.currentAssignee ? fullName(view.currentAssignee) : 'La personne'} sera retiré(e) de ce
                    renfort, qui n’est plus nécessaire selon le titulaire actuel de la ligne à renforcer. L’historique
                    des affectations est conservé.
                  </>
                ) : (
                  <>
                    {view.currentAssignee ? fullName(view.currentAssignee) : 'La personne'} sera retiré(e)
                    {isBlock ? ' de tout le bloc, qui restera ' : ' de cette garde, qui restera '}
                    <strong>{isBlock ? 'non attribué' : 'non attribuée'}</strong>{' '}
                    {isBlock ? 'jusqu’à ce que vous le complétiez.' : 'jusqu’à ce que vous la complétiez.'}
                  </>
                )}
              </span>
            </p>
          ) : (
            <>
              {view.assignable !== false && (
                <h3 className="reassignment-heading">
                  {view.currentAssignee ? 'Remplacer par' : 'Attribuer à'}
                </h3>
              )}
              {superfluous ? (
                <p className="alert alert--warning">
                  <Icon name="alert" size={18} strokeWidth={2} />
                  <span>
                    Ce renfort n’est plus nécessaire selon le titulaire actuel de la ligne à renforcer. Vous pouvez le
                    laisser en place ou le retirer : ce n’est pas une erreur.
                  </span>
                </p>
              ) : view.notAssignableReason === 'coverage_not_required' ? (
                <p className="muted">Aucun renfort n’est actuellement requis pour cette garde.</p>
              ) : view.notAssignableReason === 'coverage_undetermined' ? (
                <p className="muted">
                  Le besoin de renfort ne peut pas être évalué tant que la garde source n’est pas attribuée.
                </p>
              ) : view.candidates.length === 0 ? (
                <p className="muted">
                  Personne de cette ligne ne peut prendre {isBlock ? 'ce bloc' : 'cette garde'} actuellement.
                </p>
              ) : (
                <ul className="reassignment-candidates" aria-label="Candidats">
                  {view.candidates.map((candidate) => (
                    <li key={candidate.teamMemberStableId} className="reassignment-candidate">
                      <span className="reassignment-candidate__name">{fullName(candidate)}</span>
                      <button
                        type="button"
                        className="btn btn--sm btn--secondary"
                        aria-pressed={selected?.teamMemberStableId === candidate.teamMemberStableId}
                        onClick={() => setSelected(candidate)}
                      >
                        {selected?.teamMemberStableId === candidate.teamMemberStableId ? 'Choisi' : 'Choisir'}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              {view.currentAssignee && (
                <button
                  type="button"
                  className="pd-link reassignment-remove"
                  onClick={() => setConfirmingRemoval(true)}
                >
                  {superfluous ? 'Retirer le renfort' : 'Retirer l’affectation'}
                </button>
              )}
            </>
          )}
        </>
      )}

      {saveError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{saveError}</span>
        </p>
      )}
      {done?.kind === 'replaced' && (
        <p className="muted">
          {fullName(done.who)} est désormais attribué{isBlock ? ' à tout le bloc' : ''}.
        </p>
      )}
      {done?.kind === 'removed' && (
        <p className="muted">
          {superfluous
            ? 'Le renfort a été retiré.'
            : isBlock
              ? 'Le bloc est désormais non attribué.'
              : 'La garde est désormais non attribuée.'}
        </p>
      )}
      {done && <ImpactList impacts={done.impacts} />}
    </Overlay>
  )
}

/** docs/decisions.md D167: where this reinforcement stands, in plain words (from the backend's live state). */
function DemandLine({ view }: { view: ReassignmentCandidatesView }) {
  const text = {
    REQUIRED_ASSIGNED: 'Renfort requis',
    REQUIRED_UNASSIGNED: 'Renfort requis — non attribué',
    NOT_REQUIRED_UNASSIGNED: 'Aucun renfort requis',
    NOT_REQUIRED_ASSIGNED: 'Renfort non requis',
    UNDETERMINED: 'Renfort non évalué',
  }[view.demand!.state]
  return <p className="muted reassignment-demand">Renfort : {text}</p>
}

/** The consequences of the change on the reinforcements depending on it (D165) — changed ones only. */
export function ImpactList({ impacts }: { impacts: DependentImpact[] }) {
  const lines = impactSummary(impacts)
  if (lines.length === 0) return null
  return (
    <div className="reassignment-impacts" role="status">
      <p>
        <strong>Conséquence{lines.length > 1 ? 's' : ''} sur les renforts :</strong>
      </p>
      <ul>
        {lines.map((line) => (
          <li key={line}>{line}</li>
        ))}
      </ul>
    </div>
  )
}

function BlockSummary({ view }: { view: ReassignmentCandidatesView }) {
  if (view.blockDuties.length <= 1) {
    const duty = view.blockDuties[0]
    return (
      <p className="muted reassignment-block-summary">
        {duty.dutyTypeName} — {formatLocalDate(duty.date)}
      </p>
    )
  }

  return (
    <p className="muted reassignment-block-summary">
      {view.groupLabel ? `Bloc ${view.groupLabel}` : 'Bloc'} du {formatLocalDate(view.blockDuties[0].date)} au{' '}
      {formatLocalDate(view.blockDuties[view.blockDuties.length - 1].date)} — {view.blockDuties.length} gardes
      modifiées ensemble.
    </p>
  )
}

function fullName(person: { firstName: string; lastName: string }): string {
  return `${person.firstName} ${person.lastName}`
}

function formatLocalDate(date: string): string {
  return new Intl.DateTimeFormat('fr-BE', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
  }).format(new Date(`${date}T00:00:00Z`))
}

function saveErrorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    const code = (err.body as { error?: string } | null)?.error
    if (code === 'stale_reassignment' || code === 'already_uncovered') {
      return 'Le planning a changé depuis l’ouverture de cette fenêtre. Fermez et rouvrez cette garde pour réessayer.'
    }
    if (code === 'invalid_candidate') {
      const label = (err.body as { reasonLabel?: string } | null)?.reasonLabel
      return label
        ? `Cette attribution n’est plus possible : ${label}.`
        : 'Cette attribution n’est plus possible : ce candidat ne remplit plus les conditions.'
    }
    if (code === 'coverage_not_required') {
      return 'Aucun renfort n’est actuellement requis pour cette garde.'
    }
    if (code === 'coverage_undetermined') {
      return 'Le besoin de renfort ne peut pas être évalué tant que la garde source n’est pas attribuée.'
    }
  }
  return 'L’enregistrement a échoué.'
}
