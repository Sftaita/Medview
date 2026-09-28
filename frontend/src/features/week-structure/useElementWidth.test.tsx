import { act, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useElementWidth } from './useElementWidth'

type Callback = (entries: Partial<ResizeObserverEntry>[]) => void

function Probe({ onWidth }: { onWidth: (w: number) => void }) {
  const [ref, width] = useElementWidth<HTMLDivElement>()
  onWidth(width)
  return <div ref={ref} />
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('useElementWidth', () => {
  it('mesure toujours la boîte de bordure, au montage comme au redimensionnement', () => {
    let callback: Callback = () => {}
    vi.stubGlobal(
      'ResizeObserver',
      class {
        constructor(cb: Callback) {
          callback = cb
        }
        observe() {}
        disconnect() {}
      },
    )
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: 528 } as DOMRect)
    const widths: number[] = []
    render(<Probe onWidth={(w) => widths.push(w)} />)
    expect(widths.at(-1)).toBe(528)

    // Jamais la boîte de contenu (padding exclu) : le palier changerait au
    // seul changement de padding, lui-même fixé par le palier.
    act(() =>
      callback([
        {
          borderBoxSize: [{ inlineSize: 509, blockSize: 0 }],
          contentRect: { width: 471 } as DOMRectReadOnly,
        },
      ]),
    )
    expect(widths.at(-1)).toBe(509)
  })
})
