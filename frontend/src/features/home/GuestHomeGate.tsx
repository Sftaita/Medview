import type { ReactNode } from 'react'
import { useLocation } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { HomePage } from './HomePage'

/**
 * "/" is the dashboard for a signed-in user and the public homepage for
 * everyone else. Every other protected route still sends an anonymous
 * visitor to /login (ProtectedRoute, which this gate wraps).
 */
export function GuestHomeGate({ children }: { children: ReactNode }) {
  const { user, isLoading } = useAuth()
  const { pathname } = useLocation()

  if (!isLoading && !user && pathname === '/') {
    return <HomePage />
  }

  return <>{children}</>
}
