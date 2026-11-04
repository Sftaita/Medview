import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { Overlay } from '../../../components/Overlay'
import { ApiError } from '../../../lib/apiClient'
import { saveWeekStructureMode, WeekStructureModal } from '../../week-structure'
import { GenerationRulesPanel } from '../pilot/GenerationRulesPanel'
import { fetchDemandPolicy, saveDemandPolicy } from './api'
import { CoverageMatrix } from './CoverageMatrix'
import { CoverageStartSection } from './CoverageStartSection'
import { DemandPreviewPanel, InheritedStructurePanel, TriggerSummary } from './ReinforcementPanels'
import { draftFromView, isDirty, matrixRows, toInput, type CoverageDraft } from './coverageModel'
import type { DemandPolicyView } from './types'
import { sortWeekdays, weekdayLabel } from './weekdays'
import './coverage.css'

type Props = {
  line: {
    stableId: string
    name: string
    type: 'PRIMARY' | 'SECONDARY'
    coverage?: { mode: string; weekStructure?: 'GENERAL' | 'CUSTOM' }
  }
  timezone?: string
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
 *
 * docs/decisions.md D224: "Règles de couverture" — when a duty is needed —
 * are the only thing set here (the distribution between members is "Règles
 * de génération"). A reinforcement line has no weekly structure of its own:
 * it inherits its source's blocks, shown read-only; the matrix lists the
 * SOURCE's surgeons, who trigger a reinforcement — never this line's members,
 * who hold them. The preview is computed by the server, nothing saved.
 */
export function LineSettingsDialog({ line, timezone = 'Europe/Brussels', onClose, onSaved }: Props) {
  // docs/decisions.md D227: what this line uses — the general weekly structure, or its own.
  const [weekMode, setWeekMode] = useState<'GENERAL' | 'CUSTOM'>(
    line.coverage?.weekStructure ?? (line.type === 'PRIMARY' ? 'GENERAL' : 'CUSTOM'),
  )
  const [weekModeError, setWeekModeError] = useState<string | null>(null)
  const [view, setView] = useState<DemandPolicyView | null>(null)
  const [draft, setDraft] = useState<CoverageDraft | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [weekOpen, setWeekOpen] = useState(false)
  const inFlight = useRef(false)
  const outcomeRef = useRef<HTMLParagraphElement>(null)

  // The outcome of "Enregistrer" is written below the form: on a phone it would sit below the fold,
  // unseen (390 px check, D224) — bring it into view.
  useEffect(() => {
    if (notice || saveError) outcomeRef.current?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
  }, [notice, saveError])
  const sourceId = useId()

  // Only the latest load counts: a late response must never overwrite what has been ticked since
  // (found at 390 px, D224 — the box unticked itself seconds after the tap).
  const latestLoad = useRef(0)
  const load = useCallback(
    (keepDraft: boolean) => {
      const request = ++latestLoad.current
      fetchDemandPolicy(line.stableId)
        .then((value) => {
          if (request !== latestLoad.current) return
          setView(value)
          setDraft((current) => (keepDraft && current ? current : draftFromView(value)))
          setLoadError(null)
        })
        .catch(() => {
          if (request === latestLoad.current) {
            setLoadError('Impossible de charger la configuration de cette ligne.')
          }
        })
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
  // A reinforcement recorded before D224 kept its own structure: still shown, still editable. Never offered otherwise.
  const legacyOwnStructure =
    view?.mode === 'CONDITIONAL_ON_SOURCE_ASSIGNMENT' && view.structureSource === 'OWN'
  const ownStructure = !conditional || legacyOwnStructure
  const source = view?.sourceOptions.find((option) => option.lineStableId === draft?.sourceLineStableId)
  const sourceExcluded = sortWeekdays(source?.structure?.excludedWeekdays ?? [])

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
          {ownStructure && (
            <section className="cov-section" aria-labelledby={`${sourceId}-week`}>
              <h3 id={`${sourceId}-week`}>Semaine type</h3>
              <span className="cov-help">Quels jours et quels blocs de garde existent ?</span>
              <fieldset className="cov-modes" aria-label="Semaine type utilisée">
                {(['GENERAL', 'CUSTOM'] as const).map((mode) => (
                  <label key={mode} className="cov-mode">
                    <input
                      type="radio"
                      name={`${sourceId}-week-mode`}
                      checked={weekMode === mode}
                      disabled={saving}
                      onChange={() => {
                        setWeekModeError(null)
                        saveWeekStructureMode(line.stableId, mode)
                          .then(() => {
                            setWeekMode(mode)
                            onSaved?.()
                            load(true)
                          })
                          .catch(() =>
                            setWeekModeError(
                              'Ce choix n’a pas pu être enregistré : la semaine type générale couperait un bloc à la limite entre deux périodes.',
                            ),
                          )
                      }}
                    />
                    <span>
                      <strong>
                        {mode === 'GENERAL'
                          ? 'Semaine type générale du planning'
                          : 'Semaine type personnalisée'}
                      </strong>
                      <span className="cov-help">
                        {mode === 'GENERAL'
                          ? 'Commune aux lignes qui l’utilisent ; se modifie dans le menu « ⋯ » du planning.'
                          : 'Propre à cette ligne. Elle ne suit plus la semaine type générale.'}
                      </span>
                    </span>
                  </label>
                ))}
              </fieldset>
              {weekModeError && (
                <p role="alert" className="alert alert--error">
                  <Icon name="alert" size={18} strokeWidth={2} />
                  <span>{weekModeError}</span>
                </p>
              )}
              <p className="cov-help">
                {!view.targetStructure.configured
                  ? 'Aucune semaine type n’est encore configurée pour cette ligne.'
                  : excluded.length === 0
                    ? 'Une garde chaque jour de la semaine.'
                    : `Pas de garde le ${excluded.map(weekdayLabel).join(', ')}.`}
              </p>
              {weekMode === 'CUSTOM' && (
                <div>
                  <button
                    type="button"
                    className="btn btn--secondary btn--sm"
                    onClick={() => setWeekOpen(true)}
                    disabled={saving}
                  >
                    <Icon name="calendar" size={16} strokeWidth={2} />
                    Modifier la semaine type
                  </button>
                </div>
              )}
            </section>
          )}

          <CoverageStartSection lineStableId={line.stableId} onSaved={onSaved} />

          {line.type === 'PRIMARY' ? (
            <section className="cov-section">
              <h3>Règles de couverture</h3>
              <span className="cov-help">Quand une garde de cette ligne est-elle nécessaire ?</span>
              <p className="cov-help">
                La ligne principale est toujours une garde indépendante : ses gardes sont à couvrir chaque
                jour de sa semaine type.
              </p>
            </section>
          ) : (
            <>
              <section className="cov-section">
                <fieldset className="cov-modes">
                  <legend>
                    <h3>Règles de couverture</h3>
                    <span className="cov-help">Quand une garde de cette ligne est-elle nécessaire ?</span>
                  </legend>
                  <label className="cov-mode">
                    <input
                      type="radio"
                      name={`${sourceId}-mode`}
                      checked={!conditional}
                      disabled={saving}
                      onChange={() => setDraft((current) => current && { ...current, mode: 'INDEPENDENT' })}
                    />
                    <span>
                      <strong>Couverture indépendante</strong>
                      <span className="cov-help">
                        Une garde à couvrir chaque jour de sa propre semaine type.
                      </span>
                    </span>
                  </label>
                  <label className="cov-mode">
                    <input
                      type="radio"
                      name={`${sourceId}-mode`}
                      checked={conditional}
                      disabled={saving}
                      onChange={() =>
                        setDraft(
                          (current) => current && { ...current, mode: 'CONDITIONAL_ON_SOURCE_ASSIGNMENT' },
                        )
                      }
                    />
                    <span>
                      <strong>Renfort conditionnel</strong>
                      <span className="cov-help">
                        Une garde n’est nécessaire que certains jours, selon le chirurgien de garde sur une
                        autre ligne. Les horaires et les blocs viennent de cette autre ligne.
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
                    <p className="cov-help">
                      Aucune autre ligne de ce planning ne peut être renforcée pour le moment.
                    </p>
                  ) : (
                    <select
                      id={`${sourceId}-source`}
                      className="cov-select"
                      value={draft.sourceLineStableId ?? ''}
                      disabled={saving}
                      onChange={(event) => {
                        const sourceLineStableId = event.target.value || null
                        setDraft((current) => current && { ...current, sourceLineStableId })
                      }}
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
                      {source?.structure && !legacyOwnStructure && (
                        <InheritedStructurePanel sourceName={source.name} structure={source.structure} />
                      )}
                      <h4 className="cov-matrix-title">
                        Chirurgiens de {source?.name ?? 'la ligne source'} qui déclenchent un renfort
                      </h4>
                      <p className="cov-help">
                        Cochez, pour chaque chirurgien, les jours où sa garde sur{' '}
                        {source?.name ?? 'la ligne source'} nécessite un renfort {line.name}. Les membres de{' '}
                        {line.name} sont ceux qui assurent ces renforts (panneau « Membres ») : ils ne
                        figurent pas ici.
                      </p>
                      <CoverageMatrix
                        rows={matrixRows(view, draft)}
                        selection={draft.selection}
                        excludedWeekdays={legacyOwnStructure ? excluded : sourceExcluded}
                        disabled={saving}
                        // Functional updates only (docs/decisions.md D167): never a stale draft overwriting a change.
                        onChange={(update) =>
                          setDraft(
                            (current) => current && { ...current, selection: update(current.selection) },
                          )
                        }
                      />
                      <TriggerSummary
                        rows={matrixRows(view, draft)}
                        selection={draft.selection}
                        lineName={line.name}
                        sourceName={source?.name ?? 'la ligne source'}
                      />
                      <DemandPreviewPanel
                        lineStableId={line.stableId}
                        input={toInput(draft)}
                        disabled={saving}
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
              <p className="cov-help">
                Selon la configuration enregistrée — ces points n’empêchent pas l’enregistrement.
              </p>
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
          <GenerationRulesPanel lineStableId={line.stableId} timezone={timezone} onActivated={onSaved} />
        </div>
      )}

      {notice && (
        <p ref={outcomeRef} role="status" className="alert alert--success">
          <Icon name="check" size={18} strokeWidth={2} />
          <span>{notice}</span>
        </p>
      )}
      {saveError && (
        <p ref={outcomeRef} role="alert" className="alert alert--error">
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
    'Des gardes de cette ligne existent déjà sur une période pas encore générée : son mode de couverture et sa ligne à renforcer ne peuvent plus changer pour cette période. Les jours de chaque chirurgien restent modifiables.',
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
