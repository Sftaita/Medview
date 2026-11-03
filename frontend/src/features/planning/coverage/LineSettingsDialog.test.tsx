import '@testing-library/jest-dom/vitest'
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { StrictMode } from 'react'
import { afterEach, describe, expect, it, onTestFinished, vi } from 'vitest'
import { status, stubApi, type Route } from '../../../testUtils/stubApi'
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

function setup(
  view: DemandPolicyView,
  save: (body: unknown) => unknown = () => view,
  line = RENFORT_LINE,
  extra: Record<string, Route> = {},
) {
  const api = stubApi({
    ...extra,
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

    expect(
      await screen.findByText(/La ligne principale est toujours une garde indépendante/),
    ).toBeInTheDocument()
    expect(screen.queryByText('Renfort conditionnel')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Modifier la semaine type/ })).toBeInTheDocument()
  })

  it('a secondary line chooses between an independent duty and a reinforcement', async () => {
    setup(policyView())

    const independent = await screen.findByRole('radio', { name: /Couverture indépendante/ })
    const reinforcement = screen.getByRole('radio', { name: /Renfort conditionnel/ })
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

    const rows = screen
      .getAllByRole('row')
      .slice(1)
      .map((row) => within(row).getByRole('rowheader').textContent)
    expect(rows).toEqual(['Bob Claes', 'Alice BernardNe fait plus partie de la ligne à renforcer'])
  })

  it('edits locally (box, whole row, no day) and sends MONDAY…SUNDAY once, on "Enregistrer"', async () => {
    desktopWidth()
    const saved = conditionalView({
      policy: { stableId: 'policy-2', version: 2, createdAt: '2026-12-02T10:00:00+00:00' },
    })
    const { api, onSaved } = setup(policyView(), () => saved)

    fireEvent.click(await screen.findByRole('radio', { name: /Renfort conditionnel/ }))
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
        {
          userStableId: DR_B.userStableId,
          weekdays: ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'],
          increment: 1,
        },
        { userStableId: DR_C.userStableId, weekdays: ['FRIDAY', 'SATURDAY', 'SUNDAY'], increment: 1 },
      ],
    })
    const sent = api.requests('PUT', POLICY)[0].body as { triggers: { userStableId: string }[] }
    expect(sent.triggers.map((t) => t.userStableId)).not.toContain(DR_A.userStableId) // no day: no trigger at all
    expect(await screen.findByText(/Configuration enregistrée/)).toBeInTheDocument()
  })

  it('a late response never overwrites what has been ticked since (D224, 390 px)', async () => {
    desktopWidth()
    // Two loads under StrictMode, answered in the reverse order: the older one arrives last.
    const answers: ((view: DemandPolicyView) => void)[] = []
    stubApi({
      [`GET /api/planning-lines/${RENFORT}/demand-policy`]: () =>
        new Promise<DemandPolicyView>((resolve) => answers.push(resolve)),
    })
    render(
      <StrictMode>
        <LineSettingsDialog line={RENFORT_LINE} onClose={vi.fn()} />
      </StrictMode>,
    )
    await waitFor(() => expect(answers).toHaveLength(2))

    await act(async () => answers[1](conditionalView()))
    const alice = box('Alice Bernard', 'lundi')
    expect(alice).toBeChecked()
    fireEvent.click(alice)
    expect(alice).not.toBeChecked()

    await act(async () => answers[0](conditionalView()))
    expect(box('Alice Bernard', 'lundi')).not.toBeChecked() // the tap is still there
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeEnabled()
  })

  it('brings the outcome of "Enregistrer" into view — below the fold on a phone (D224, 390 px)', async () => {
    const scrolled: string[] = []
    const original = HTMLElement.prototype.scrollIntoView
    HTMLElement.prototype.scrollIntoView = function (this: HTMLElement) {
      scrolled.push(this.textContent ?? '')
    }
    onTestFinished(() => {
      HTMLElement.prototype.scrollIntoView = original
    })
    setup(conditionalView(), () =>
      conditionalView({
        policy: { stableId: 'policy-2', version: 2, createdAt: '2026-12-02T10:00:00+00:00' },
      }),
    )

    fireEvent.click(await screen.findByRole('button', { name: 'Aucun jour pour Alice Bernard' }))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await screen.findByText(/Configuration enregistrée/)
    await waitFor(() =>
      expect(scrolled.some((text) => text.includes('Configuration enregistrée'))).toBe(true),
    )
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
          {
            code: 'TRIGGER_PERSON_NOT_IN_SOURCE_LINE',
            details: {},
            message: 'Alice Bernard ne fait pas partie de la ligne source.',
          },
          {
            code: 'TRIGGER_DAY_EXCLUDED_FROM_TARGET',
            details: {},
            message: 'Bob Claes : la semaine type n’a pas de garde le mercredi.',
          },
          {
            code: 'TRIGGER_PARTIALLY_COVERS_TARGET_BLOCK',
            details: {},
            message: 'Bob Claes : le bloc Week-end n’est coché qu’en partie.',
          },
        ],
      }),
    )

    const warnings = await screen.findByRole('list', { name: 'Avertissements de configuration' })
    expect(within(warnings).getAllByRole('listitem')).toHaveLength(3)
    expect(within(warnings).getByText(/ne fait pas partie de la ligne source/)).toBeInTheDocument()
    expect(within(warnings).getByText(/n’est coché qu’en partie/)).toBeInTheDocument()
  })

  it.each([
    [
      'line_already_materialized',
      status(409, { error: 'line_already_materialized' }),
      /ne peuvent plus changer/,
    ],
    [
      'source no longer independent',
      status(422, { error: 'invalid_demand_policy', code: 'SOURCE_NOT_INDEPENDENT' }),
      /elle-même une ligne de renfort/,
    ],
  ])('explains a refusal: %s', async (_name, reply, text) => {
    desktopWidth()
    setup(conditionalView(), () => reply)

    fireEvent.click(await screen.findByRole('button', { name: 'Sélectionner le mardi pour tous' }))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(text)
  })

  it('opens the weekly structure from here, keeps the unsaved matrix and reloads the policy after it', async () => {
    desktopWidth()
    // A reinforcement recorded before D224 kept its own structure: still editable from here.
    const { api } = setup(conditionalView({ structureSource: 'OWN', inheritedFrom: null }))

    fireEvent.click(await screen.findByRole('button', { name: 'Sélectionner le mardi pour tous' }))
    fireEvent.click(screen.getByRole('button', { name: /Modifier la semaine type/ }))
    const weekDialog = await screen.findByRole('dialog', { name: 'Semaine type — Renfort' })
    // Once loaded, the editor has its own (selection) "Annuler": close through the dialog's footer.
    fireEvent.click(
      within(weekDialog.querySelector<HTMLElement>('.overlay__footer')!).getByRole('button', {
        name: 'Annuler',
      }),
    )

    expect(await screen.findByText('Paramètres — Renfort')).toBeInTheDocument()
    expect(box('Anne Admin', 'mardi')).toBeChecked() // the unsaved selection survived
    expect(api.requests('GET', POLICY).length).toBeGreaterThanOrEqual(1)
  })

  it('a day without duty in the weekly structure takes no new selection, but keeps an existing one visible', async () => {
    desktopWidth()
    setup(
      conditionalView({
        structureSource: 'OWN',
        inheritedFrom: null,
        targetStructure: { configured: true, excludedWeekdays: ['FRIDAY', 'WEDNESDAY'], blocks: [] },
      }),
    )

    expect(
      await screen.findByRole('checkbox', {
        name: 'Anne Admin — mercredi (pas de garde de renfort ce jour)',
      }),
    ).toBeDisabled()
    const kept = screen.getByRole('checkbox', {
      name: 'Bob Claes — vendredi (pas de garde de renfort ce jour)',
    })
    expect(kept).toBeChecked()
    expect(kept).toBeEnabled() // can still be unchecked
    expect(screen.getByText(/Pas de garde le mercredi, vendredi/)).toBeInTheDocument()
  })

  it('shows the blocks inherited from the source, read-only, and never a structure of its own (D224)', async () => {
    setup(conditionalView())

    const inherited = await screen.findByLabelText('Structure des blocs héritée de Seniors')
    expect(inherited).toHaveTextContent('Bloc « Week-end » : vendredi, samedi et dimanche')
    expect(inherited).toHaveTextContent('Aucun renfort n’est ajouté pour compléter un bloc')
    expect(inherited).toHaveTextContent(
      'Automatique, rien à régler : les horaires et les blocs viennent de Seniors.',
    )
    expect(
      screen.queryByText(/tout le bloc est considéré comme nécessitant un renfort/),
    ).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Modifier la semaine type/ })).not.toBeInTheDocument()
    // The surgeons who TRIGGER a reinforcement — never the members who hold it.
    expect(screen.getByText('Chirurgiens de Seniors qui déclenchent un renfort')).toBeInTheDocument()
    expect(screen.getByText(/Les membres de Renfort sont ceux qui assurent ces renforts/)).toBeInTheDocument()
  })

  it('sums up the conditions being edited, and says when there is none', async () => {
    desktopWidth()
    setup(conditionalView())

    const summary = await screen.findByLabelText('Résumé des conditions de renfort')
    expect(summary).toHaveTextContent(
      'Quand Alice Bernard est de garde sur Seniors le lundi, mardi, mercredi, jeudi, vendredi, samedi ou dimanche : un renfort Renfort.',
    )
    expect(summary).toHaveTextContent(
      'Quand Bob Claes est de garde sur Seniors le vendredi, samedi ou dimanche : un renfort Renfort.',
    )
    expect(summary).toHaveTextContent('Chaque renfort est assuré par un membre de Renfort.')
    fireEvent.click(screen.getByRole('button', { name: 'Aucun jour pour Alice Bernard' }))
    fireEvent.click(screen.getByRole('button', { name: 'Aucun jour pour Bob Claes' }))
    expect(summary).toHaveTextContent('Aucune condition : aucune garde de renfort ne sera demandée.')
  })

  it('previews the duties needed from the draft — computed by the server, nothing saved', async () => {
    desktopWidth()
    const { api } = setup(conditionalView(), undefined, undefined, {
      [`POST /api/planning-lines/${RENFORT}/demand-policy/preview`]: () => ({
        periods: [
          {
            period: { stableId: 'c1', number: 1, startsAt: '2026-10-01', endsAt: '2027-01-29' },
            generated: false,
            sourceGenerated: true,
            dutyCount: 5,
            units: [
              { dates: ['2026-10-02', '2026-10-03', '2026-10-04'], block: true, sourceHolder: DR_C },
              { dates: ['2026-10-10'], block: false, sourceHolder: DR_B },
              { dates: ['2026-10-16', '2026-10-18'], block: true, sourceHolder: DR_C },
            ],
          },
          {
            period: { stableId: 'c2', number: 2, startsAt: '2027-01-29', endsAt: '2027-05-03' },
            generated: false,
            sourceGenerated: false,
            dutyCount: 0,
            units: [],
          },
        ],
      }),
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Aucun jour pour Alice Bernard' }))
    fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser les gardes nécessaires' }))

    const result = await screen.findByRole('status', { name: 'Gardes de renfort nécessaires' })
    expect(result).toHaveTextContent('5 gardes de renfort dont 2 blocs')
    expect(result).toHaveTextContent('Période 1 — jeu. 1 oct. au jeu. 28 janv.') // end exclusive: the last day shown
    expect(result).toHaveTextContent('Bob Claes de garde')
    expect(result).toHaveTextContent('Chaque bloc est confié à une seule personne.')
    expect(result).toHaveTextContent('n’est pas encore générée sur cette période')
    const sent = api.requests('POST', `${POLICY}/preview`)[0].body as { triggers: { userStableId: string }[] }
    expect(sent.triggers.map((t) => t.userStableId)).toEqual([DR_C.userStableId]) // the draft, unsaved
    expect(api.requests('PUT', POLICY)).toHaveLength(0)
  })

  it('switches to one card per person on a narrow screen — same data, same controls', async () => {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
      width: STACKED_BELOW - 1,
    } as DOMRect)
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
