import { useEffect, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { activateRuleSet, fetchRuleSetStatus } from './api'
import { formatDateTime } from './format'
import type { RuleSetStatus } from './types'

type Props = {
  lineStableId: string
  lineName: string
  timezone: string
  onClose: () => void
  /** Activation succeeded: the caller may want to refresh the preflight it depends on. */
  onActivated?: () => void
}

/**
 * "Paramètres de génération" (docs/decisions.md D137) — a pure activation
 * gate, never a settings form: every field of `PlanningRuleSetConfiguration`
 * is unread by the real generation engine today (audited in
 * `PlanningRuleSetController`'s own docblock), so exposing them would be
 * exactly the "expert settings only because they exist in a DTO" this lot's
 * spec forbids. DRAFT/ACTIVE/RETIRED, version numbers and stableIds never
 * appear here — only whether generation rules are active for this line's
 * team, and a single "Activer" action when they are not.
 */
export function RuleSetModal({ lineStableId, lineName, timezone, onClose, onActivated }: Props) {
  const [status, setStatus] = useState<RuleSetStatus | null>(null)
  const [loading, setLoading] = useState(true)
  const [activating, setActivating] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    fetchRuleSetStatus(lineStableId)
      .then((value) => {
        if (!cancelled) setStatus(value)
      })
      .catch(() => {
        if (!cancelled) setError('Impossible de charger les règles de génération.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [lineStableId])

  async function activate() {
    setActivating(true)
    setError(null)
    try {
      setStatus(await activateRuleSet(lineStableId))
      onActivated?.()
    } catch {
      setError('Impossible d’activer les règles de génération.')
    } finally {
      setActivating(false)
    }
  }

  return (
    <Overlay
      title={`Règles de génération — ${lineName}`}
      onClose={onClose}
      dismissible={!activating}
      footer={
        <button type="button" className="btn btn--secondary" onClick={onClose}>
          Fermer
        </button>
      }
    >
      {loading && (
        <p role="status" className="muted">
          Chargement…
        </p>
      )}

      {!loading && status && (
        <>
          {status.active ? (
            <p className="alert alert--success">
              <Icon name="check" size={18} strokeWidth={2} />
              <span>
                Des règles de génération sont actives pour cette ligne
                {status.activatedAt && <> depuis le {formatDateTime(status.activatedAt, timezone)}</>}.
              </span>
            </p>
          ) : (
            <>
              <p className="alert alert--warning">
                <Icon name="alert" size={18} strokeWidth={2} />
                <span>
                  Aucune règle de génération n’est active pour cette ligne : la génération reste bloquée tant
                  qu’aucune n’est activée.
                </span>
              </p>
              <p className="muted">
                L’activation ne configure aucun paramètre particulier — elle débloque simplement la
                génération. Les règles de repos (repos légal, repos minimum d’équipe) se choisissent au moment
                de générer.
              </p>
              <button
                type="button"
                className="btn btn--primary"
                onClick={() => void activate()}
                disabled={activating}
                aria-busy={activating}
              >
                {activating ? 'Activation…' : 'Activer'}
              </button>
            </>
          )}
        </>
      )}

      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
    </Overlay>
  )
}
