import '@testing-library/jest-dom/vitest'
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { status, stubApi } from '../../../testUtils/stubApi'
import { conditionalView, DR_A, DR_B, DR_C, policyView, RENFORT, SENIORS } from './coverageTestData'
import { STACKED_BELOW } from './CoverageMatrix'
import { LineSettingsDialog } from './LineSettingsDialog'
import type { DemandPolicyView } from './types'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const RENFORT_LINE: { stableId: string; name: string; type: 'PRIMARY' | 'SECONDARY' } = {
  stableId: RENFORT,
  name: 'Renfort',
  type: 'SECONDARY',
}
const POLICY = `/api/planning-lines/${RENFORT}/demand-policy`

function setup(view: DemandPolicyView, save: (body: unknown) => unknown = () => view, line = RENFORT_LINE) {
  const api = stubApi({
    [`GET /api/planning-lines/${line.stableId}/demand-policy`]: () => view,
    [`PUT /api/planning-lines/${line.stableId}/demand-policy`]: ({ body }) => save(body),
    [`GET /api/planning-lines/${line.stableId}/week-structure`]: () => ({
      blocks: [],
      solo: ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
      soloFamily: '',
      excluded: [],
    }),
  })
  const onSaved = vi.fn()
  render(<LineSettingsDialog line={line} onClose={vi.fn()} onSaved={onSaved} />)
  return { api, onSaved }
}

/** Real layout widths are measured; jsdom has none — give the matrix a desktop width. */
function desktopWidth() {
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: 900 } as DOMRect)
}

const box = (who: string, day: string) => screen.getByRole('checkbox', { name: `${who} — ${day}` })

describe('LineSettingsDialog (docs/decisions.md D167)', () => {
  it('never offers the reinforcement mode for the primary line', async () => {
    setup(policyView({ line: { stableId: SENIORS, name: 'Seniors', type: 'PRIMARY' } }), undefined, {
      stableId: SENIORS,
      name: 'Seniors',
      type: 'PRIMARY',
    })

    expect(await screen.findByText(/La ligne principale est toujours une garde indépendante/)).toBeInTheDocument()
    expect(screen.queryByText('Renfort selon le chirurgien de garde')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Modifier la semaine type/ })).toBeInTheDocument()
  })

  it('a secondary line chooses between an independent duty and a reinforcement', async () => {
    setup(policyView())

    const independent = await screen.findByRole('radio', { name: /Garde indépendante/ })
    const reinforcement = screen.getByRole('radio', { name: /Renfort selon le chirurgien de garde/ })
    expect(independent).toBeChecked()
    expect(screen.queryByLabelText('Ligne à renforcer')).not.toBeInTheDocument()

    fireEvent.click(reinforcement)
    expect(screen.getByLabelText('Ligne à renforcer')).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Seniors' })).toBeInTheDocument()
  })

  it('loads an existing policy into the matrix, one row per person', async () => {
    desktopWidth()
    setup(conditionalView())

    expect(await screen.findByLabelText('Ligne à renforcer')).toHaveValue(SENIORS)
    expect(screen.getAllByRole('row')).toHaveLength(1 + 3) // header + Dr A, Dr B (two stints, one row), Dr C
    expect(box('Alice Bernard', 'lundi')).toBeChecked()
    expect(box('Bob Claes', 'vendredi')).toBeChecked()
    expect(box('Bob Claes', 'mardi')).not.toBeChecked()
    expect(box('Anne Admin', 'dimanche')).not.toBeChecked()
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled()
  })

  it('changing the line to reinforce changes the people listed', async () => {
    desktopWidth()
    setup(conditionalView())

    fireEvent.change(await screen.findByLabelText('Ligne à renforcer'), { target: { value: 'line-autre' } })

    const rows = screen.getAllByRole('row').slice(1).map((row) => within(row).getByRole('rowheader').textContent)
    expect(rows).toEqual(['Bob Claes', 'Alice BernardNe fait plus partie de la ligne à renforcer'])
  })

  it('edits locally (box, whole row, no day) and sends MONDAY…SUNDAY once, on "Enregistrer"', async () => {
    desktopWidth()
    const saved = conditionalView({ policy: { stableId: 'policy-2', version: 2, createdAt: '2026-12-02T10:00:00+00:00' } })
    const { api, onSaved } = setup(policyView(), () => saved)

    fireEvent.click(await screen.findByRole('radio', { name: /Renfort selon le chirurgien de garde/ }))
    fireEvent.change(screen.getByLabelText('Ligne à renforcer'), { target: { value: SENIORS } })
    fireEvent.click(screen.getByRole('button', { name: 'Tous les jours pour Alice Bernard' }))
    for (const day of ['vendredi', 'samedi', 'dimanche', 'lundi']) {
      fireEvent.click(box('Bob Claes', day))
    }
    fireEvent.click(box('Bob Claes', 'lundi')) // unchecked again
    fireEvent.click(box('Anne Admin', 'mardi'))
    fireEvent.click(screen.getByRole('button', { name: 'Aucun jour pour Anne Admin' }))

    expect(api.requests('PUT', POLICY)).toHaveLength(0) // nothing sent box by box
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce())
    expect(api.requests('PUT', POLICY)[0].body).toEqual({
      schemaVersion: 1,
      mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
      source: { lineStableId: SENIORS },
      triggers: [
        { userStableId: DR_B.userStableId, weekdays: ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'], increment: 1 },
        { userStableId: DR_C.userStableId, weekdays: ['FRIDAY', 'SATURDAY', 'SUNDAY'], increment: 1 },
      ],
    })
    const sent = api.requests('PUT', POLICY)[0].body as { triggers: { userStableId: string }[] }
    expect(sent.triggers.map((t) => t.userStableId)).not.toContain(DR_A.userStableId) // no day: no trigger at all
    expect(await screen.findByText(/Configuration enregistrée/)).toBeInTheDocument()
  })

  it('never loses a change: several edits in the same tick all apply (functional updates)', async () => {
    desktopWidth()
    setup(conditionalView())
    await screen.findByLabelText('Ligne à renforcer')

    // Three changes dispatched before React re-renders — the UAT case that exposed a stale selection.
    act(() => {
      screen.getByRole('button', { name: 'Tous les jours pour Anne Admin' }).click()
      screen.getByRole('button', { name: 'Aucun jour pour Alice Bernard' }).click()
      screen.getByRole('button', { name: 'Aucun jour pour Bob Claes' }).click()
    })

    expect(box('Anne Admin', 'lundi')).toBeChecked()
    expect(box('Anne Admin', 'dimanche')).toBeChecked()
    expect(box('Alice Bernard', 'lundi')).not.toBeChecked()
    expect(box('Bob Claes', 'vendredi')).not.toBeChecked()
  })

  it('a whole column at once', async () => {
    desktopWidth()
    setup(conditionalView())

    fireEvent.click(await screen.findByRole('button', { name: 'Sélectionner le mardi pour tous' }))
    expect(box('Anne Admin', 'mardi')).toBeChecked()
    expect(box('Bob Claes', 'mardi')).toBeChecked()
    fireEvent.click(screen.getByRole('button', { name: 'Désélectionner le mardi pour tous' }))
    expect(box('Alice Bernard', 'mardi')).not.toBeChecked()
  })

  it('says so when the submission changed nothing — no new version', async () => {
    desktopWidth()
    const view = conditionalView()
    setup(view, () => view)

    fireEvent.click(await screen.findByRole('checkbox', { name: 'Anne Admin — lundi' }))
    fireEvent.click(box('Anne Admin', 'lundi'))
    // Back to the server state: nothing to save.
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled()
    fireEvent.click(box('Anne Admin', 'lundi'))
    // The server answers with the very same version (e.g. it judged the content identical).
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText(/Aucune modification/)).toBeInTheDocument()
  })

  it('shows the backend warnings as they are', async () => {
    setup(
      conditionalView({
        warnings: [
          { code: 'TRIGGER_PERSON_NOT_IN_SOURCE_LINE', details: {}, message: 'Alice Bernard ne fait pas partie de la ligne source.' },
          { code: 'TRIGGER_DAY_EXCLUDED_FROM_TARGET', details: {}, message: 'Bob Claes : la semaine type n’a pas de garde le mercredi.' },
          { code: 'TRIGGER_PARTIALLY_COVERS_TARGET_BLOCK', details: {}, message: 'Bob Claes : le bloc Week-end n’est coché qu’en partie.' },
        ],
      }),
    )

    const warnings = await screen.findByRole('list', { name: 'Avertissements de configuration' })
    expect(within(warnings).getAllByRole('listitem')).toHaveLength(3)
    expect(within(warnings).getByText(/ne fait pas partie de la ligne source/)).toBeInTheDocument()
    expect(within(warnings).getByText(/n’est coché qu’en partie/)).toBeInTheDocument()
  })

  it.each([
    ['line_already_materialized', status(409, { error: 'line_already_materialized' }), /ne peuvent plus changer/],
    ['planning_already_published', status(409, { error: 'planning_already_published' }), /déjà publiée/],
    ['source no longer independent', status(422, { error: 'invalid_demand_policy', code: 'SOURCE_NOT_INDEPENDENT' }), /elle-même une ligne de renfort/],
  ])('explains a refusal: %s', async (_name, reply, text) => {
    desktopWidth()
    setup(conditionalView(), () => reply)

    fireEvent.click(await screen.findByRole('button', { name: 'Sélectionner le mardi pour tous' }))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(text)
  })

  it('opens the weekly structure from here, keeps the unsaved matrix and reloads the policy after it', async () => {
    desktopWidth()
    const { api } = setup(conditionalView())

    fireEvent.click(await screen.findByRole('button', { name: 'Sélectionner le mardi pour tous' }))
    fireEvent.click(screen.getByRole('button', { name: /Modifier la semaine type/ }))
    expect(await screen.findByText('Semaine type — Renfort')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))

    expect(await screen.findByText('Paramètres — Renfort')).toBeInTheDocument()
    expect(box('Anne Admin', 'mardi')).toBeChecked() // the unsaved selection survived
    expect(api.requests('GET', POLICY).length).toBeGreaterThanOrEqual(1)
  })

  it('a day without duty in the weekly structure takes no new selection, but keeps an existing one visible', async () => {
    desktopWidth()
    setup(conditionalView({ targetStructure: { configured: true, excludedWeekdays: ['FRIDAY', 'WEDNESDAY'], blocks: [] } }))

    expect(await screen.findByRole('checkbox', { name: 'Anne Admin — mercredi (pas de garde de renfort ce jour)' })).toBeDisabled()
    const kept = screen.getByRole('checkbox', { name: 'Bob Claes — vendredi (pas de garde de renfort ce jour)' })
    expect(kept).toBeChecked()
    expect(kept).toBeEnabled() // can still be unchecked
    expect(screen.getByText(/Pas de garde le mercredi, vendredi/)).toBeInTheDocument()
  })

  it('explains the block rule when the line has blocks', async () => {
    setup(conditionalView({ targetStructure: { configured: true, excludedWeekdays: [], blocks: [{ name: 'Week-end', weekdays: ['FRIDAY', 'SATURDAY', 'SUNDAY'] }] } }))
    expect(await screen.findByText(/tout le bloc est considéré comme nécessitant un renfort/)).toBeInTheDocument()
  })

  it('switches to one card per person on a narrow screen — same data, same controls', async () => {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: STACKED_BELOW - 1 } as DOMRect)
    setup(conditionalView())

    const cards = await screen.findByRole('list', { name: 'Jours nécessitant un renfort, par chirurgien' })
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(within(cards).getAllByRole('group')).toHaveLength(3)
    const bob = within(cards).getByRole('group', { name: 'Bob Claes' })
    expect(within(bob).getByRole('checkbox', { name: 'Bob Claes — samedi' })).toBeChecked()
    fireEvent.click(within(bob).getByRole('checkbox', { name: 'Bob Claes — mardi' }))
    expect(within(bob).getByRole('checkbox', { name: 'Bob Claes — mardi' })).toBeChecked()
  })

  it('every control is a native, named, focusable control (keyboard)', async () => {
    desktopWidth()
    setup(conditionalView())

    const first = await screen.findByRole('checkbox', { name: 'Anne Admin — lundi' })
    first.focus()
    expect(first).toHaveFocus()
    for (const checkbox of screen.getAllByRole('checkbox')) {
      expect(checkbox.tagName).toBe('INPUT')
      expect(checkbox).toHaveAccessibleName()
    }
    for (const button of screen.getAllByRole('button')) {
      expect(button).toHaveAccessibleName()
    }
    expect(screen.getByLabelText('Ligne à renforcer').tagName).toBe('SELECT')
  })
})
