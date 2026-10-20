import { lastDayOf } from '../detail/period'
import type { PlanningDetail, PlanningLineSummary } from '../types'

/**
 * The "Exporter le planning" dialog's state and the request it becomes
 * (docs/planning-export.md). Presentation only: nothing here ever renames a
 * line or the planning — the aliases and the title exist for the document.
 */

export type ExportFormat = 'pdf' | 'xlsx'

export type ExportLineChoice = {
  stableId: string
  /** The line's real name, never edited here. */
  name: string
  selected: boolean
  /** "Nom dans l'export". */
  label: string
}

export type ExportState = {
  format: ExportFormat
  title: string
  period: 'full' | 'custom'
  /** Custom period, inclusive calendar dates ("YYYY-MM-DD"). */
  firstDay: string
  lastDay: string
  /** In document order. */
  lines: ExportLineChoice[]
}

/** The API contract: `lines` order *is* the document order; `to` is exclusive, like everywhere else. */
export type ExportRequest = {
  format: ExportFormat
  title: string
  from?: string
  to?: string
  lines: { stableId: string; label: string }[]
}

/** What the document will be, for the note shown before exporting (docs/decisions.md D200). */
export function exportNote(planning: PlanningDetail): string {
  return planning.canManageCalendar === true
    ? 'Le document reprend le calendrier tel qu’il est maintenant, modifications non encore diffusées comprises : il est alors marqué « Calendrier opérationnel — modifications non publiées possibles ». Seules les périodes publiées y figurent. La version diffusée reste disponible avec « PDF de la dernière diffusion ». Les noms choisis ici ne servent qu’au document.'
    : 'Le document reprend le planning tel qu’il a été diffusé, sans les modifications faites depuis. Seules les périodes publiées y figurent. Les noms choisis ici ne servent qu’au document.'
}

export const TITLE_MAX_LENGTH = 120
export const LABEL_MAX_LENGTH = 80

const DAY = 86_400_000

function addDays(iso: string, days: number): string {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d) + days * DAY).toISOString().slice(0, 10)
}

/**
 * Whether the person may choose this line (docs/decisions.md D200): a
 * manager, any active line; anybody else, only a line with a published
 * period — the server refuses the others anyway, as if they did not exist.
 */
export function isExportableLine(line: PlanningLineSummary, manager: boolean): boolean {
  if (!line.active) return false
  if (manager) return true
  if (line.periods) return line.periods.some((period) => period.status === 'PUBLISHED')
  return line.periodStatus === undefined || line.periodStatus === 'PUBLISHED'
}

/** Defaults: PDF, the planning's name, the whole period, every exportable line in its current order under its own name. */
export function initialExportState(planning: PlanningDetail): ExportState {
  const manager = planning.canManageCalendar === true
  const lines = planning.lines
    .filter((line) => isExportableLine(line, manager))
    .sort((a, b) => a.position - b.position)
    .map((line) => ({ stableId: line.stableId, name: line.name, selected: true, label: line.name }))
  return {
    format: 'pdf',
    title: planning.name,
    period: 'full',
    firstDay: planning.startsAt,
    lastDay: lastDayOf(planning.endsAt),
    lines,
  }
}

/** Moves the line at `index` one step up (-1) or down (+1); out of bounds = unchanged. */
export function moveLine(lines: ExportLineChoice[], index: number, direction: -1 | 1): ExportLineChoice[] {
  const target = index + direction
  if (index < 0 || index >= lines.length || target < 0 || target >= lines.length) return lines
  const next = [...lines]
  ;[next[index], next[target]] = [next[target], next[index]]
  return next
}

export type ExportErrors = Partial<Record<'lines' | 'labels' | 'title' | 'period', string>>

/**
 * What would make the server refuse the export — checked here too so the
 * user sees why before sending, never instead of the server's own checks.
 */
export function validateExport(state: ExportState, planning: PlanningDetail): ExportErrors {
  const errors: ExportErrors = {}
  const selected = state.lines.filter((line) => line.selected)
  if (selected.length === 0) {
    errors.lines = 'Sélectionnez au moins une ligne à exporter.'
  } else if (selected.some((line) => line.label.trim() === '')) {
    errors.labels = 'Chaque ligne exportée doit avoir un nom.'
  } else if (selected.some((line) => line.label.trim().length > LABEL_MAX_LENGTH)) {
    errors.labels = `Un nom de ligne ne peut dépasser ${LABEL_MAX_LENGTH} caractères.`
  }

  if (state.title.trim() === '') {
    errors.title = 'Donnez un titre au document.'
  } else if (state.title.trim().length > TITLE_MAX_LENGTH) {
    errors.title = `Le titre ne peut dépasser ${TITLE_MAX_LENGTH} caractères.`
  }

  if (state.period === 'custom') {
    const planningLastDay = lastDayOf(planning.endsAt)
    if (!state.firstDay || !state.lastDay) {
      errors.period = 'Indiquez la date de début et la date de fin.'
    } else if (state.firstDay < planning.startsAt || state.lastDay > planningLastDay) {
      errors.period = 'La période doit rester dans les dates du planning.'
    } else if (state.firstDay > state.lastDay) {
      errors.period = 'La date de début doit précéder la date de fin.'
    }
  }

  return errors
}

export function toExportRequest(state: ExportState): ExportRequest {
  const request: ExportRequest = {
    format: state.format,
    title: state.title.trim(),
    lines: state.lines
      .filter((line) => line.selected)
      .map((line) => ({ stableId: line.stableId, label: line.label.trim() })),
  }
  if (state.period === 'custom') {
    request.from = state.firstDay
    request.to = addDays(state.lastDay, 1)
  }
  return request
}

/** Only when the server sent no usable name (it always does in practice). */
export function fallbackFilename(format: ExportFormat): string {
  return `planning.${format}`
}
