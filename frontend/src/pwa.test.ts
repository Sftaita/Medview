/// <reference types="node" />
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(import.meta.dirname, '..')
const publicDir = resolve(root, 'public')
const publicFile = (url: string) => resolve(publicDir, url.split('?')[0].replace(/^\//, ''))

type ManifestIcon = { src: string; sizes: string; purpose?: string }
const manifest = JSON.parse(readFileSync(resolve(publicDir, 'manifest.webmanifest'), 'utf8')) as {
  name: string
  start_url: string
  scope: string
  display: string
  icons: ManifestIcon[]
  shortcuts: { url: string; icons: ManifestIcon[] }[]
}
const indexHtml = readFileSync(resolve(root, 'index.html'), 'utf8')
const appRoutes = readFileSync(resolve(root, 'src/App.tsx'), 'utf8')

describe('PWA manifest', () => {
  it('is installable: name, standalone display, 192 and 512 icons, maskable variant', () => {
    expect(manifest.name).toBe('MedVue')
    expect(manifest.display).toBe('standalone')
    expect(manifest.scope).toBe('/')
    const sizes = manifest.icons.map((i) => i.sizes)
    expect(sizes).toContain('192x192')
    expect(sizes).toContain('512x512')
    expect(manifest.icons.some((i) => i.purpose === 'maskable')).toBe(true)
  })

  it('references only files that exist in public/', () => {
    const srcs = [...manifest.icons, ...manifest.shortcuts.flatMap((s) => s.icons)].map((i) => i.src)
    for (const src of srcs) {
      expect(existsSync(publicFile(src)), src).toBe(true)
    }
  })

  it('points every shortcut at a real application route', () => {
    for (const { url } of manifest.shortcuts) {
      const path = url.split('?')[0]
      expect(appRoutes, url).toContain(`path="${path}"`)
    }
  })
})

describe('index.html PWA head', () => {
  it('links the manifest, favicons and apple touch icon, all present in public/', () => {
    for (const href of ['/manifest.webmanifest', '/favicon.ico', '/favicon.svg', '/apple-touch-icon.png']) {
      expect(indexHtml).toContain(`href="${href}"`)
      expect(existsSync(publicFile(href)), href).toBe(true)
    }
  })

  it('declares iOS launch screens that all exist', () => {
    const splashes = [...indexHtml.matchAll(/rel="apple-touch-startup-image"\s+href="([^"]+)"/g)].map(
      (m) => m[1],
    )
    expect(splashes).toHaveLength(12)
    for (const href of splashes) {
      expect(existsSync(publicFile(href)), href).toBe(true)
    }
  })
})

describe('service worker', () => {
  const sw = readFileSync(resolve(publicDir, 'sw.js'), 'utf8')

  it('precaches only files that exist in public/', () => {
    const list = sw.match(/const PRECACHE = \[([\s\S]*?)\]/)?.[1] ?? ''
    const urls = [...list.matchAll(/'([^']+)'/g)].map((m) => m[1])
    expect(urls).toContain('/offline.html')
    for (const url of urls) {
      expect(existsSync(publicFile(url)), url).toBe(true)
    }
  })

  it('never intercepts API calls or non-GET requests', () => {
    expect(sw).toContain("if (req.method !== 'GET') return;")
    expect(sw).toContain("url.pathname.startsWith('/api/')")
  })
})
