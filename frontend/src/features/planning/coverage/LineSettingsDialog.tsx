import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { WeekStructureModal } from '../../week-structure'
import { fetchDemandPolicy, saveDemandPolicy } from './api'
import { CoverageMatrix } from './CoverageMatrix'
import { draftFromView, isDirty, matrixRows, toInput, type CoverageDraft } from './coverageModel'
import type { DemandPolicyView } from './types'
import { sortWeekdays, weekdayLabel } from './weekdays'
import './coverage.css'

type Props = {
  line: { stableId: string; name: string; type: 'PRIMARY' | 'SECONDARY' }
  onClose: () => void
  /** Something was saved (coverage rules or weekly structure): the caller may refresh what depends on it. */
  onSaved?: () => void
}

/**
 * "Paramètres de la ligne" (docs/decisions.md D167): the line's weekly
 * structure (the existing WeekStructureModal, reused as is) and, for a
 * secondary line, how it is covered — an independent duty, or a
 * reinforcement required according to who holds the duty of another line
 * (D162). The people × days matrix is edited locally and sent once, on
 * "Enregistrer"; the screen then shows exactly what the server answered.
 * Which lines may be reinforced, the warnings and every refusal come from
 * the backend — never recomputed here.
 *
 * The weekly structure dialog replaces this one while it is open (never two
 * dialogs stacked); the unsaved matrix is kept meanwhile, and the policy
 * (warnings, days without duty) is reloaded when the structure was saved.
 */
export function LineSettingsDialog({ line, onClose, onSaved }: Props) {
  const [view, setView] = useState<DemandPolicyView | null>(null)
  const [draft, setDraft] = useState<CoverageDraft | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [weekOpen, setWeekOpen] = useState(false)
  const inFlight = useRef(false)
  const sourceId = useId()

  const load = useCallback(
    (keepDraft: boolean) => {
      fetchDemandPolicy(line.stableId)
        .then((value) => {
          setView(value)
          setDraft((current) => (keepDraft && current ? current : draftFromView(value)))
          setLoadError(null)
        })
        .catch(() => setLoadError('Impossible de charger la configuration de cette ligne.'))
    },
    [line.stableId],
  )

  useEffect(() => {
    load(false)
  }, [load])

  async function save() {
    if (!view || !draft || inFlight.current) return
    inFlight.current = true
    setSaving(true)
    setSaveError(null)
    setNotice(null)
    try {
      const before = view.policy
      const saved = await saveDemandPolicy(line.stableId, toInput(draft))
      setView(saved)
      setDraft(draftFromView(saved))
      const unchanged = (before?.stableId ?? null) === (saved.policy?.stableId ?? null)
      setNotice(
        unchanged
          ? 'Aucune modification : la configuration enregistrée était déjà celle-ci.'
          : 'Configuration enregistrée. Elle s’appliquera à la prochaine génération de cette ligne.',
      )
      onSaved?.()
    } catch (err) {
      setSaveError(saveErrorMessage(err))
    } finally {
      inFlight.current = false
      setSaving(false)
    }
  }

  if (weekOpen) {
    return (
      <WeekStructureModal
        lineStableId={line.stableId}
        lineName={line.name}
        onClose={() => setWeekOpen(false)}
        onSaved={() => {
          load(true)
          onSaved?.()
        }}
      />
    )
  }

  const conditional = draft?.mode === 'CONDITIONAL_ON_SOURCE_ASSIGNMENT'
  const dirty = view !== null && draft !== null && isDirty(draft, view)
  const excluded = sortWeekdays(view?.targetStructure.excludedWeekdays ?? [])
  const hasBlocks = (view?.targetStructure.blocks.length ?? 0) > 0

  return (
    <Overlay
      title={`Paramètres — ${line.name}`}
      onClose={onClose}
      dismissible={!saving}
      stableLayout
      wide={line.type === 'SECONDARY'}
      footer={
        line.type === 'SECONDARY' ? (
          <>
            <button type="button" className="btn btn--secondary" onClick={onClose} disabled={saving}>
              {dirty ? 'Annuler' : 'Fermer'}
            </button>
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => void save()}
              disabled={!dirty || saving}
              aria-busy={saving}
            >
              {saving ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </>
        ) : (
          <button type="button" className="btn btn--primary" onClick={onClose}>
            Fermer
          </button>
        )
      }
    >
      {!view && !loadError && (
        <p role="status" className="muted">
          Chargement…
        </p>
      )}
      {loadError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{loadError}</span>
        </p>
      )}

      {view && draft && (
        <div className="cov-sections">
          <section className="cov-section" aria-labelledby={`${sourceId}-week`}>
            <h3 id={`${sourceId}-week`}>Semaine type</h3>
            <p className="cov-help">
              {!view.targetStructure.configured
                ? 'Aucune semaine type n’est encore configurée pour cette ligne.'
                : excluded.length === 0
                  ? 'Une garde chaque jour de la semaine.'
                  : `Pas de garde le ${excluded.map(weekdayLabel).join(', ')}.`}
            </p>
            <div>
              <button type="button" className="btn btn--secondary btn--sm" onClick={() => setWeekOpen(true)} disabled={saving}>
                <Icon name="calendar" size={16} strokeWidth={2} />
                Modifier la semaine type
              </button>
            </div>
          </section>

          {line.type === 'PRIMARY' ? (
            <section className="cov-section">
              <p className="cov-help">
                La ligne principale est toujours une garde indépendante : ses gardes sont à couvrir chaque jour de sa
                semaine type.
              </p>
            </section>
          ) : (
            <>
              <section className="cov-section">
                <fieldset className="cov-modes">
                  <legend>
                    <h3>Mode de couverture</h3>
                  </legend>
                  <label className="cov-mode">
                    <input
                      type="radio"
                      name={`${sourceId}-mode`}
                      checked={!conditional}
                      disabled={saving}
                      onChange={() => setDraft({ ...draft, mode: 'INDEPENDENT' })}
                    />
                    <span>
                      <strong>Garde indépendante</strong>
                      <span className="cov-help">Une garde à couvrir chaque jour de la semaine type.</span>
                    </span>
                  </label>
                  <label className="cov-mode">
                    <input
                      type="radio"
                      name={`${sourceId}-mode`}
                      checked={conditional}
                      disabled={saving}
                      onChange={() => setDraft({ ...draft, mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT' })}
                    />
                    <span>
                      <strong>Renfort selon le chirurgien de garde</strong>
                      <span className="cov-help">
                        Un deuxième chirurgien n’est nécessaire que certains jours, selon qui est de garde sur une
                        autre ligne.
                      </span>
                    </span>
                  </label>
                </fieldset>
              </section>

              {conditional && (
                <section className="cov-section">
                  <label htmlFor={`${sourceId}-source`} className="cov-label">
                    Ligne à renforcer
                  </label>
                  {view.sourceOptions.length === 0 ? (
                    <p className="cov-help">Aucune autre ligne de ce planning ne peut être renforcée pour le moment.</p>
                  ) : (
                    <select
                      id={`${sourceId}-source`}
                      className="cov-select"
                      value={draft.sourceLineStableId ?? ''}
                      disabled={saving}
                      onChange={(event) => setDraft({ ...draft, sourceLineStableId: event.target.value || null })}
                    >
                      <option value="">Choisir une ligne…</option>
                      {view.sourceOptions.map((option) => (
                        <option key={option.lineStableId} value={option.lineStableId}>
                          {option.name}
                        </option>
                      ))}
                    </select>
                  )}

                  {draft.sourceLineStableId && (
                    <>
                      <p className="cov-help">
                        Lorsqu’un chirurgien sélectionné est de garde sur la ligne à renforcer un jour coché, une garde
                        de renfort est requise sur cette ligne ce jour-là.
                      </p>
                      {hasBlocks && (
                        <p className="cov-help">
                          Pour une garde organisée en bloc, si un jour du bloc nécessite un renfort, tout le bloc est
                          considéré comme nécessitant un renfort.
                        </p>
                      )}
                      <CoverageMatrix
                        rows={matrixRows(view, draft)}
                        selection={draft.selection}
                        excludedWeekdays={excluded}
                        disabled={saving}
                        onChange={(selection) => setDraft({ ...draft, selection })}
                      />
                    </>
                  )}
                </section>
              )}
            </>
          )}

          {view.warnings.length > 0 && (
            <section className="cov-section" aria-labelledby={`${sourceId}-warnings`}>
              <h3 id={`${sourceId}-warnings`}>À vérifier</h3>
              <p className="cov-help">Selon la configuration enregistrée — ces points n’empêchent pas l’enregistrement.</p>
              <ul className="cov-warnings" aria-label="Avertissements de configuration">
                {view.warnings.map((warning, index) => (
                  <li key={`${warning.code}-${index}`} className="alert alert--warning">
                    <Icon name="alert" size={18} strokeWidth={2} />
                    <span>{warning.message}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      )}

      {notice && (
        <p role="status" className="alert alert--success">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>{notice}</span>
        </p>
      )}
      {saveError && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{saveError}</span>
        </p>
      )}
    </Overlay>
  )
}

/** Every refusal of the backend (D162), in plain words — the backend stays the authority. */
const REFUSALS: Record<string, string> = {
  line_already_materialized:
    'Les gardes de cette ligne existent déjà : son mode de couverture et sa ligne à renforcer ne peuvent plus changer. Les jours de chaque chirurgien restent modifiables.',
  planning_already_published:
    'Une ligne de ce planning est déjà publiée : une ligne de renfort ne peut plus être configurée pour cette période.',
  concurrent_update:
    'La configuration a été modifiée entre-temps par quelqu’un d’autre. Fermez puis rouvrez cette fenêtre pour repartir de la version actuelle.',
  PRIMARY_LINE_CANNOT_BE_CONDITIONAL: 'La ligne principale ne peut pas être une ligne de renfort.',
  SOURCE_REQUIRED: 'Choisissez la ligne à renforcer.',
  SOURCE_NOT_INDEPENDENT: 'Cette ligne est elle-même une ligne de renfort : elle ne peut pas être renforcée.',
  SOURCE_INACTIVE: 'La ligne choisie n’est plus active : choisissez-en une autre.',
  SOURCE_NOT_IN_PLANNING: 'La ligne choisie n’appartient pas à ce planning.',
  SOURCE_IS_TARGET: 'Une ligne ne peut pas se renforcer elle-même.',
  TARGET_IS_A_SOURCE:
    'D’autres lignes renforcent déjà celle-ci : elle ne peut pas devenir elle-même une ligne de renfort.',
  TARGET_INACTIVE: 'Cette ligne n’est plus active.',
  UNKNOWN_USER: 'Un chirurgien sélectionné n’existe plus. Fermez puis rouvrez cette fenêtre.',
}

function saveErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    const body = (err.body ?? {}) as { error?: string; code?: string }
    const key = body.code ?? body.error
    if (key && REFUSALS[key]) {
      return REFUSALS[key]
    }
    if (err.status === 403) {
      return 'Vous n’avez pas le droit de configurer cette ligne.'
    }
  }
  return 'La configuration n’a pas pu être enregistrée.'
}
