import { describe, expect, it, vi } from 'vitest'
import { registerServiceWorker } from './registerServiceWorker'

function fakes(withServiceWorker = true) {
  const register = vi.fn().mockResolvedValue({})
  const nav = (withServiceWorker ? { serviceWorker: { register } } : {}) as unknown as Navigator
  const listeners: Record<string, () => void> = {}
  const win = {
    addEventListener: (type: string, cb: () => void) => {
      listeners[type] = cb
    },
  } as unknown as Window
  return { register, nav, win, listeners }
}

describe('registerServiceWorker', () => {
  it('registers /sw.js at root scope once the page has loaded', () => {
    const { register, nav, win, listeners } = fakes()
    expect(registerServiceWorker(true, nav, win)).toBe(true)
    expect(register).not.toHaveBeenCalled()
    listeners.load()
    expect(register).toHaveBeenCalledWith('/sw.js', { scope: '/' })
  })

  it('does nothing outside production builds', () => {
    const { register, nav, win, listeners } = fakes()
    expect(registerServiceWorker(false, nav, win)).toBe(false)
    expect(listeners.load).toBeUndefined()
    expect(register).not.toHaveBeenCalled()
  })

  it('does nothing when the browser has no service worker support', () => {
    const { nav, win, listeners } = fakes(false)
    expect(registerServiceWorker(true, nav, win)).toBe(false)
    expect(listeners.load).toBeUndefined()
  })

  it('swallows a registration failure', async () => {
    const { register, nav, win, listeners } = fakes()
    register.mockRejectedValueOnce(new Error('blocked'))
    registerServiceWorker(true, nav, win)
    expect(() => listeners.load()).not.toThrow()
    await Promise.resolve()
  })
})
