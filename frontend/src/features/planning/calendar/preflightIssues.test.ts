import { describe, expect, it } from 'vitest'
import type { PublicationPreflight } from '../result/types'
import { blockingIssues, datesLabel, issuesByDuty } from './preflightIssues'

// Anonymised shape of the case found in production (2026-09-29): a published line, one duty whose holder declared
// an unavailability after the publication, one block held by two people, and no uncovered duty left.
const LINE = {
  lineStableId: 'l1',
  lineName: 'Ligne A',
  periodStatus: 'PUBLISHED' as const,
  hasGeneration: true,
}

function preflight(overrides: Partial<PublicationPreflight> = {}): PublicationPreflight {
  return {
    publishable: false,
    republishable: false,
    lines: [LINE],
    uncoveredDuties: [],
    inconsistentGroups: [],
    invalidAssignments: [],
    conflicts: [],
    undeterminedDuties: [],
    superfluousCoverages: [],
    ...overrides,
  }
}

const DUTY = {
  dutyStableId: 'd29',
  date: '2026-10-29',
  dutyTypeName: 'Garde',
  lineStableId: 'l1',
  lineName: 'Ligne A',
}
const MEMBER = { teamMemberStableId: 'm1', firstName: 'Membre', lastName: 'Un' }

describe('blockingIssues', () => {
  it('names the duty, the line, the person, the rule and the fix of an invalid assignment', () => {
    const [issue] = blockingIssues(
      preflight({
        invalidAssignments: [
          {
            duty: DUTY,
            member: MEMBER,
            reason: 'indisponible',
            reasonCode: 'UNAVAILABLE',
            unitStableKey: 'd29',
            dates: ['2026-10-29'],
            dutyStableIds: ['d29'],
          },
        ],
      }),
      'republish',
    )

    expect(issue.kind).toBe('assignment')
    expect(issue.dutyStableId).toBe('d29')
    expect(issue.where).toBe('Jeudi 29 octobre · ligne « Ligne A »')
    expect(issue.detail).toBe('Membre Un a déclaré une indisponibilité ce jour-là.')
    expect(issue.rule).toBe('personne n’est de garde un jour où elle s’est déclarée indisponible')
    expect(issue.fix).toMatch(/Remplacez le titulaire ou retirez l’affectation/)
    expect(issue.tag).toBe('indisponible')
  })

  it('gives a block by its first and last day and points at every one of its duties', () => {
    const issues = blockingIssues(
      preflight({
        conflicts: [
          {
            duty: { ...DUTY, dutyStableId: 'b1', date: '2026-10-17' },
            member: MEMBER,
            reason: 'déjà affecté à une garde incompatible',
            reasonCode: 'CONFLICT',
            unitStableKey: 'g1',
            dates: ['2026-10-17', '2026-10-18'],
            dutyStableIds: ['b1', 'b2'],
          },
        ],
      }),
      'republish',
    )

    expect(issues[0].where).toBe('Du samedi 17 octobre au dimanche 18 octobre · ligne « Ligne A »')
    expect(issues[0].detail).toBe('Membre Un est déjà de garde au même moment.')
    expect([...issuesByDuty(issues).keys()]).toEqual(['b1', 'b2'])
  })

  it('falls back on the translated reason for a rule it has no wording for, and on the duty for an older server', () => {
    const [issue] = blockingIssues(
      preflight({ invalidAssignments: [{ duty: DUTY, member: MEMBER, reason: 'compétence manquante' }] }),
      'republish',
    )

    expect(issue.detail).toBe('Membre Un : compétence manquante.')
    expect(issue.rule).toBe('compétence manquante')
    expect(issue.dutyStableIds).toEqual(['d29'])
  })

  it('lists the different holders of an inconsistent block', () => {
    const [issue] = blockingIssues(
      preflight({
        inconsistentGroups: [
          {
            groupInstanceStableId: 'g1',
            duty: { ...DUTY, dutyStableId: 'b1', date: '2026-10-17' },
            unitStableKey: 'g1',
            dates: ['2026-10-17', '2026-10-18'],
            dutyStableIds: ['b1', 'b2'],
            members: [MEMBER, { teamMemberStableId: 'm2', firstName: 'Membre', lastName: 'Deux' }],
          },
        ],
      }),
      'republish',
    )

    expect(issue.kind).toBe('inconsistent')
    expect(issue.detail).toBe(
      'Les jours de ce bloc sont attribués à des personnes différentes (Membre Un, Membre Deux).',
    )
    expect(issue.dutyStableIds).toEqual(['b1', 'b2'])
  })

  it('follows the server on uncovered duties: blocking at first publication, not on a published line (D143)', () => {
    const uncovered = [
      {
        dutyStableId: 'd6',
        date: '2026-10-06',
        dutyTypeName: 'Garde',
        lineStableId: 'l1',
        lineName: 'Ligne A',
      },
    ]

    expect(blockingIssues(preflight({ uncoveredDuties: uncovered }), 'republish')).toEqual([])
    const [issue] = blockingIssues(preflight({ uncoveredDuties: uncovered }), 'publish')
    expect(issue.kind).toBe('uncovered')
    expect(issue.dutyStableId).toBe('d6')

    // A line added since the publication, never published yet, still needs full coverage.
    const neverPublished = preflight({
      lines: [
        LINE,
        { lineStableId: 'l2', lineName: 'Ligne B', periodStatus: 'GENERATED', hasGeneration: true },
      ],
      uncoveredDuties: [{ ...uncovered[0], lineStableId: 'l2', lineName: 'Ligne B' }],
    })
    expect(blockingIssues(neverPublished, 'republish')).toHaveLength(1)
  })

  it('never marks an uncovered duty as an assignment to fix', () => {
    const issues = blockingIssues(
      preflight({ uncoveredDuties: [{ dutyStableId: 'd6', date: '2026-10-06', dutyTypeName: 'Garde' }] }),
      'publish',
    )
    expect(issuesByDuty(issues).size).toBe(0)
  })
})

describe('datesLabel', () => {
  it('reads a single day or a range', () => {
    expect(datesLabel(['2026-10-29'])).toBe('Jeudi 29 octobre')
    expect(datesLabel(['2026-10-17', '2026-10-18'])).toBe('Du samedi 17 octobre au dimanche 18 octobre')
  })
})
