const API_BASE_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

export type HealthStatus = {
  status: 'ok' | 'error'
  database: 'ok' | 'error'
}

export async function fetchHealth(): Promise<HealthStatus> {
  const response = await fetch(`${API_BASE_URL}/api/health`)
  if (!response.ok) {
    throw new Error(`Health check failed with status ${response.status}`)
  }
  return response.json()
}
