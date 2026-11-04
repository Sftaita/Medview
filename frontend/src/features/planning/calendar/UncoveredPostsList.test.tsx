import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { PublicationUncoveredUnit } from '../result/types'
import { UncoveredPostsList } from './UncoveredPostsList'

afterEach(cleanup)

const JEROME = { userStableId: 'u1', firstName: 'Jerome', lastName: 'De Muylder' }

function day(date: string, weekday: string, holder = JEROME) {
  return {
    date,
    weekday,
    reason: 'TRIGGERED',
    dutyStableId: `d-${date}`,
    sourceDutyStableId: `s-${date}`,
    sourceHolder: holder,
  }
}

function unit(overrides: Partial<PublicationUncoveredUnit> = {}): PublicationUncoveredUnit {
  return {
    lineStableId: 'fell',
    lineName: 'Fell/Ass',
    sourceLineName: 'Première ligne',
    conditional: true,
    materialized: true,
    dates: ['2026-10-02', '2026-10-03', '2026-10-04'],
    dutyStableIds: ['d-2026-10-02', 'd-2026-10-03', 'd-2026-10-04'],
    days: [day('2026-10-02', 'FRIDAY'), day('2026-10-03', 'SATURDAY'), day('2026-10-04', 'SUNDAY')],
    vacancy: {
      code: 'NO_MEMBER_ON_DATE',
      memberCount: 6,
      assignableCount: 0,
      firstMembershipStart: '2026-11-01',
      reasons: [],
    },
    ...overrides,
  }
}

describe('UncoveredPostsList (docs/decisions.md D232)', () => {
  it('says the dates, the source holder, why the post is required and why it is empty', () => {
    render(<UncoveredPostsList units={[unit()]} />)

    expect(screen.getByText('Fell/Ass — 3 gardes requises sans titulaire')).toBeInTheDocument()
    expect(screen.getByText('Du vendredi 2 octobre au dimanche 4 octobre')).toBeInTheDocument()
    expect(
      screen.getByText(/Jerome De Muylder tient « Première ligne » et déclenche ce renfort/),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        /Aucun membre de « Fell\/Ass » à ces dates : les adhésions commencent le 1(er)? novembre 2026/,
      ),
    ).toBeInTheDocument()
    expect(screen.getByText(/Ni la complétion ni une attribution manuelle/)).toBeInTheDocument()
  })

  it('counts every day and groups the posts that share one cause under one sentence', () => {
    const standalone = unit({
      dates: ['2026-10-05'],
      dutyStableIds: ['d-2026-10-05'],
      days: [day('2026-10-05', 'MONDAY', { userStableId: 'u2', firstName: 'Stephane', lastName: 'Urgyan' })],
    })
    render(<UncoveredPostsList units={[unit(), standalone]} />)

    expect(screen.getByText('Fell/Ass — 4 gardes requises sans titulaire')).toBeInTheDocument()
    expect(screen.getAllByText(/Aucun membre de « Fell\/Ass »/)).toHaveLength(1)
    const list = screen.getByRole('list', { name: 'Postes de Fell/Ass' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(2)
    expect(within(list).getByText(/Stephane Urgyan tient/)).toBeInTheDocument()
  })

  it('names both holders of a block whose days have different source holders', () => {
    render(
      <UncoveredPostsList
        units={[
          unit({
            dates: ['2026-10-02', '2026-10-03'],
            days: [
              day('2026-10-02', 'FRIDAY'),
              day('2026-10-03', 'SATURDAY', {
                userStableId: 'u2',
                firstName: 'Stephane',
                lastName: 'Urgyan',
              }),
            ],
          }),
        ]}
      />,
    )

    expect(
      screen.getByText(
        /Jerome De Muylder et Stephane Urgyan tiennent « Première ligne » et déclenchent ce renfort/,
      ),
    ).toBeInTheDocument()
  })

  it('a reinforcement not created yet points at the completion and cannot be located', () => {
    const onLocate = vi.fn()
    render(
      <UncoveredPostsList
        onLocate={onLocate}
        units={[
          unit({
            materialized: false,
            dutyStableIds: [],
            dates: ['2026-10-06'],
            days: [{ ...day('2026-10-06', 'TUESDAY'), dutyStableId: null }],
            vacancy: {
              code: 'NOT_CREATED',
              memberCount: 0,
              assignableCount: 0,
              firstMembershipStart: null,
              reasons: [],
            },
          }),
        ]}
      />,
    )

    expect(screen.getByText(/ces gardes ne sont pas encore créées/)).toBeInTheDocument()
    expect(screen.getByText(/utilisez « Compléter la période »/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Voir dans le calendrier' })).not.toBeInTheDocument()
  })

  it('says who cannot take the post and why, or that someone can', () => {
    render(
      <UncoveredPostsList
        units={[
          unit({
            vacancy: {
              code: 'NO_ELIGIBLE_MEMBER',
              memberCount: 2,
              assignableCount: 0,
              firstMembershipStart: null,
              reasons: [{ code: 'UNAVAILABLE', label: 'indisponible', count: 2 }],
            },
          }),
          unit({
            dates: ['2026-11-10'],
            days: [day('2026-11-10', 'TUESDAY')],
            vacancy: {
              code: 'ASSIGNABLE_MEMBERS',
              memberCount: 2,
              assignableCount: 1,
              firstMembershipStart: null,
              reasons: [],
            },
          }),
        ]}
      />,
    )

    expect(screen.getByText(/Aucun membre ne peut les assurer : indisponible \(2\)/)).toBeInTheDocument()
    expect(screen.getByText(/Des membres peuvent les assurer/)).toBeInTheDocument()
  })

  it('on a published line it warns without blocking, and says the next step is a republication (D233)', () => {
    render(
      <UncoveredPostsList
        units={[
          unit({
            linePublished: true,
            materialized: false,
            dutyStableIds: [],
            dates: ['2027-01-05'],
            days: [{ ...day('2027-01-05', 'TUESDAY'), dutyStableId: null }],
            vacancy: {
              code: 'NOT_CREATED',
              memberCount: 0,
              assignableCount: 0,
              firstMembershipStart: null,
              reasons: [],
            },
          }),
        ]}
      />,
    )

    expect(screen.getByText(/Ligne déjà publiée : cela n’empêche pas de republier/)).toBeInTheDocument()
    expect(
      screen.getByText(/Créez ces postes depuis le calendrier, attribuez-les, puis republiez/),
    ).toBeInTheDocument()
  })

  it('points at the coverage start when nobody can hold the post (D233)', () => {
    render(<UncoveredPostsList units={[unit()]} />)

    expect(screen.getByText(/réglez le « Début de couverture de cette ligne »/)).toBeInTheDocument()
  })

  it('locates a post in the calendar', () => {
    const onLocate = vi.fn()
    render(<UncoveredPostsList units={[unit()]} onLocate={onLocate} />)

    fireEvent.click(screen.getByRole('button', { name: 'Voir dans le calendrier' }))

    expect(onLocate).toHaveBeenCalledWith('d-2026-10-02')
  })

  it('an independent line says it is a required duty of the line', () => {
    render(
      <UncoveredPostsList
        units={[
          unit({
            conditional: false,
            sourceLineName: null,
            days: [
              {
                ...day('2026-10-02', 'FRIDAY'),
                reason: 'INTRINSIC_REQUIRED',
                sourceHolder: null,
                sourceDutyStableId: null,
              },
            ],
            dates: ['2026-10-02'],
          }),
        ]}
      />,
    )

    expect(screen.getByText(/Garde obligatoire de cette ligne/)).toBeInTheDocument()
  })

  it('renders nothing without a post — and from an older server that sends none', () => {
    const { container } = render(<UncoveredPostsList units={undefined} />)
    expect(container).toBeEmptyDOMElement()
  })
})
