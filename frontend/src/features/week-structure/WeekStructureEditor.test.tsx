import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { WeekStructureEditor } from './WeekStructureEditor'
import { allSolo, preset } from './weeklyStructure'
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
      blocks: [{ id: 'A', name: 'Ven · Dim', days: ['VEN', 'DIM'] }],
      solo: ['LUN', 'MAR', 'MER', 'JEU', 'SAM'],
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

  it('signale une semaine sans garde', () => {
    const empty = preset('solo')
    const none = { ...empty, days: empty.days.map(() => ({ mode: 'none' as const })) } as WeekStructure
    render(<WeekStructureEditor value={none} onChange={() => {}} />)
    expect(screen.getByRole('alert')).toHaveTextContent('Aucune garde ne sera générée')
  })
})
