import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { status as httpStatus, stubApi } from '../../testUtils/stubApi'
import { WeekStructureModal } from './WeekStructureModal'

beforeEach(() => localStorage.setItem('medvue.auth.token', 'jwt'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

const EMPTY_STRUCTURE = {
  blocks: [],
  solo: ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
  soloFamily: '',
  excluded: [],
}

describe('WeekStructureModal', () => {
  it('loads the current structure and shows the non-optimistic note', async () => {
    stubApi({ 'GET /api/planning-lines/line-1/week-structure': () => EMPTY_STRUCTURE })
    render(<WeekStructureModal lineStableId="line-1" lineName="Ligne principale" onClose={vi.fn()} />)

    expect(await screen.findByRole('dialog', { name: 'Semaine type — Ligne principale' })).toBeInTheDocument()
    expect(screen.getByText(/ne s’applique qu’aux prochaines générations/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled()
  })

  it('shows an error and never opens the editor when loading fails', async () => {
    stubApi({ 'GET /api/planning-lines/line-1/week-structure': () => httpStatus(500) })
    render(<WeekStructureModal lineStableId="line-1" lineName="Ligne principale" onClose={vi.fn()} />)

    expect(await screen.findByRole('alert')).toHaveTextContent('Impossible de charger')
    expect(screen.queryByRole('group', { name: 'Jours de la semaine' })).not.toBeInTheDocument()
  })

  it('keeps the dialog open and shows an error when saving fails', async () => {
    stubApi({
      'GET /api/planning-lines/line-1/week-structure': () => EMPTY_STRUCTURE,
      'PUT /api/planning-lines/line-1/week-structure': () => httpStatus(422),
    })
    const onClose = vi.fn()
    render(<WeekStructureModal lineStableId="line-1" lineName="Ligne principale" onClose={onClose} />)

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByRole('group', { name: 'Jours de la semaine' })
    fireEvent.click(within(dialog).getByRole('button', { name: /Lundi/ }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Pas de garde' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Impossible d’enregistrer')
    expect(onClose).not.toHaveBeenCalled()
  })

  it('cancels without saving anything', async () => {
    const api = stubApi({ 'GET /api/planning-lines/line-1/week-structure': () => EMPTY_STRUCTURE })
    const onClose = vi.fn()
    render(<WeekStructureModal lineStableId="line-1" lineName="Ligne principale" onClose={onClose} />)

    await screen.findByRole('dialog')
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))

    expect(onClose).toHaveBeenCalledTimes(1)
    expect(api.requests('PUT', '/api/planning-lines/line-1/week-structure')).toHaveLength(0)
  })
})
