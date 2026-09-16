/** Renders a Retry-After duration in seconds as a short French phrase. */
export function formatWaitTime(seconds: number): string {
  if (seconds < 60) {
    return `${seconds} seconde${seconds > 1 ? 's' : ''}`
  }
  const minutes = Math.ceil(seconds / 60)
  return `${minutes} minute${minutes > 1 ? 's' : ''}`
}
