import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { lastDayOf } from '../detail/period'
import type { PlanningDetail } from '../types'
import { exportPlanning } from './api'
import {
  fallbackFilename,
  initialExportState,
  LABEL_MAX_LENGTH,
  moveLine,
  TITLE_MAX_LENGTH,
  toExportRequest,
  validateExport,
  type ExportState,
} from './exportModel'
import './export.css'

type Props = {
  planning: PlanningDetail
  onClose: () => void
}

type Busy = 'export' | 'preview' | null

function errorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    const body = err.body as { error?: string; violations?: Record<string, string> } | null
    if (err.status === 409 && body?.error === 'not_yet_published') {
      return 'Ce planning n’est pas encore publié : il ne peut pas être exporté.'
    }
    if (err.status === 403) return 'Vous n’avez pas accès à ce planning.'
    if (err.status === 422) {
      const fields = Object.keys(body?.violations ?? {})
      if (fields.includes('lines')) return 'Sélectionnez au moins une ligne à exporter.'
      if (fields.some((field) => field.startsWith('lines['))) {
        return 'Une des lignes choisies n’est plus exportable. Fermez puis rouvrez l’export.'
      }
      if (fields.includes('from') || fields.includes('to')) {
        return 'La période doit rester dans les dates du planning.'
      }
      return 'Les paramètres de l’export ont été refusés.'
    }
  }
  return 'L’export n’a pas pu être généré. Réessayez.'
}

/**
 * "Exporter le planning" (docs/planning-export.md): the current calendar of
 * a published planning as a PDF or an Excel file, with the lines, their
 * order, their names in the document, the title and the period chosen
 * here. Nothing is saved: the choices live in this dialog only, and the
 * aliases and title never rename anything.
 *
 * "Aperçu" asks the server for the very same PDF (same endpoint, same
 * parameters) and shows it here — never a second rendering of the calendar.
 */
export function ExportModal({ planning, onClose }: Props) {
  const [state, setState] = useState<ExportState>(() => initialExportState(planning))
  const [busy, setBusy] = useState<Busy>(null)
  const [error, setError] = useState<string | null>(null)
  const [preview, setPreview] = useState<{ url: string; key: string } | null>(null)
  // A ref, not state: two clicks in the same tick both read the state as idle.
  const inFlight = useRef(false)
  const titleId = useId()
  const fromId = useId()
  const toId = useId()

  const errors = validateExport(state, planning)
  const valid = Object.keys(errors).length === 0
  const request = useMemo(() => toExportRequest(state), [state])
  const requestKey = JSON.stringify({ ...request, format: 'pdf' })
  const lastDay = lastDayOf(planning.endsAt)

  // A preview only describes the parameters it was made with.
  const shownPreview = preview && preview.key === requestKey ? preview : null
  useEffect(() => {
    return () => {
      if (preview) URL.revokeObjectURL(preview.url)
    }
  }, [preview])

  function update(patch: Partial<ExportState>) {
    setError(null)
    setState((current) => ({ ...current, ...patch }))
  }

  function updateLine(index: number, patch: { selected?: boolean; label?: string }) {
    setError(null)
    setState((current) => ({
      ...current,
      lines: current.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
    }))
  }

  async function run(kind: Exclude<Busy, null>) {
    if (inFlight.current || !valid) return
    inFlight.current = true
    setBusy(kind)
    setError(null)
    try {
      if (kind === 'preview') {
        const file = await exportPlanning(planning.stableId, { ...request, format: 'pdf' })
        setPreview({ url: URL.createObjectURL(file.blob), key: requestKey })
      } else {
        const file = await exportPlanning(planning.stableId, request)
        const url = URL.createObjectURL(file.blob)
        const link = document.createElement('a')
        link.href = url
        link.download = file.filename ?? fallbackFilename(request.format)
        document.body.appendChild(link)
        link.click()
        link.remove()
        URL.revokeObjectURL(url)
      }
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      inFlight.current = false
      setBusy(null)
    }
  }

  return (
    <Overlay
      title="Exporter le planning"
      onClose={onClose}
      dismissible={busy === null}
      wide={shownPreview !== null}
      footer={
        <>
          <button type="button" className="btn btn--secondary" onClick={onClose} disabled={busy !== null}>
            Fermer
          </button>
          <div className="export-actions">
            {state.format === 'pdf' && (
              <button
                type="button"
                className="btn btn--secondary"
                onClick={() => run('preview')}
                disabled={!valid || busy !== null}
                aria-busy={busy === 'preview'}
              >
                {busy === 'preview' ? 'Aperçu…' : 'Aperçu'}
              </button>
            )}
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => run('export')}
              disabled={!valid || busy !== null}
              aria-busy={busy === 'export'}
            >
              {busy === 'export' ? 'Export en cours…' : 'Exporter'}
            </button>
          </div>
        </>
      }
    >
      <div className={shownPreview ? 'export export--with-preview' : 'export'}>
        <div className="export-form form">
          <p className="muted export-note">
            Le document reprend le calendrier tel qu’il est maintenant, modifications non publiées comprises.
            Les noms choisis ici ne servent qu’au document.
          </p>

          <fieldset className="export-fieldset">
            <legend className="field__label">Format</legend>
            <div className="segmented" role="group" aria-label="Format">
              <button
                type="button"
                className="segmented__option"
                aria-pressed={state.format === 'pdf'}
                onClick={() => update({ format: 'pdf' })}
              >
                PDF
              </button>
              <button
                type="button"
                className="segmented__option"
                aria-pressed={state.format === 'xlsx'}
                onClick={() => update({ format: 'xlsx' })}
              >
                Excel (.xlsx)
              </button>
            </div>
          </fieldset>

          <div className="field">
            <label htmlFor={titleId} className="field__label">
              Titre du document
            </label>
            <input
              id={titleId}
              className="field__input"
              value={state.title}
              maxLength={TITLE_MAX_LENGTH}
              aria-invalid={errors.title ? 'true' : undefined}
              onChange={(event) => update({ title: event.target.value })}
            />
            {errors.title && <span className="field__hint export-error">{errors.title}</span>}
          </div>

          <fieldset className="export-fieldset">
            <legend className="field__label">Lignes</legend>
            <ol className="export-lines">
              {state.lines.map((line, index) => (
                <li key={line.stableId} className="export-line" data-selected={line.selected}>
                  <div className="export-line__order">
                    <button
                      type="button"
                      className="btn btn--ghost btn--sm"
                      aria-label={`Monter « ${line.name} »`}
                      disabled={index === 0}
                      onClick={() => update({ lines: moveLine(state.lines, index, -1) })}
                    >
                      <Icon name="up" size={16} strokeWidth={2.2} />
                    </button>
                    <button
                      type="button"
                      className="btn btn--ghost btn--sm"
                      aria-label={`Descendre « ${line.name} »`}
                      disabled={index === state.lines.length - 1}
                      onClick={() => update({ lines: moveLine(state.lines, index, 1) })}
                    >
                      <Icon name="down" size={16} strokeWidth={2.2} />
                    </button>
                  </div>
                  <div className="export-line__body">
                    <label className="export-check">
                      <input
                        type="checkbox"
                        checked={line.selected}
                        onChange={(event) => updateLine(index, { selected: event.target.checked })}
                        aria-label={`Afficher « ${line.name} » dans l’export`}
                      />
                      <span className="export-line__name">{line.name}</span>
                    </label>
                    <label className="export-alias">
                      <span className="field__hint">Nom dans l’export</span>
                      <input
                        className="field__input"
                        value={line.label}
                        maxLength={LABEL_MAX_LENGTH}
                        disabled={!line.selected}
                        aria-label={`Nom dans l’export pour « ${line.name} »`}
                        aria-invalid={line.selected && line.label.trim() === '' ? 'true' : undefined}
                        onChange={(event) => updateLine(index, { label: event.target.value })}
                      />
                    </label>
                  </div>
                </li>
              ))}
            </ol>
            {(errors.lines ?? errors.labels) && (
              <p role="alert" className="field__hint export-error">
                {errors.lines ?? errors.labels}
              </p>
            )}
          </fieldset>

          <fieldset className="export-fieldset">
            <legend className="field__label">Période</legend>
            <div className="segmented" role="group" aria-label="Période">
              <button
                type="button"
                className="segmented__option"
                aria-pressed={state.period === 'full'}
                onClick={() => update({ period: 'full' })}
              >
                Planning complet
              </button>
              <button
                type="button"
                className="segmented__option"
                aria-pressed={state.period === 'custom'}
                onClick={() => update({ period: 'custom' })}
              >
                Période personnalisée
              </button>
            </div>
            {state.period === 'custom' && (
              <div className="export-period">
                <div className="field">
                  <label htmlFor={fromId} className="field__label">
                    Du
                  </label>
                  <input
                    id={fromId}
                    type="date"
                    className="field__input"
                    value={state.firstDay}
                    min={planning.startsAt}
                    max={lastDay}
                    aria-invalid={errors.period ? 'true' : undefined}
                    onChange={(event) => update({ firstDay: event.target.value })}
                  />
                </div>
                <div className="field">
                  <label htmlFor={toId} className="field__label">
                    Au (inclus)
                  </label>
                  <input
                    id={toId}
                    type="date"
                    className="field__input"
                    value={state.lastDay}
                    min={planning.startsAt}
                    max={lastDay}
                    aria-invalid={errors.period ? 'true' : undefined}
                    onChange={(event) => update({ lastDay: event.target.value })}
                  />
                </div>
              </div>
            )}
            {errors.period && (
              <p role="alert" className="field__hint export-error">
                {errors.period}
              </p>
            )}
          </fieldset>

          {error && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{error}</span>
            </p>
          )}
        </div>

        {shownPreview && (
          <div className="export-preview">
            <iframe title="Aperçu du PDF" src={shownPreview.url} className="export-preview__frame" />
          </div>
        )}
      </div>
    </Overlay>
  )
}
