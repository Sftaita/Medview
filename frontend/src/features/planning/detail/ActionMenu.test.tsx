import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ActionMenu } from './ActionMenu'

const items = [
  { label: 'Paramètres de la ligne', icon: 'settings' as const, onSelect: vi.fn() },
  { label: 'Règles de génération', icon: 'check' as const, onSelect: vi.fn() },
  { label: 'Supprimer la ligne', icon: 'trash' as const, onSelect: vi.fn(), danger: true },
]

const original = document.elementFromPoint

/** What the browser shows at a point: the menu's own item, or something covering it. */
function shownAtLastItem(covered: boolean) {
  document.elementFromPoint = () =>
    covered ? document.body : screen.getByRole('menuitem', { name: 'Supprimer la ligne' })
}

afterEach(() => {
  cleanup()
  document.elementFromPoint = original
})

describe('ActionMenu (D140; opens upward when out of reach, D224 390 px check)', () => {
  it('opens downward when its last item is reachable', () => {
    shownAtLastItem(false)
    render(<ActionMenu label="Actions pour Fell/Ass" items={items} />)

    fireEvent.click(screen.getByRole('button', { name: 'Actions pour Fell/Ass' }))

    expect(screen.getByRole('menu')).not.toHaveClass('pd-menu--up')
  })

  it('opens upward when its last item is covered — the phone bottom bar, a clipping card, the end of the page', () => {
    shownAtLastItem(true)
    render(<ActionMenu label="Actions pour Fell/Ass" items={items} />)

    fireEvent.click(screen.getByRole('button', { name: 'Actions pour Fell/Ass' }))
    expect(screen.getByRole('menu')).toHaveClass('pd-menu--up')

    fireEvent.keyDown(window, { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    shownAtLastItem(false)
    fireEvent.click(screen.getByRole('button', { name: 'Actions pour Fell/Ass' }))
    expect(screen.getByRole('menu')).not.toHaveClass('pd-menu--up') // measured again at each opening
  })

  it('every item stays usable once opened upward', () => {
    shownAtLastItem(true)
    render(<ActionMenu label="Actions pour Fell/Ass" items={items} />)

    fireEvent.click(screen.getByRole('button', { name: 'Actions pour Fell/Ass' }))
    fireEvent.click(screen.getByRole('menuitem', { name: 'Supprimer la ligne' }))

    expect(items[2].onSelect).toHaveBeenCalledOnce()
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })
})
