import { useEffect, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { activateRuleSet, fetchRuleSetStatus } from './api'
import { formatDateTime } from './format'
import type { RuleSetStatus } from './types'

type Props = {
  lineStableId: string
  timezone: string
  /** Activation succeeded: the caller may want to refresh what depends on it. */
  onActivated?: () => void
}

/**
 * "Règles de génération" of a line (docs/decisions.md D137, D228), in the
 * settings of that line: how its duties are shared between its members. Still
 * an activation gate, never a settings form: no parameter of the rule set is read
 * by the engine (audited in PlanningRuleSetController), so none is shown; what
 * the engine really does is said in words.
 */
export function GenerationRulesPanel({ lineStableId, timezone, onActivated }: Props) {
  const [status, setStatus] = useState<RuleSetStatus | null>(null)
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
    <section className="cov-section">
      <h3>Règles de génération</h3>
      <span className="cov-help">Comment répartir les gardes entre les membres ?</span>
      <p className="cov-help">
        Les gardes sont réparties entre les membres de cette ligne seulement : la charge et chaque type de
        garde sont équilibrés séparément, en respectant les indisponibilités, les blocs et les gardes qu’un
        même membre tient sur d’autres lignes. Les règles de repos se choisissent au moment de générer.
      </p>
      {status?.active && (
        <p className="cov-help">
          <Icon name="check" size={15} strokeWidth={2.4} /> Actives
          {status.activatedAt && <> depuis le {formatDateTime(status.activatedAt, timezone)}</>}.
        </p>
      )}
      {status && !status.active && (
        <>
          <p className="alert alert--warning">
            <Icon name="alert" size={18} strokeWidth={2} />
            <span>Pas encore activées : les gardes de cette ligne ne peuvent pas être générées.</span>
          </p>
          <div>
            <button
              type="button"
              className="btn btn--primary btn--sm"
              onClick={() => void activate()}
              disabled={activating}
              aria-busy={activating}
            >
              {activating ? 'Activation…' : 'Activer'}
            </button>
          </div>
        </>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
    </section>
  )
}
