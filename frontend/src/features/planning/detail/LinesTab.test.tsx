import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { FillLinePlan } from '../pilot/types'
import type { PlanningLineSummary } from '../types'
import { LinesTab } from './LinesTab'

// Each line's counters and "Générer cette ligne" (docs/decisions.md D222).

afterEach(cleanup)

function line(stableId: string, name: string, type: 'PRIMARY' | 'SECONDARY'): PlanningLineSummary {
  return {
    stableId,
    name,
    type,
    position: type === 'PRIMARY' ? 0 : 1,
    active: true,
    team: { stableId: `t-${stableId}`, name: `Équipe ${name}`, canInvite: true },
    planningPeriodStableId: `pp-${stableId}`,
    createdAt: '2026-09-20T00:00:00+00:00',
    updatedAt: '2026-09-20T00:00:00+00:00',
  }
}

const base: Omit<FillLinePlan, 'lineStableId' | 'lineName'> = {
  lineType: 'PRIMARY',
  periodStatus: 'GENERATED',
  generated: true,
  action: 'NOTHING',
  reason: 'nothing_to_complete',
  selectable: false,
  selectedByDefault: false,
  requiredDutyCount: 30,
  coveredRequiredDutyCount: 30,
  uncoveredRequiredDutyCount: 0,
  protectedAssignmentCount: 30,
  blockers: [],
  warnings: [],
  afterSource: false,
}

const LINES = [
  line('l1', 'Seniors', 'PRIMARY'),
  line('l2', 'Juniors', 'SECONDARY'),
  line('l3', 'Renfort', 'SECONDARY'),
]
const PLANS: Record<string, FillLinePlan> = {
  l1: { ...base, lineStableId: 'l1', lineName: 'Seniors' },
  l2: {
    ...base,
    lineStableId: 'l2',
    lineName: 'Juniors',
    generated: false,
    periodStatus: 'DRAFT',
    action: 'GENERATE',
    reason: null,
    selectable: true,
    selectedByDefault: true,
    coveredRequiredDutyCount: 0,
    uncoveredRequiredDutyCount: 30,
    protectedAssignmentCount: 0,
  },
  l3: {
    ...base,
    lineStableId: 'l3',
    lineName: 'Renfort',
    generated: false,
    periodStatus: 'DRAFT',
    action: 'EXCLUDED',
    reason: 'blocked',
    requiredDutyCount: 0,
    blockers: ['NO_ACTIVE_RULE_SET'],
  },
}

function renderTab(props: Partial<Parameters<typeof LinesTab>[0]> = {}) {
  const handlers = { onGenerateLine: vi.fn() }
  render(
    <LinesTab
      lines={LINES}
      facesByTeam={{}}
      canManage
      canConfigure
      saving={false}
      onManageMembers={vi.fn()}
      onDeleteLine={vi.fn()}
      onConfigureLine={vi.fn()}
      onAddLine={vi.fn(async () => true)}
      fillPlans={PLANS}
      {...handlers}
      {...props}
    />,
  )
  return handlers
}

const row = (name: string) => screen.getByText(name, { selector: 'strong' }).closest('li') as HTMLElement

/** The entries of a line's "⋯" menu, left open. */
function menuOf(name: string) {
  fireEvent.click(within(row(name)).getByRole('button', { name: `Actions pour ${name}` }))
  return screen.getByRole('menu', { name: `Actions pour ${name}` })
}

describe('LinesTab — counters and "Générer cette ligne"', () => {
  it('shows what each line holds and misses', () => {
    renderTab()

    expect(within(row('Seniors')).getByText('30/30 gardes attribuées')).toBeInTheDocument()
    expect(within(row('Juniors')).getByText('Jamais générée · 30 gardes à pourvoir')).toBeInTheDocument()
  })

  it('offers "Générer cette ligne" in the line’s menu, only where it has something to receive', () => {
    const { onGenerateLine } = renderTab()

    expect(within(menuOf('Seniors')).queryByRole('menuitem', { name: 'Générer cette ligne' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Actions pour Seniors' }))

    fireEvent.click(within(menuOf('Juniors')).getByRole('menuitem', { name: 'Générer cette ligne' }))
    expect(onGenerateLine).toHaveBeenCalledWith(LINES[1])
    // Never a button of its own on the row.
    expect(screen.queryByRole('button', { name: 'Générer cette ligne' })).not.toBeInTheDocument()
  })

  it('explains, in words, why a line cannot be generated', () => {
    renderTab()

    expect(within(menuOf('Renfort')).queryByRole('menuitem', { name: 'Générer cette ligne' })).toBeNull()
    expect(
      within(row('Renfort')).getByText(
        /Impossible pour l’instant : les règles de génération de la ligne ne sont pas activées/,
      ),
    ).toBeInTheDocument()
  })

  it('no second computation while one is running', () => {
    renderTab({ busy: true })

    const menu = menuOf('Juniors')
    expect(within(menu).queryByRole('menuitem', { name: 'Générer cette ligne' })).toBeNull()
    // The rest of the menu stays.
    expect(within(menu).getByRole('menuitem', { name: 'Paramètres de la ligne' })).toBeInTheDocument()
  })

  it('no "Règles de génération" entry: the rules are in the settings of the line (docs/decisions.md D228)', () => {
    renderTab()

    expect(within(menuOf('Juniors')).queryByRole('menuitem', { name: /Règles/ })).toBeNull()
  })

  it('shows nothing of this to someone without the plans (a plain member)', () => {
    renderTab({ fillPlans: undefined, onGenerateLine: undefined })

    expect(within(menuOf('Juniors')).queryByRole('menuitem', { name: 'Générer cette ligne' })).toBeNull()
    expect(screen.queryByText(/gardes attribuées/)).not.toBeInTheDocument()
  })
})

describe('LinesTab — when a line is needed (docs/decisions.md D224)', () => {
  const fell = (coverage: PlanningLineSummary['coverage']) => ({
    ...line('l-fell', 'Fell/Ass', 'SECONDARY'),
    coverage,
  })

  it('a reinforcement line without any condition says so and opens its editor — an active rule set proves nothing', () => {
    const onConfigureLine = vi.fn()
    renderTab({
      lines: [
        fell({
          mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
          structureSource: 'INHERITED',
          source: { lineStableId: 'l1', name: 'Première ligne' },
          triggerCount: 0,
        }),
      ],
      fillPlans: {},
      onConfigureLine,
    })

    const fellRow = row('Fell/Ass')
    expect(
      within(fellRow).getByText(/Conditions de renfort à configurer — renfort de Première ligne/),
    ).toBeInTheDocument()
    fireEvent.click(within(fellRow).getByRole('button', { name: 'Configurer les conditions' }))
    expect(onConfigureLine).toHaveBeenCalledOnce()
  })

  it('a configured reinforcement line names its source, its triggers and its inherited blocks', () => {
    renderTab({
      lines: [
        fell({
          mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
          structureSource: 'INHERITED',
          source: { lineStableId: 'l1', name: 'Première ligne' },
          triggerCount: 3,
        }),
      ],
      fillPlans: {},
    })
    expect(
      within(row('Fell/Ass')).getByText(
        'Renfort de Première ligne · 3 chirurgiens déclencheurs · blocs hérités de Première ligne',
      ),
    ).toBeInTheDocument()
  })

  it('an independent secondary line with neither structure nor condition says what is missing', () => {
    renderTab({ lines: [fell({ mode: 'INDEPENDENT', hasWeekStructure: false })], fillPlans: {} })
    expect(within(row('Fell/Ass')).getByText(/Règles de couverture à configurer/)).toBeInTheDocument()
  })

  it('an independent line says which weekly structure it uses (docs/decisions.md D227)', () => {
    renderTab({
      lines: [
        fell({ mode: 'INDEPENDENT', hasWeekStructure: true, weekStructure: 'GENERAL' }),
        {
          ...line('l-own', 'Propre', 'SECONDARY'),
          coverage: { mode: 'INDEPENDENT', hasWeekStructure: true, weekStructure: 'CUSTOM' },
        },
      ],
      fillPlans: {},
    })

    expect(within(row('Fell/Ass')).getByText('Semaine type générale')).toBeInTheDocument()
    expect(within(row('Propre')).getByText('Semaine type personnalisée')).toBeInTheDocument()
  })
})
