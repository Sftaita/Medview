import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { CalendarImpactNotice } from './CalendarImpactNotice'
import { mergeImpacts } from './impacts'
import type { CalendarImpact } from './types'

afterEach(cleanup)

function impact(overrides: Partial<CalendarImpact> = {}): CalendarImpact {
  return {
    planningStableId: 'plan-1',
    planningName: 'Trauma Delta',
    period: { stableId: 'c2', number: 2, count: 2 },
    lines: ['Seniors'],
    generatedAt: '2027-01-24T08:15:00+00:00',
    published: false,
    addedDays: ['2027-03-15'],
    releasedDays: [],
    from: '2027-03-15',
    to: '2027-03-15',
    conflicts: [],
    ...overrides,
  }
}

describe('CalendarImpactNotice (docs/decisions.md D216, D218)', () => {
  it('says nothing when nothing generated is met — never a false alarm', () => {
    const { container } = render(<CalendarImpactNotice impacts={[]} onDismiss={vi.fn()} />)
    expect(container).toBeEmptyDOMElement()
  })

  it('names the planning and its period, the generation date, and that it is recorded but not applied', () => {
    render(<CalendarImpactNotice impacts={[impact()]} onDismiss={vi.fn()} />)

    const notice = screen.getByRole('status', { name: 'Plannings déjà générés' })
    expect(notice).toHaveTextContent(
      'Attention : cette date appartient au planning Trauma Delta – Période 2, déjà généré le 24 janvier 2027.',
    )
    expect(notice).toHaveTextContent('Indisponibilité ajoutée le 15 mars 2027.')
    expect(notice).toHaveTextContent(
      'Votre calendrier personnel a bien été modifié, mais le planning généré reste inchangé : aucune garde n’est réattribuée automatiquement.',
    )
    expect(notice).not.toHaveTextContent('Indisponibilité retirée')
    expect(notice).not.toHaveTextContent('Vous êtes déjà de garde')
    expect(notice).toHaveClass('alert--info')
  })

  it('warns harder when a duty is held on those days, and keeps it assigned', () => {
    render(
      <CalendarImpactNotice
        impacts={[
          impact({
            published: true,
            addedDays: ['2027-03-15', '2027-03-16'],
            from: '2027-03-15',
            to: '2027-03-16',
            conflicts: [
              { dates: ['2027-03-15'], lineName: 'Seniors', dutyTypeName: 'Garde', blockName: null },
            ],
          }),
        ]}
        onDismiss={vi.fn()}
      />,
    )

    const notice = screen.getByRole('status', { name: 'Plannings déjà générés' })
    expect(notice).toHaveTextContent(
      'ces dates appartiennent au planning Trauma Delta – Période 2, déjà généré le 24 janvier 2027 et publié.',
    )
    expect(notice).toHaveTextContent(
      'Vous êtes déjà de garde le 15 mars 2027 (Seniors). Cette garde reste attribuée. Contactez le responsable du planning si une modification est nécessaire.',
    )
    expect(notice).toHaveClass('alert--warning')
  })

  it('says an absence shrunk, moved or deleted gave days back, and that the generated planning is unchanged', () => {
    render(
      <CalendarImpactNotice
        impacts={[
          impact({
            published: true,
            addedDays: ['2027-03-22'],
            releasedDays: ['2027-03-15', '2027-03-16', '2027-03-17', '2027-03-19'],
            from: '2027-03-15',
            to: '2027-03-22',
          }),
        ]}
        onDismiss={vi.fn()}
      />,
    )

    const notice = screen.getByRole('status', { name: 'Plannings déjà générés' })
    expect(notice).toHaveTextContent('ces dates appartiennent au planning Trauma Delta – Période 2')
    expect(notice).toHaveTextContent('Indisponibilité ajoutée le 22 mars 2027.')
    expect(notice).toHaveTextContent(
      'Indisponibilité retirée du 15 mars 2027 au 17 mars 2027 et le 19 mars 2027 : vous y êtes de nouveau disponible.',
    )
    expect(notice).toHaveTextContent('le planning généré reste inchangé')
    expect(notice).toHaveClass('alert--info')
  })

  it('tells a deletion alone, never with a duty', () => {
    render(
      <CalendarImpactNotice
        impacts={[impact({ addedDays: [], releasedDays: ['2027-03-15'] })]}
        onDismiss={vi.fn()}
      />,
    )

    const notice = screen.getByRole('status', { name: 'Plannings déjà générés' })
    expect(notice).toHaveTextContent('cette date appartient au planning')
    expect(notice).toHaveTextContent('Indisponibilité retirée le 15 mars 2027')
    expect(notice).not.toHaveTextContent('Indisponibilité ajoutée')
    expect(notice).not.toHaveTextContent('de garde')
  })

  it('names each planning concerned, without the period number for a single-period planning', () => {
    render(
      <CalendarImpactNotice
        impacts={[
          impact(),
          impact({
            planningStableId: 'plan-2',
            planningName: 'Urgences',
            period: { stableId: 'u1', number: 1, count: 1 },
          }),
        ]}
        onDismiss={vi.fn()}
      />,
    )

    const notice = screen.getByRole('status', { name: 'Plannings déjà générés' })
    expect(notice).toHaveTextContent('Trauma Delta – Période 2')
    expect(notice).toHaveTextContent('au planning Urgences, déjà généré')
    expect(notice).not.toHaveTextContent('Urgences – Période 1')
  })

  it('is dismissed without asking anything else', () => {
    const onDismiss = vi.fn()
    render(<CalendarImpactNotice impacts={[impact()]} onDismiss={onDismiss} />)

    fireEvent.click(screen.getByRole('button', { name: 'Compris' }))
    expect(onDismiss).toHaveBeenCalledTimes(1)
  })
})

describe('mergeImpacts', () => {
  it('joins what one save met in the same period, and lists each duty once', () => {
    const duty = { dates: ['2027-03-15'], lineName: 'Seniors', dutyTypeName: 'Garde', blockName: null }
    const merged = mergeImpacts([
      impact({ from: '2027-03-15', to: '2027-03-15', conflicts: [duty] }),
      impact({
        addedDays: ['2027-03-20', '2027-03-21'],
        from: '2027-03-20',
        to: '2027-03-21',
        published: true,
        lines: ['Juniors'],
        conflicts: [duty],
      }),
      impact({
        planningStableId: 'plan-2',
        planningName: 'Urgences',
        period: { stableId: 'u1', number: 1, count: 1 },
        addedDays: ['2027-03-01'],
        from: '2027-03-01',
        to: '2027-03-01',
      }),
    ])

    expect(merged.map((m) => m.period.stableId)).toEqual(['u1', 'c2'])
    expect(merged[1]).toMatchObject({
      from: '2027-03-15',
      to: '2027-03-21',
      published: true,
      lines: ['Seniors', 'Juniors'],
    })
    expect(merged[1].conflicts).toHaveLength(1)
    expect(merged[1].addedDays).toEqual(['2027-03-15', '2027-03-20', '2027-03-21'])
  })

  it('a day given back and taken again in the same save did not change: not told (D218)', () => {
    const duty = { dates: ['2027-03-15'], lineName: 'Seniors', dutyTypeName: 'Garde', blockName: null }
    // A deletion then a creation over the same day, plus a day really added.
    const merged = mergeImpacts([
      impact({ addedDays: [], releasedDays: ['2027-03-15'] }),
      impact({ addedDays: ['2027-03-15', '2027-03-16'], conflicts: [duty], to: '2027-03-16' }),
    ])
    expect(merged).toHaveLength(1)
    expect(merged[0]).toMatchObject({
      addedDays: ['2027-03-16'],
      releasedDays: [],
      from: '2027-03-16',
      to: '2027-03-16',
    })
    expect(merged[0].conflicts).toEqual([])

    // Nothing left to tell: the period is dropped.
    expect(
      mergeImpacts([
        impact({ addedDays: [], releasedDays: ['2027-03-15'] }),
        impact({ addedDays: ['2027-03-15'] }),
      ]),
    ).toEqual([])
  })
})
