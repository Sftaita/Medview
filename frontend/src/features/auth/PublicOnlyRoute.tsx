import type { ReactNode } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from './useAuth'

/**
 * Wraps /login and /register: UAT found that an already-authenticated
 * user could still open and submit those forms (no redirect away),
 * unlike most apps' expected behaviour. Mirrors ProtectedRoute's
 * isLoading handling so it doesn't flash the form for a moment while the
 * bootstrap session check is still in flight.
 */
export function PublicOnlyRoute({ children }: { children: ReactNode }) {
  const { user, isLoading } = useAuth()

  if (isLoading) {
    return <p>Chargement…</p>
  }

  if (user) {
    return <Navigate to="/" replace />
  }

  return <>{children}</>
}
