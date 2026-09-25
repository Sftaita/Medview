import { useEffect, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { fetchReassignmentCandidates, reassignDuty } from './api'
import type { ReassignmentCandidate, ReassignmentCandidatesView } from './types'

type Props = {
  planningStableId: string
  dutyStableId: string
  onClose: () => void
  /** A reassignment was actually saved: the caller refreshes calendar + stats. */
  onReassigned: () => void
}

/**
 * "Modifier la garde" / "Modifier le bloc de garde" (docs/decisions.md
 * D131). Candidates are the real, live pool — every one of them stays
 * visible, disabled ones show their real reason, never simply hidden
 * (§9 of the spec). Picking a candidate only sets local state: nothing is
 * persisted until "Enregistrer la modification" succeeds (§16) — closing,
 * pressing Escape or "Annuler" discards the choice with no side effect.
 * The server revalidates for real at save time (§17): a candidate that
 * looked selectable when the modal opened can still be refused.
 */
export function ReassignmentModal({ planningStableId, dutyStableId, onClose, onReassigned }: Props) {
  const [view, setView] = useState<ReassignmentCandidatesView | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [selected, setSelected] = useState<ReassignmentCandidate | null>(null)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  // A ref, not state: two clicks on "Enregistrer" in the same tick both read the state as "idle".
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

  async function save() {
    if (inFlight.current || null === selected || null === view) {
      return
    }
    inFlight.current = true
    setSaving(true)
    setSaveError(null)
    try {
      await reassignDuty(
        planningStableId,
        dutyStableId,
        selected.teamMemberStableId,
        view.currentTeamMemberStableId,
      )
      setSaved(true)
      onReassigned()
    } catch (err) {
      setSaveError(saveErrorMessage(err))
    } finally {
      inFlight.current = false
      setSaving(false)
    }
  }

  const isBlock = null !== view && view.blockDuties.length > 1
  const title = saved
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
        saved ? (
          <button type="button" className="btn btn--primary" onClick={onClose} data-autofocus>
            Fermer
          </button>
        ) : (
          <>
            <button type="button" className="btn btn--secondary" onClick={onClose} disabled={saving}>
              Annuler
            </button>
            <button
              type="button"
              className="btn btn--primary"
              onClick={save}
              disabled={null === selected || saving}
              aria-busy={saving}
            >
              {saving ? 'Enregistrement…' : 'Enregistrer la modification'}
            </button>
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

      {view && !saved && (
        <>
          <BlockSummary view={view} />
          {selected && (
            <p className="reassignment-recap">
              Nouvelle attribution : <strong>{fullName(selected)}</strong>
            </p>
          )}
          <ul className="reassignment-candidates" aria-label="Candidats">
            {view.candidates.map((candidate) => (
              <li key={candidate.teamMemberStableId} className="reassignment-candidate">
                <div>
                  <span className="reassignment-candidate__name">{fullName(candidate)}</span>
                  {candidate.isCurrent && (
                    <span className="muted reassignment-candidate__note"> — actuellement attribué</span>
                  )}
                  {!candidate.selectable && candidate.blockingReasons.length > 0 && (
                    <span className="muted reassignment-candidate__note">
                      {' '}
                      — {candidate.blockingReasons.join(', ')}
                    </span>
                  )}
                </div>
                <button
                  type="button"
                  className="btn btn--sm btn--secondary"
                  disabled={!candidate.selectable}
                  aria-pressed={selected?.teamMemberStableId === candidate.teamMemberStableId}
                  onClick={() => setSelected(candidate)}
                >
                  {selected?.teamMemberStableId === candidate.teamMemberStableId ? 'Choisi' : 'Choisir'}
                </button>
              </li>
            ))}
          </ul>
        </>
      )}

      {saveError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{saveError}</span>
        </p>
      )}
      {saved && selected && (
        <p className="muted">
          {fullName(selected)} est désormais attribué{isBlock ? ' à tout le bloc' : ''}.
        </p>
      )}
    </Overlay>
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
      Bloc du {formatLocalDate(view.blockDuties[0].date)} au{' '}
      {formatLocalDate(view.blockDuties[view.blockDuties.length - 1].date)} — {view.blockDuties.length} gardes
      réattribuées ensemble.
    </p>
  )
}

function fullName(candidate: ReassignmentCandidate): string {
  return `${candidate.firstName} ${candidate.lastName}`
}

function formatLocalDate(date: string): string {
  return new Intl.DateTimeFormat('fr-BE', { weekday: 'long', day: 'numeric', month: 'long' }).format(
    new Date(`${date}T00:00:00Z`),
  )
}

function saveErrorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    const code = (err.body as { error?: string } | null)?.error
    if (code === 'stale_reassignment') {
      return 'Le planning a changé depuis l’ouverture de cette fenêtre. Fermez et rouvrez cette garde pour réessayer.'
    }
    if (code === 'invalid_candidate') {
      return 'Cette attribution n’est plus possible : ce candidat ne remplit plus les conditions.'
    }
  }
  return 'L’enregistrement a échoué.'
}
