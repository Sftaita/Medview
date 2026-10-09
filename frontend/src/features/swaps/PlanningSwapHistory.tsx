import { useEffect, useState } from 'react'
import { Icon } from '../../components/Icon'
import { fetchPlanningSwaps, swapErrorMessage } from './api'
import { personName, requestStatus, unitLabel } from './labels'
import { SwapRequestPanel } from './SwapRequestPanel'
import { SwapStatusPill } from './SwapStatusPill'
import './swaps.css'
import type { SwapRequestDetail } from './types'

/**
 * A planning's swap history for its managers (docs/duty-swaps.md §5): every request, its proposals and its
 * chronology, read-only. Members conclude swaps between themselves; there is nothing here to approve.
 */
export function PlanningSwapHistory({ planningStableId }: { planningStableId: string }) {
  const [requests, setRequests] = useState<SwapRequestDetail[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [open, setOpen] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    fetchPlanningSwaps(planningStableId)
      .then((value) => {
        if (!cancelled) setRequests(value)
      })
      .catch((e: unknown) => {
        if (!cancelled) setError(swapErrorMessage(e, 'Impossible de charger l’historique des échanges.'))
      })
    return () => {
      cancelled = true
    }
  }, [planningStableId])

  if (error) {
    return (
      <p role="alert" className="alert alert--error">
        <Icon name="alert" size={18} strokeWidth={2} />
        <span>{error}</span>
      </p>
    )
  }
  if (requests === null) {
    return (
      <p role="status" className="muted">
        Chargement…
      </p>
    )
  }

  return (
    <section aria-label="Historique des échanges" className="pd-card swap-history-card">
      <p className="muted">
        Les membres concluent leurs échanges entre eux : aucune validation n’est attendue de votre part.
        Chaque échange confirmé apparaît dans le calendrier comme une affectation « échange ».
      </p>
      {requests.length === 0 ? (
        <p className="muted">Aucun échange demandé sur ce planning.</p>
      ) : (
        <ul className="swap-proposals">
          {requests.map((request) => {
            const expanded = open === request.stableId
            return (
              <li key={request.stableId} className="swap-proposal">
                <button
                  type="button"
                  className="swap-row swap-history-card__toggle"
                  aria-expanded={expanded}
                  onClick={() => setOpen(expanded ? null : request.stableId)}
                >
                  <span className="db-row-text">
                    <strong>{unitLabel(request.offered)}</strong>
                    <span className="db-muted">
                      {personName(request.requester)} · {request.line.name}
                    </span>
                  </span>
                  <SwapStatusPill {...requestStatus(request)} />
                </button>
                {expanded && <SwapRequestPanel request={request} readOnly />}
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}
