import type { ApiWeekday, DemandMode, DemandPerson, DemandPolicyInput, DemandPolicyView } from './types'
import { sortWeekdays } from './weekdays'

/** The days selected per person (User), before "Enregistrer" — local only, never sent box by box. */
export type Selection = Record<string, ApiWeekday[]>

export type CoverageDraft = {
  mode: DemandMode
  sourceLineStableId: string | null
  selection: Selection
}

export type MatrixRow = DemandPerson & {
  /** Selected days but no longer a person of the chosen source line (the backend warns about it). */
  outsideSource: boolean
}

/** What the screen starts from: exactly the server's state. */
export function draftFromView(view: DemandPolicyView): CoverageDraft {
  const selection: Selection = {}
  for (const trigger of view.triggers) {
    selection[trigger.userStableId] = sortWeekdays(trigger.weekdays)
  }
  const onlySource = view.sourceOptions.length === 1 ? view.sourceOptions[0].lineStableId : null

  return {
    mode: view.line.type === 'PRIMARY' ? 'INDEPENDENT' : view.mode,
    sourceLineStableId: view.source?.lineStableId ?? onlySource,
    selection,
  }
}

/**
 * One row per person (User) — never per membership stint: someone listed twice
 * by the source line appears once. The source line's people first (by name),
 * then anyone still selected who is no longer part of it.
 */
export function matrixRows(view: DemandPolicyView, draft: CoverageDraft): MatrixRow[] {
  const byUser = new Map<string, MatrixRow>()
  const source = view.sourceOptions.find((option) => option.lineStableId === draft.sourceLineStableId)
  for (const person of source?.people ?? []) {
    if (!byUser.has(person.userStableId)) {
      byUser.set(person.userStableId, { ...person, outsideSource: false })
    }
  }
  const known = new Map(view.triggers.map((t) => [t.userStableId, t]))
  for (const [userStableId, days] of Object.entries(draft.selection)) {
    if (days.length > 0 && !byUser.has(userStableId)) {
      const person = known.get(userStableId)
      byUser.set(userStableId, {
        userStableId,
        firstName: person?.firstName ?? '',
        lastName: person?.lastName ?? 'Personne inconnue',
        outsideSource: true,
      })
    }
  }

  const collator = new Intl.Collator('fr')
  return [...byUser.values()].sort(
    (a, b) =>
      Number(a.outsideSource) - Number(b.outsideSource) ||
      collator.compare(a.lastName, b.lastName) ||
      collator.compare(a.firstName, b.firstName),
  )
}

export function toggleDay(selection: Selection, userStableId: string, day: ApiWeekday): Selection {
  const current = new Set(selection[userStableId] ?? [])
  if (current.has(day)) {
    current.delete(day)
  } else {
    current.add(day)
  }
  return { ...selection, [userStableId]: sortWeekdays(current) }
}

export function setDays(selection: Selection, userStableId: string, days: ApiWeekday[]): Selection {
  return { ...selection, [userStableId]: sortWeekdays(days) }
}

/** One day for everybody listed: on for all, or off for all. */
export function setColumn(selection: Selection, userStableIds: string[], day: ApiWeekday, on: boolean): Selection {
  const next = { ...selection }
  for (const userStableId of userStableIds) {
    const days = new Set(next[userStableId] ?? [])
    if (on) {
      days.add(day)
    } else {
      days.delete(day)
    }
    next[userStableId] = sortWeekdays(days)
  }
  return next
}

/**
 * The PUT body (D162, schema 1). An independent line sends neither a source
 * nor triggers; a person without any selected day is simply not a trigger.
 */
export function toInput(draft: CoverageDraft): DemandPolicyInput {
  if (draft.mode === 'INDEPENDENT') {
    return { schemaVersion: 1, mode: 'INDEPENDENT', source: null, triggers: [] }
  }

  const triggers = Object.entries(draft.selection)
    .filter(([, days]) => days.length > 0)
    .map(([userStableId, days]) => ({ userStableId, weekdays: sortWeekdays(days), increment: 1 }))
    .sort((a, b) => a.userStableId.localeCompare(b.userStableId))

  return {
    schemaVersion: 1,
    mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
    source: draft.sourceLineStableId ? { lineStableId: draft.sourceLineStableId } : null,
    triggers,
  }
}

/** Whether the draft differs from what the server holds — "Enregistrer" is only offered then. */
export function isDirty(draft: CoverageDraft, view: DemandPolicyView): boolean {
  return JSON.stringify(toInput(draft)) !== JSON.stringify(toInput(draftFromView(view)))
}
