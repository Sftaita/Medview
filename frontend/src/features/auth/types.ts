export type CurrentUser = {
  id: number
  email: string
  firstName: string
  lastName: string
  active: boolean
  createdAt: string
  updatedAt: string
}

export type RegisterInput = {
  email: string
  plainPassword: string
  firstName: string
  lastName: string
}
