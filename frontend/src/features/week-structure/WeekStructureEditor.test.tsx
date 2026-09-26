import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { WeekStructureEditor } from './WeekStructureEditor'
import { allSolo, createBlock, preset } from './weeklyStructure'
import type { WeekStructure, WeekStructurePayload } from './weeklyStructure'

function Harness({
  initial,
  onPayload,
}: {
  initial: WeekStructure
  onPayload: (p: WeekStructurePayload) => void
}) {
  const [value, setValue] = useState(initial)
  return (
    <WeekStructureEditor
      value={value}
      onChange={(next, payload) => {
        setValue(next)
        onPayload(payload)
      }}
    />
  )
}

const day = (name: string) => screen.getByRole('button', { name: new RegExp(`^${name}`) })

describe('WeekStructureEditor', () => {
  it('affiche le résumé et une tuile par jour', () => {
    render(<WeekStructureEditor value={preset('vsd')} onChange={() => {}} />)
    expect(screen.getByText('5 unités à attribuer · 7 jours de garde')).toBeInTheDocument()
    expect(
      screen.getByRole('group', { name: 'Jours de la semaine' }).querySelectorAll('button'),
    ).toHaveLength(7)
  })

  it('crée un bloc vendredi + dimanche et remonte le payload', () => {
    const onPayload = vi.fn()
    render(<Harness initial={allSolo()} onPayload={onPayload} />)

    fireEvent.click(day('Vendredi'))
    expect(screen.getByRole('button', { name: 'Créer un bloc' })).toBeDisabled()
    fireEvent.click(day('Dimanche'))
    expect(day('Vendredi')).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(screen.getByRole('button', { name: 'Créer un bloc' }))

    expect(onPayload).toHaveBeenLastCalledWith({
      blocks: [{ id: 'A', name: 'Ven · Dim', family: '', days: ['VEN', 'DIM'] }],
      solo: ['LUN', 'MAR', 'MER', 'JEU', 'SAM'],
      soloFamily: '',
      excluded: [],
    })
    expect(screen.getByText('Ven · Dim · 2 jours · non consécutifs')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Vendredi, bloc A (Ven · Dim)' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Samedi, garde isolée' })).toBeInTheDocument()
    expect(day('Vendredi')).toHaveAttribute('aria-pressed', 'false')
  })

  it('exclut un jour et affiche le rappel', () => {
    const onPayload = vi.fn()
    render(<Harness initial={allSolo()} onPayload={onPayload} />)
    fireEvent.click(day('Dimanche'))
    fireEvent.click(screen.getByRole('button', { name: 'Pas de garde' }))
    expect(onPayload.mock.calls.at(-1)?.[0].excluded).toEqual(['DIM'])
    expect(screen.getByText(/aucune garde ne sera créée/)).toBeInTheDocument()
  })

  it('dissout un bloc et renomme un bloc', () => {
    const onPayload = vi.fn()
    render(<Harness initial={preset('vsd')} onPayload={onPayload} />)
    fireEvent.change(screen.getByLabelText('Nom du bloc A'), { target: { value: 'WE' } })
    expect(onPayload.mock.calls.at(-1)?.[0].blocks[0].name).toBe('WE')
    fireEvent.click(screen.getByRole('button', { name: 'Dissoudre' }))
    expect(onPayload.mock.calls.at(-1)?.[0].blocks).toEqual([])
  })

  it('annule la sélection sans rien modifier', () => {
    const onChange = vi.fn()
    render(<WeekStructureEditor value={allSolo()} onChange={onChange} />)
    fireEvent.click(day('Lundi'))
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))
    expect(onChange).not.toHaveBeenCalled()
    expect(day('Lundi')).toHaveAttribute('aria-pressed', 'false')
  })

  it('en lecture seule, ni sélection ni action', () => {
    render(<WeekStructureEditor value={preset('vsd')} onChange={() => {}} readOnly />)
    expect(day('Lundi')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Dissoudre' })).not.toBeInTheDocument()
    expect(screen.getByLabelText('Nom du bloc A')).toBeDisabled()
  })

  it('classe un bloc et les jours isolés dans une famille d’équité', () => {
    const onPayload = vi.fn()
    // allSolo() starts every family empty ('') — a real, detectable change,
    // unlike preset('vsd') whose block/soloFamily already hold a value.
    render(<Harness initial={createBlock(allSolo(), [4, 5, 6])} onPayload={onPayload} />)

    fireEvent.change(screen.getByLabelText("Famille d'équité du bloc A"), {
      target: { value: 'Week-end' },
    })
    expect(onPayload.mock.calls.at(-1)?.[0].blocks[0].family).toBe('Week-end')

    fireEvent.change(screen.getByLabelText("Famille d'équité des gardes isolées"), {
      target: { value: 'Semaine' },
    })
    expect(onPayload.mock.calls.at(-1)?.[0].soloFamily).toBe('Semaine')
  })

  it('en lecture seule, les champs de famille sont aussi désactivés', () => {
    render(<WeekStructureEditor value={preset('vsd')} onChange={() => {}} readOnly />)
    expect(screen.getByLabelText("Famille d'équité du bloc A")).toBeDisabled()
    expect(screen.getByLabelText("Famille d'équité des gardes isolées")).toBeDisabled()
  })

  it('signale une semaine sans garde', () => {
    const empty = preset('solo')
    const none = { ...empty, days: empty.days.map(() => ({ mode: 'none' as const })) } as WeekStructure
    render(<WeekStructureEditor value={none} onChange={() => {}} />)
    expect(screen.getByRole('alert')).toHaveTextContent('Aucune garde ne sera générée')
  })

  describe('zone d’actions stable (aucun saut de mise en page)', () => {
    const ACTIONS = ['Créer un bloc', 'Garde isolée', 'Pas de garde', 'Annuler']
    const noneWeek = () => {
      const w = allSolo()
      return { ...w, days: w.days.map(() => ({ mode: 'none' as const })) } as WeekStructure
    }
    const actionButtons = (container: HTMLElement) =>
      Array.from(container.querySelectorAll('.wse__actions button')).map((b) => b.textContent)
    const hint = (container: HTMLElement) => container.querySelector('.wse__hint')!
    const summary = (container: HTMLElement) => container.querySelector('.wse__sel-summary')!
    const pressed = () =>
      screen.getByRole('group', { name: 'Jours de la semaine' }).querySelectorAll('[aria-pressed="true"]')

    it('sans sélection : aide visible, mêmes boutons présents mais désactivés', () => {
      const { container } = render(<WeekStructureEditor value={noneWeek()} onChange={() => {}} />)
      expect(actionButtons(container)).toEqual(ACTIONS)
      for (const name of ACTIONS) expect(screen.getByRole('button', { name })).toBeDisabled()
      expect(hint(container)).toHaveAttribute('data-shown', 'true')
      expect(summary(container)).toHaveAttribute('data-shown', 'false')
    })

    it('un jour sélectionné : même structure, résumé affiché, bloc impossible', () => {
      const { container } = render(<WeekStructureEditor value={noneWeek()} onChange={() => {}} />)
      fireEvent.click(day('Mardi'))
      expect(actionButtons(container)).toEqual(ACTIONS)
      expect(hint(container)).toHaveAttribute('data-shown', 'false')
      expect(summary(container)).toHaveAttribute('data-shown', 'true')
      expect(summary(container)).toHaveTextContent('1 jour sélectionné')
      expect(summary(container)).toHaveTextContent('Mar · un bloc réunit au moins deux jours')
      expect(screen.getByRole('button', { name: 'Créer un bloc' })).toBeDisabled()
      expect(screen.getByRole('button', { name: 'Garde isolée' })).toBeEnabled()
      expect(screen.getByRole('button', { name: 'Pas de garde' })).toBeEnabled()
      expect(screen.getByRole('button', { name: 'Annuler' })).toBeEnabled()
    })

    it('plusieurs jours contigus puis non contigus : résumé mis à jour, structure inchangée', () => {
      const { container } = render(<WeekStructureEditor value={noneWeek()} onChange={() => {}} />)
      fireEvent.click(day('Lundi'))
      fireEvent.click(day('Mardi'))
      expect(summary(container)).toHaveTextContent('2 jours sélectionnés')
      expect(summary(container)).toHaveTextContent('Lun · Mar')
      fireEvent.click(day('Jeudi'))
      expect(summary(container)).toHaveTextContent('3 jours sélectionnés')
      expect(summary(container)).toHaveTextContent('Lun · Mar · Jeu')
      expect(screen.getByRole('button', { name: 'Créer un bloc' })).toBeEnabled()
      expect(actionButtons(container)).toEqual(ACTIONS)

      fireEvent.click(day('Mardi'))
      expect(day('Mardi')).toHaveAttribute('aria-pressed', 'false')
      expect(summary(container)).toHaveTextContent('Lun · Jeu')
    })

    it('Annuler vide la sélection et revient à l’aide, boutons toujours en place', () => {
      const onChange = vi.fn()
      const { container } = render(<WeekStructureEditor value={noneWeek()} onChange={onChange} />)
      fireEvent.click(day('Lundi'))
      fireEvent.click(day('Mercredi'))
      fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))
      expect(onChange).not.toHaveBeenCalled()
      expect(pressed()).toHaveLength(0)
      expect(hint(container)).toHaveAttribute('data-shown', 'true')
      expect(actionButtons(container)).toEqual(ACTIONS)
      for (const name of ACTIONS) expect(screen.getByRole('button', { name })).toBeDisabled()
    })

    it('Garde isolée depuis une semaine vide : payload solo et sélection vidée', () => {
      const onPayload = vi.fn()
      const { container } = render(<Harness initial={noneWeek()} onPayload={onPayload} />)
      fireEvent.click(day('Lundi'))
      fireEvent.click(day('Mercredi'))
      fireEvent.click(screen.getByRole('button', { name: 'Garde isolée' }))
      expect(onPayload).toHaveBeenLastCalledWith({
        blocks: [],
        solo: ['LUN', 'MER'],
        soloFamily: '',
        excluded: ['MAR', 'JEU', 'VEN', 'SAM', 'DIM'],
      })
      expect(pressed()).toHaveLength(0)
      expect(screen.getByRole('button', { name: 'Lundi, garde isolée' })).toBeInTheDocument()
      expect(hint(container)).toHaveAttribute('data-shown', 'true')
      expect(actionButtons(container)).toEqual(ACTIONS)
    })

    it('Créer un bloc depuis une semaine vide, puis Pas de garde sur un jour du bloc', () => {
      const onPayload = vi.fn()
      render(<Harness initial={noneWeek()} onPayload={onPayload} />)
      fireEvent.click(day('Vendredi'))
      fireEvent.click(day('Dimanche'))
      fireEvent.click(screen.getByRole('button', { name: 'Créer un bloc' }))
      expect(onPayload.mock.calls.at(-1)?.[0].blocks).toEqual([
        { id: 'A', name: 'Ven · Dim', family: '', days: ['VEN', 'DIM'] },
      ])
      expect(pressed()).toHaveLength(0)

      fireEvent.click(day('Vendredi'))
      fireEvent.click(screen.getByRole('button', { name: 'Pas de garde' }))
      expect(onPayload.mock.calls.at(-1)?.[0].excluded).toContain('VEN')
      expect(screen.getByRole('button', { name: 'Vendredi, pas de garde' })).toBeInTheDocument()
    })
  })

  describe('accessibilité des jours', () => {
    it('aucun attribut title (pas d’infobulle native), libellé accessible conservé', () => {
      const { container } = render(<WeekStructureEditor value={preset('vsd')} onChange={() => {}} />)
      expect(container.querySelectorAll('[title]')).toHaveLength(0)
      const tiles = screen.getByRole('group', { name: 'Jours de la semaine' }).querySelectorAll('button')
      tiles.forEach((tile) => {
        expect(tile).not.toHaveAttribute('title')
        expect(tile.getAttribute('aria-label')).toMatch(
          /^(Lundi|Mardi|Mercredi|Jeudi|Vendredi|Samedi|Dimanche), /,
        )
      })
    })

    it('les libellés décrivent le rôle de chaque jour', () => {
      render(<Harness initial={allSolo()} onPayload={() => {}} />)
      fireEvent.click(day('Mardi'))
      fireEvent.click(screen.getByRole('button', { name: 'Pas de garde' }))
      expect(screen.getByRole('button', { name: 'Mardi, pas de garde' })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Lundi, garde isolée' })).toBeInTheDocument()
    })

    it('les jours restent des boutons natifs, focusables, à état pressé', () => {
      render(<WeekStructureEditor value={allSolo()} onChange={() => {}} />)
      const monday = day('Lundi')
      expect(monday.tagName).toBe('BUTTON')
      expect(monday).toHaveAttribute('type', 'button')
      monday.focus()
      expect(monday).toHaveFocus()
      expect(monday).toHaveAttribute('aria-pressed', 'false')
    })
  })
})
