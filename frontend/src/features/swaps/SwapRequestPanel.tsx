import { useRef, useState } from 'react'
import { Icon } from '../../components/Icon'
import { cancelSwapRequest, decideSwapProposal, proposeSwap, swapErrorMessage } from './api'
import {
  audienceLabel,
  eventLabel,
  formatWhen,
  personName,
  proposalStatus,
  requestStatus,
  unitLabel,
} from './labels'
import { ResponsibilityNotice } from './ResponsibilityNotice'
import { SwapStatusPill } from './SwapStatusPill'
import type { SwapProposal, SwapRequestDetail } from './types'

type Busy = { kind: 'accept' | 'refuse' | 'withdraw' | 'cancel' | 'propose'; id?: string } | null

/**
 * One swap request in full (docs/duty-swaps.md §9): what is offered, to whom, every proposal the viewer may see
 * with the actions they may take, and the chronology from the server's append-only journal. Every action waits
 * for the server and then shows what it answered — the calendar is never changed on the screen before MedVue has
 * recorded the swap.
 */
export function SwapRequestPanel({
  request: given,
  onChanged = () => {},
  readOnly = false,
}: {
  request: SwapRequestDetail
  onChanged?: (request: SwapRequestDetail) => void
  /** A manager's history view: what happened, never an action (no approval exists). */
  readOnly?: boolean
}) {
  const request: SwapRequestDetail = readOnly
    ? {
        ...given,
        actions: { cancel: false, propose: false },
        proposals: given.proposals.map((p) => ({
          ...p,
          actions: { accept: false, refuse: false, withdraw: false },
        })),
        proposableUnits: [],
      }
    : given
  const [busy, setBusy] = useState<Busy>(null)
  const [confirming, setConfirming] = useState<string | null>(null)
  const [confirmingCancel, setConfirmingCancel] = useState(false)
  const [proposedUnit, setProposedUnit] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const inFlight = useRef(false)

  async function run(
    next: Exclude<Busy, null>,
    action: () => Promise<SwapRequestDetail>,
    done?: (r: SwapRequestDetail) => string | null,
  ) {
    if (inFlight.current) return
    inFlight.current = true
    setBusy(next)
    setError(null)
    setSuccess(null)
    try {
      const updated = await action()
      setConfirming(null)
      setConfirmingCancel(false)
      setProposedUnit('')
      setSuccess(done ? done(updated) : null)
      onChanged(updated)
    } catch (e) {
      setError(swapErrorMessage(e))
    } finally {
      inFlight.current = false
      setBusy(null)
    }
  }

  const status = requestStatus(request)
  const isRequester = request.viewerRole === 'REQUESTER'
  const open = request.status === 'OPEN'

  return (
    <div className="swap-panel">
      <section className="swap-panel__head" aria-label="Demande">
        <div className="swap-panel__status">
          <SwapStatusPill {...status} />
          <span className="muted">Demandée le {formatWhen(request.createdAt)}</span>
        </div>
        <div className="swap-unit-card">
          <span className="swap-unit-card__label">
            {isRequester ? 'Votre garde' : `Garde de ${personName(request.requester)}`}
          </span>
          <strong>{unitLabel(request.offered)}</strong>
          <span className="muted">
            {request.planning.name} · {request.line.name}
          </span>
        </div>
        <dl className="swap-facts">
          <div>
            <dt>Type</dt>
            <dd>{request.kind === 'AGREED' ? 'Échange convenu' : 'Recherche d’un échange'}</dd>
          </div>
          <div>
            <dt>Adressée à</dt>
            <dd>{audienceLabel(request)}</dd>
          </div>
        </dl>
      </section>

      {open && isRequester && !readOnly && <ResponsibilityNotice compact />}
      {open && !isRequester && !readOnly && request.viewerRole !== 'MANAGER' && (
        <p className="alert alert--info">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>
            Rien n’est modifié dans le planning tant que l’échange n’a pas été accepté et confirmé dans MedVue
            : chacun reste responsable de sa garde initiale.
          </span>
        </p>
      )}
      {request.status === 'COMPLETED' && (
        <p className="alert alert--success">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>
            Échange enregistré dans le planning. Ces attributions sont officielles. Pensez à prévenir la
            direction de cet échange.
          </span>
        </p>
      )}

      {success && (
        <p role="status" className="alert alert--success">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>{success}</span>
        </p>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}

      <section aria-label="Propositions" className="swap-panel__section">
        <h3>{request.kind === 'AGREED' ? 'Échange proposé' : 'Propositions'}</h3>
        {request.proposals.length === 0 ? (
          <p className="muted">
            {request.viewerRole === 'REQUESTER'
              ? 'Aucune proposition pour l’instant.'
              : 'Aucune proposition de votre part.'}
          </p>
        ) : (
          <ul className="swap-proposals">
            {request.proposals.map((proposal) => (
              <ProposalItem
                key={proposal.stableId}
                request={request}
                proposal={proposal}
                busy={busy}
                confirming={confirming === proposal.stableId}
                onConfirm={() => setConfirming(proposal.stableId)}
                onCancelConfirm={() => setConfirming(null)}
                onAccept={() =>
                  run(
                    { kind: 'accept', id: proposal.stableId },
                    () => decideSwapProposal(proposal.stableId, 'accept'),
                    (r) =>
                      r.alreadyApplied
                        ? 'Cet échange était déjà enregistré.'
                        : 'Échange confirmé et enregistré. Un email de confirmation vous est envoyé à tous les deux.',
                  )
                }
                onRefuse={() =>
                  run(
                    { kind: 'refuse', id: proposal.stableId },
                    () => decideSwapProposal(proposal.stableId, 'refuse'),
                    () => 'Proposition refusée. Rien n’a changé dans le planning.',
                  )
                }
                onWithdraw={() =>
                  run(
                    { kind: 'withdraw', id: proposal.stableId },
                    () => decideSwapProposal(proposal.stableId, 'withdraw'),
                    () => 'Proposition retirée.',
                  )
                }
              />
            ))}
          </ul>
        )}
      </section>

      {request.actions.propose && (
        <section aria-label="Proposer une garde" className="swap-panel__section">
          <h3>Proposer une de vos gardes</h3>
          {request.proposableUnits.length === 0 ? (
            <p className="muted">Vous n’avez aucune garde à venir sur cette ligne à proposer.</p>
          ) : (
            <>
              <label className="field">
                <span className="field__label">Votre garde</span>
                <select
                  className="field__input"
                  value={proposedUnit}
                  disabled={busy !== null}
                  onChange={(e) => setProposedUnit(e.target.value)}
                >
                  <option value="">Choisir une garde…</option>
                  {request.proposableUnits.map((unit) => (
                    <option key={unit.dutyStableId} value={unit.dutyStableId}>
                      {unitLabel(unit)}
                    </option>
                  ))}
                </select>
              </label>
              <p className="muted swap-hint">
                Si {personName(request.requester)} accepte, vous assurerez {unitLabel(request.offered)} à la
                place de la garde choisie. Jusque-là, vous restez responsable de votre garde.
              </p>
              <button
                type="button"
                className="btn btn--primary"
                disabled={proposedUnit === '' || busy !== null}
                onClick={() =>
                  run(
                    { kind: 'propose' },
                    () => proposeSwap(request.stableId, proposedUnit),
                    () => 'Proposition envoyée. Rien ne change tant qu’elle n’est pas acceptée.',
                  )
                }
              >
                {busy?.kind === 'propose' ? 'Envoi…' : 'Proposer cette garde'}
              </button>
            </>
          )}
        </section>
      )}

      {request.actions.cancel && (
        <section aria-label="Annuler la demande" className="swap-panel__section">
          {confirmingCancel ? (
            <div className="swap-confirm">
              <p>
                Annuler cette demande ? Les propositions en attente seront clôturées. Votre garde reste la
                vôtre.
              </p>
              <div className="swap-actions">
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  onClick={() => setConfirmingCancel(false)}
                  disabled={busy !== null}
                >
                  Non
                </button>
                <button
                  type="button"
                  className="btn btn--danger btn--sm"
                  disabled={busy !== null}
                  onClick={() =>
                    run(
                      { kind: 'cancel' },
                      () => cancelSwapRequest(request.stableId),
                      () => 'Demande annulée.',
                    )
                  }
                >
                  {busy?.kind === 'cancel' ? 'Annulation…' : 'Oui, annuler la demande'}
                </button>
              </div>
            </div>
          ) : (
            <button
              type="button"
              className="btn btn--secondary btn--sm"
              onClick={() => setConfirmingCancel(true)}
              disabled={busy !== null}
            >
              Annuler la demande
            </button>
          )}
        </section>
      )}

      <section aria-label="Historique" className="swap-panel__section">
        <h3>Historique</h3>
        <ol className="swap-history">
          {request.history.map((event) => (
            <li key={event.stableId}>
              <span className="swap-history__when tnum">{formatWhen(event.occurredAt)}</span>
              <span>{eventLabel(event)}</span>
            </li>
          ))}
        </ol>
      </section>
    </div>
  )
}

function ProposalItem({
  request,
  proposal,
  busy,
  confirming,
  onConfirm,
  onCancelConfirm,
  onAccept,
  onRefuse,
  onWithdraw,
}: {
  request: SwapRequestDetail
  proposal: SwapProposal
  busy: Busy
  confirming: boolean
  onConfirm: () => void
  onCancelConfirm: () => void
  onAccept: () => void
  onRefuse: () => void
  onWithdraw: () => void
}) {
  const status = proposalStatus(proposal)
  const viewerIsCounterpart = request.viewerRole !== 'REQUESTER' && proposal.actions.accept
  const disabled = busy !== null
  // What the decider would take, and what the other one would take.
  const youTake = viewerIsCounterpart ? request.offered : proposal.counterpartUnit
  const otherName = viewerIsCounterpart ? personName(request.requester) : personName(proposal.counterpart)
  const otherTakes = viewerIsCounterpart ? proposal.counterpartUnit : request.offered

  return (
    <li className="swap-proposal" aria-label={`Proposition de ${personName(proposal.author)}`}>
      <div className="swap-proposal__head">
        <strong>{personName(proposal.counterpart)}</strong>
        <SwapStatusPill {...status} />
      </div>
      <p className="swap-proposal__line">
        {request.kind === 'AGREED' ? 'En échange de :' : 'Propose :'}{' '}
        <strong>{unitLabel(proposal.counterpartUnit)}</strong>
      </p>

      {confirming ? (
        <div className="swap-confirm">
          <p>
            <strong>Confirmer l’échange ?</strong>
          </p>
          <p>
            Vous assurerez <strong>{unitLabel(youTake)}</strong>. {otherName} assurera{' '}
            <strong>{unitLabel(otherTakes)}</strong>.
          </p>
          <p className="muted">
            MedVue vérifie les disponibilités et les repos au moment de l’enregistrement.
          </p>
          <div className="swap-actions">
            <button
              type="button"
              className="btn btn--ghost btn--sm"
              onClick={onCancelConfirm}
              disabled={disabled}
            >
              Retour
            </button>
            <button type="button" className="btn btn--primary btn--sm" onClick={onAccept} disabled={disabled}>
              {busy?.kind === 'accept' ? 'Enregistrement…' : 'Confirmer l’échange'}
            </button>
          </div>
        </div>
      ) : (
        (proposal.actions.accept || proposal.actions.refuse || proposal.actions.withdraw) && (
          <div className="swap-actions">
            {proposal.actions.refuse && (
              <button
                type="button"
                className="btn btn--secondary btn--sm"
                onClick={onRefuse}
                disabled={disabled}
              >
                {busy?.kind === 'refuse' && busy.id === proposal.stableId ? 'Refus…' : 'Refuser'}
              </button>
            )}
            {proposal.actions.withdraw && (
              <button
                type="button"
                className="btn btn--secondary btn--sm"
                onClick={onWithdraw}
                disabled={disabled}
              >
                {busy?.kind === 'withdraw' && busy.id === proposal.stableId
                  ? 'Retrait…'
                  : 'Retirer ma proposition'}
              </button>
            )}
            {proposal.actions.accept && (
              <button
                type="button"
                className="btn btn--primary btn--sm"
                onClick={onConfirm}
                disabled={disabled}
              >
                Accepter
              </button>
            )}
          </div>
        )
      )}
    </li>
  )
}
