import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
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
    expect(
      screen.getByText(/Une modification ne s’applique qu’aux gardes créées ensuite/),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled()
    // The one of a line says nothing of the others.
    expect(screen.queryByRole('list', { name: 'Ce que chaque ligne utilise' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Aide : Semaine type' })).not.toBeInTheDocument()
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

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByRole('group', { name: 'Jours de la semaine' })
    // The editor has its own (selection) "Annuler": close through the dialog's footer.
    fireEvent.click(
      within(dialog.querySelector<HTMLElement>('.overlay__footer')!).getByRole('button', { name: 'Annuler' }),
    )

    expect(onClose).toHaveBeenCalledTimes(1)
    expect(api.requests('PUT', '/api/planning-lines/line-1/week-structure')).toHaveLength(0)
  })

  it('keeps a stable layout: anchored to the top, scrollbar gutter reserved', async () => {
    stubApi({ 'GET /api/planning-lines/line-1/week-structure': () => EMPTY_STRUCTURE })
    render(<WeekStructureModal lineStableId="line-1" lineName="Ligne principale" onClose={vi.fn()} />)

    const dialog = await screen.findByRole('dialog')
    expect(dialog.parentElement).toHaveClass('overlay', 'overlay--modal', 'overlay--stable')
  })
})

describe('WeekStructureModal — the general one of a planning (docs/decisions.md D227)', () => {
  const TEMPLATE = '/api/plannings/plan-1/week-template'
  const LINES = [
    { stableId: 'l1', name: 'Seniors', kind: 'GENERAL', periods: [] },
    { stableId: 'l2', name: 'Juniors', kind: 'CUSTOM', periods: [] },
    { stableId: 'l3', name: 'Renfort', kind: 'INHERITED', periods: [] },
  ]
  const WEEKEND = {
    blocks: [{ id: 'A', name: 'Week-end', family: 'Week-end', days: ['VEN', 'SAM', 'DIM'] }],
    solo: ['LUN', 'MAR', 'MER', 'JEU'],
    soloFamily: 'Semaine',
    excluded: [],
  }

  it('reads the planning’s structure and says what each line uses, with a "?"', async () => {
    const api = stubApi({ [`GET ${TEMPLATE}`]: () => ({ ...WEEKEND, lines: LINES }) })
    render(<WeekStructureModal planningStableId="plan-1" lineName="du planning" onClose={vi.fn()} />)

    const dialog = await screen.findByRole('dialog', { name: 'Semaine type du planning' })
    expect(await within(dialog).findByText('Ven · Sam · Dim · 3 jours')).toBeInTheDocument()
    expect(api.requests('GET', TEMPLATE)).toHaveLength(1)

    const lines = within(dialog).getByRole('list', { name: 'Ce que chaque ligne utilise' })
    const row = (name: string) => within(lines).getByText(name).closest('li')
    expect(row('Seniors')).toHaveTextContent('Semaine type générale')
    expect(row('Juniors')).toHaveTextContent('Semaine type personnalisée : non concernée')
    expect(row('Renfort')).toHaveTextContent('Blocs hérités de sa ligne source : non concernée')
    expect(
      within(dialog).getByText(/Une modification ne s’applique qu’aux gardes créées ensuite/),
    ).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Aide : Semaine type' }))
    expect(within(dialog).getByRole('note')).toHaveTextContent(/personnalisée pour certaines lignes/)
  })

  it('saves to the planning, never to a line, then closes and tells the page', async () => {
    const api = stubApi({
      [`GET ${TEMPLATE}`]: () => ({ ...WEEKEND, lines: LINES }),
      [`PUT ${TEMPLATE}`]: () => ({ ...WEEKEND, lines: LINES, appliedTo: ['l1'] }),
    })
    const onClose = vi.fn()
    const onSaved = vi.fn()
    render(
      <WeekStructureModal
        planningStableId="plan-1"
        lineName="du planning"
        onClose={onClose}
        onSaved={onSaved}
      />,
    )

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('Ven · Sam · Dim · 3 jours')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Dissoudre' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1))
    expect(onSaved).toHaveBeenCalledTimes(1)
    expect(api.requests('PUT', TEMPLATE)).toHaveLength(1)
    expect(api.calls.some((call) => call.path.startsWith('/api/planning-lines/'))).toBe(false)
  })

  it('shows an error and never opens the editor when loading fails', async () => {
    stubApi({ [`GET ${TEMPLATE}`]: () => httpStatus(500) })
    render(<WeekStructureModal planningStableId="plan-1" lineName="du planning" onClose={vi.fn()} />)

    expect(await screen.findByRole('alert')).toHaveTextContent('Impossible de charger')
    expect(screen.queryByRole('group', { name: 'Jours de la semaine' })).not.toBeInTheDocument()
  })
})
