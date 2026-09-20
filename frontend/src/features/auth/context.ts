import { createContext } from 'react'
import type { CurrentUser, JoinedTeam, RegisterInput } from './types'

export type AuthContextValue = {
  user: CurrentUser | null
  /** True only while the initial "am I already logged in?" check is running. */
  isLoading: boolean
  login: (email: string, password: string) => Promise<void>
  register: (input: RegisterInput) => Promise<JoinedTeam[]>
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)
