import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { clearStoredToken, getStoredToken, setStoredToken, UNAUTHORIZED_EVENT } from '../../lib/apiClient'
import { fetchMe, login as loginRequest, register as registerRequest } from './api'
import { AuthContext } from './context'
import type { CurrentUser, RegisterInput } from './types'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<CurrentUser | null>(null)
  const [isLoading, setIsLoading] = useState(() => getStoredToken() !== null)

  useEffect(() => {
    if (!getStoredToken()) {
      return
    }

    fetchMe()
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

  const logout = useCallback(() => {
    clearStoredToken()
    setUser(null)
  }, [])

  return (
    <AuthContext.Provider value={{ user, isLoading, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  )
}
