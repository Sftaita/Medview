import { apiFetch } from '../../lib/apiClient'
import type { MyDuty } from './types'

export function fetchMyDuties(): Promise<MyDuty[]> {
  return apiFetch<{ duties: MyDuty[] }>('/api/me/duties').then((body) => body.duties)
}
