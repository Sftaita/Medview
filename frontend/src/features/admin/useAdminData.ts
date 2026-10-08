import { useCallback, useEffect, useState } from 'react'

export type AdminData<T> = {
  data: T | null
  error: boolean
  loading: boolean
  reload: () => void
  setData: (data: T) => void
}

/**
 * Loads one admin resource and reloads it whenever `deps` change. The previous data stays on screen
 * while a new request is in flight (filters, pages), and a late answer for stale `deps` is dropped.
 */
export function useAdminData<T>(load: () => Promise<T>, deps: unknown[]): AdminData<T> {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState(false)
  const [loading, setLoading] = useState(true)
  const [nonce, setNonce] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    load()
      .then((result) => {
        if (cancelled) return
        setData(result)
        setError(false)
      })
      .catch(() => {
        if (!cancelled) setError(true)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
    // `load` is a new closure on every render; `deps` says when it really changed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, nonce])

  const reload = useCallback(() => setNonce((value) => value + 1), [])

  return { data, error, loading, reload, setData }
}
