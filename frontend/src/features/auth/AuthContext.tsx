import { useCallback, useEffect, useState, type ReactNode } from 'react'
import {
  clearStoredToken,
  refreshAccessToken,
  setStoredToken,
  TOKEN_STORAGE_KEY,
  UNAUTHORIZED_EVENT,
} from '../../lib/apiClient'
import { fetchMe, login as loginRequest, logout as logoutRequest, register as registerRequest } from './api'
import { AuthContext } from './context'
import type { CurrentUser, RegisterInput } from './types'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<CurrentUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    // The refresh cookie (HttpOnly, never visible to this code) is the
    // real source of truth for "is there a session", not localStorage —
    // a fresh tab has no access token yet but may still have a valid
    // cookie from a previous visit.
    refreshAccessToken()
      .then(() => fetchMe())
      .then(setUser)
      .catch(() => {
        clearStoredToken()
        setUser(null)
      })
      .finally(() => setIsLoading(false))
  }, [])

  useEffect(() => {
    const handleUnauthorized = () => setUser(null)
    window.addEventListener(UNAUTHORIZED_EVENT, handleUnauthorized)
    return () => window.removeEventListener(UNAUTHORIZED_EVENT, handleUnauthorized)
  }, [])

  useEffect(() => {
    // UAT found that logging out in one tab left other open tabs showing
    // stale "logged in" UI until their next reload/API call. The `storage`
    // event fires in every *other* same-origin tab (never the one that
    // made the change) whenever localStorage changes, so this catches a
    // logout-elsewhere as soon as it happens instead of only reactively.
    function handleStorageChange(event: StorageEvent) {
      if (event.key === TOKEN_STORAGE_KEY && event.newValue === null) {
        setUser(null)
      }
    }
    window.addEventListener('storage', handleStorageChange)
    return () => window.removeEventListener('storage', handleStorageChange)
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const { token } = await loginRequest(email, password)
    setStoredToken(token)
    setUser(await fetchMe())
  }, [])

  const register = useCallback(
    async (input: RegisterInput) => {
      await registerRequest(input)
      await login(input.email, input.plainPassword)
    },
    [login],
  )

  const logout = useCallback(async () => {
    try {
      await logoutRequest()
    } catch {
      // Best-effort: even if the network call fails, clear local state so
      // the UI reflects "logged out" immediately.
    }
    clearStoredToken()
    setUser(null)
  }, [])

  return (
    <AuthContext.Provider value={{ user, isLoading, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  )
}
