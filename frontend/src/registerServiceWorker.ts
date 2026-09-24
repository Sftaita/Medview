/**
 * Registers the PWA service worker (public/sw.js). Production only: in dev,
 * a cache-first worker on static assets would fight Vite's HMR.
 */
export function registerServiceWorker(
  enabled: boolean = import.meta.env.PROD,
  nav: Navigator = navigator,
  win: Window = window,
): boolean {
  if (!enabled || !('serviceWorker' in nav)) {
    return false
  }
  win.addEventListener('load', () => {
    nav.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
      // An unregistered worker only means no offline page; the app still works.
    })
  })
  return true
}
