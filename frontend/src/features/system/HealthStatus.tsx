import { useEffect, useState } from 'react'
import { fetchHealth, type HealthStatus as HealthStatusType } from '../../lib/apiClient'

type State =
  { kind: 'loading' } | { kind: 'success'; health: HealthStatusType } | { kind: 'error'; message: string }

type Props = {
  /** Light text, for use on the dark green surface. */
  inverse?: boolean
}

export function HealthStatus({ inverse = false }: Props) {
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

  const modifier = inverse ? ' health--inverse' : ''

  if (state.kind === 'loading') {
    return <span className={`health health--loading${modifier}`}>Vérification du service…</span>
  }

  if (state.kind === 'error') {
    return (
      <span className={`health health--error${modifier}`} title={state.message}>
        Service injoignable
      </span>
    )
  }

  const isHealthy = state.health.status === 'ok' && state.health.database === 'ok'

  return (
    <span
      className={`health ${isHealthy ? 'health--ok' : 'health--error'}${modifier}`}
      title={`API : ${state.health.status} · DB : ${state.health.database}`}
    >
      {isHealthy ? 'Service opérationnel' : 'Service dégradé'}
    </span>
  )
}
