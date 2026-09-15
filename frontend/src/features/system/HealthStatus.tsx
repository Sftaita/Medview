import { useEffect, useState } from 'react'
import { fetchHealth, type HealthStatus as HealthStatusType } from '../../lib/apiClient'

type State =
  | { kind: 'loading' }
  | { kind: 'success'; health: HealthStatusType }
  | { kind: 'error'; message: string }

export function HealthStatus() {
  const [state, setState] = useState<State>({ kind: 'loading' })

  useEffect(() => {
    let cancelled = false

    fetchHealth()
      .then((health) => {
        if (!cancelled) setState({ kind: 'success', health })
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setState({
            kind: 'error',
            message: error instanceof Error ? error.message : 'Unknown error',
          })
        }
      })

    return () => {
      cancelled = true
    }
  }, [])

  if (state.kind === 'loading') {
    return <span className="health-status health-status--loading">API : vérification…</span>
  }

  if (state.kind === 'error') {
    return (
      <span className="health-status health-status--error" title={state.message}>
        API : injoignable
      </span>
    )
  }

  const isHealthy = state.health.status === 'ok' && state.health.database === 'ok'

  return (
    <span className={`health-status ${isHealthy ? 'health-status--ok' : 'health-status--error'}`}>
      API : {state.health.status} · DB : {state.health.database}
    </span>
  )
}
