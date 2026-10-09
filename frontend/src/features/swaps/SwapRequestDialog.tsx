import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '../../components/Icon'
import { Overlay } from '../../components/Overlay'
import { createSwapRequest, fetchSwapOptions, swapErrorMessage } from './api'
import { personName, unitLabel } from './labels'
import { ResponsibilityNotice } from './ResponsibilityNotice'
import type { SwapOptions, SwapRequestDetail } from './types'

type Mode = 'AGREED' | 'SEARCH'
type Audience = 'ONE' | 'SEVERAL' | 'ALL'

/**
 * "Échanger ma garde" (docs/duty-swaps.md §9): either an exchange already agreed with a colleague (their duty
 * chosen here, they accept or refuse), or a search — one colleague, several, or the whole line, who then propose
 * one of their own duties. Nothing is sent before the member confirms the responsibility warning, and nothing in
 * the planning changes when it is: the server records a request, never a transfer.
 */
export function SwapRequestDialog({
  dutyStableId,
  onClose,
  onCreated,
}: {
  dutyStableId: string
  onClose: () => void
  onCreated: (request: SwapRequestDetail) => void
}) {
  const [options, setOptions] = useState<SwapOptions | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [mode, setMode] = useState<Mode | null>(null)
  const [audience, setAudience] = useState<Audience>('ONE')
  const [colleague, setColleague] = useState<string>('')
  const [counterpartDuty, setCounterpartDuty] = useState<string>('')
  const [selected, setSelected] = useState<string[]>([])
  const [acknowledged, setAcknowledged] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const inFlight = useRef(false)
  const errorRef = useRef<HTMLParagraphElement>(null)

  // The refusal is written below the form: bring it into view, or it may go unseen in a long dialog.
  useEffect(() => {
    if (error) errorRef.current?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
  }, [error])

  useEffect(() => {
    let cancelled = false
    fetchSwapOptions(dutyStableId)
      .then((value) => {
        if (!cancelled) setOptions(value)
      })
      .catch((e: unknown) => {
        if (!cancelled) setLoadError(swapErrorMessage(e, 'Impossible de préparer l’échange de cette garde.'))
      })
    return () => {
      cancelled = true
    }
  }, [dutyStableId])

  const chosenColleague = useMemo(
    () => options?.colleagues.find((c) => c.userStableId === colleague) ?? null,
    [options, colleague],
  )
  const chosenUnit = chosenColleague?.units.find((u) => u.dutyStableId === counterpartDuty) ?? null

  const recipients =
    mode === 'AGREED'
      ? colleague
        ? [colleague]
        : []
      : audience === 'ALL'
        ? []
        : audience === 'ONE'
          ? colleague
            ? [colleague]
            : []
          : selected
  const ready =
    mode !== null &&
    acknowledged &&
    (mode === 'AGREED' ? chosenUnit !== null : audience === 'ALL' || recipients.length > 0)

  async function submit() {
    if (!ready || inFlight.current || !options || mode === null) return
    inFlight.current = true
    setSubmitting(true)
    setError(null)
    try {
      const created = await createSwapRequest({
        dutyStableId: options.offered.dutyStableId,
        kind: mode,
        audience: mode === 'SEARCH' && audience === 'ALL' ? 'ALL' : 'SELECTED',
        recipientUserStableIds: recipients,
        ...(mode === 'AGREED' ? { counterpartDutyStableId: counterpartDuty } : {}),
        acknowledgedResponsibility: acknowledged,
      })
      onCreated(created)
    } catch (e) {
      setError(swapErrorMessage(e, 'La demande n’a pas pu être envoyée.'))
    } finally {
      inFlight.current = false
      setSubmitting(false)
    }
  }

  const footer =
    options && !options.openRequestStableId ? (
      <>
        <button type="button" className="btn btn--ghost" onClick={onClose} disabled={submitting}>
          Annuler
        </button>
        <button type="button" className="btn btn--primary" onClick={submit} disabled={!ready || submitting}>
          {submitting ? 'Envoi…' : mode === 'AGREED' ? 'Envoyer la proposition' : 'Envoyer la demande'}
        </button>
      </>
    ) : undefined

  return (
    <Overlay
      title="Échanger ma garde"
      onClose={onClose}
      dismissible={!submitting}
      footer={footer}
      stableLayout
    >
      {!options && !loadError && (
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

      {options && (
        <div className="swap-form">
          <div className="swap-unit-card">
            <span className="swap-unit-card__label">Votre garde</span>
            <strong>{unitLabel(options.offered)}</strong>
            <span className="muted">
              {options.offered.planningName} · {options.offered.lineName}
            </span>
          </div>

          {options.openRequestStableId ? (
            <div className="alert alert--info">
              <Icon name="alert" size={18} strokeWidth={2} />
              <div className="alert__body">
                <p>Une demande d’échange est déjà en cours pour cette garde.</p>
                <p>
                  <Link to={`/swaps?request=${options.openRequestStableId}`} onClick={onClose}>
                    Voir la demande
                  </Link>
                </p>
              </div>
            </div>
          ) : (
            <>
              <fieldset className="swap-fieldset" disabled={submitting}>
                <legend>Votre situation</legend>
                <label className="checkbox">
                  <input
                    type="radio"
                    name="swap-mode"
                    checked={mode === 'AGREED'}
                    onChange={() => setMode('AGREED')}
                  />
                  <span>
                    <strong>J’ai déjà convenu d’un échange</strong>
                    <small>Choisissez le collègue et sa garde : il accepte ou refuse dans MedVue.</small>
                  </span>
                </label>
                <label className="checkbox">
                  <input
                    type="radio"
                    name="swap-mode"
                    checked={mode === 'SEARCH'}
                    onChange={() => setMode('SEARCH')}
                  />
                  <span>
                    <strong>Je cherche quelqu’un avec qui échanger</strong>
                    <small>Vos collègues vous proposent une de leurs gardes ; vous choisissez.</small>
                  </span>
                </label>
              </fieldset>

              {mode === 'AGREED' && (
                <fieldset className="swap-fieldset" disabled={submitting}>
                  <legend>Avec qui ?</legend>
                  <label className="field">
                    <span className="field__label">Collègue</span>
                    <select
                      className="field__input"
                      value={colleague}
                      onChange={(e) => {
                        setColleague(e.target.value)
                        setCounterpartDuty('')
                      }}
                    >
                      <option value="">Choisir un collègue…</option>
                      {options.colleagues.map((c) => (
                        <option key={c.userStableId} value={c.userStableId}>
                          {personName(c)}
                        </option>
                      ))}
                    </select>
                  </label>
                  {chosenColleague && chosenColleague.units.length === 0 && (
                    <p className="muted">
                      {personName(chosenColleague)} n’a aucune garde à venir sur cette ligne.
                    </p>
                  )}
                  {chosenColleague && chosenColleague.units.length > 0 && (
                    <div
                      className="swap-choices"
                      role="radiogroup"
                      aria-label={`Gardes de ${personName(chosenColleague)}`}
                    >
                      {chosenColleague.units.map((unit) => (
                        <label key={unit.dutyStableId} className="checkbox">
                          <input
                            type="radio"
                            name="swap-counterpart"
                            checked={counterpartDuty === unit.dutyStableId}
                            onChange={() => setCounterpartDuty(unit.dutyStableId)}
                          />
                          <span>
                            <strong>{unitLabel(unit)}</strong>
                          </span>
                        </label>
                      ))}
                    </div>
                  )}
                  {chosenColleague && chosenUnit && (
                    <div className="swap-summary" aria-label="Nouvelle répartition proposée">
                      <p>
                        <Icon name="arrow" size={16} strokeWidth={2} /> Vous assureriez{' '}
                        <strong>{unitLabel(chosenUnit)}</strong>
                      </p>
                      <p>
                        <Icon name="arrow" size={16} strokeWidth={2} /> {personName(chosenColleague)}{' '}
                        assurerait <strong>{unitLabel(options.offered)}</strong>
                      </p>
                      <p className="muted">Seulement après son acceptation et la confirmation par MedVue.</p>
                    </div>
                  )}
                </fieldset>
              )}

              {mode === 'SEARCH' && (
                <fieldset className="swap-fieldset" disabled={submitting}>
                  <legend>À qui envoyer la demande ?</legend>
                  <div role="group" aria-label="Destinataires" className="segmented swap-segmented">
                    {(
                      [
                        ['ONE', 'Un collègue'],
                        ['SEVERAL', 'Plusieurs collègues'],
                        ['ALL', 'Toute l’équipe'],
                      ] as const
                    ).map(([value, label]) => (
                      <button
                        key={value}
                        type="button"
                        className="segmented__option"
                        aria-pressed={audience === value}
                        onClick={() => setAudience(value)}
                      >
                        {label}
                      </button>
                    ))}
                  </div>
                  {audience === 'ONE' && (
                    <label className="field">
                      <span className="field__label">Collègue</span>
                      <select
                        className="field__input"
                        value={colleague}
                        onChange={(e) => setColleague(e.target.value)}
                      >
                        <option value="">Choisir un collègue…</option>
                        {options.colleagues.map((c) => (
                          <option key={c.userStableId} value={c.userStableId}>
                            {personName(c)}
                          </option>
                        ))}
                      </select>
                    </label>
                  )}
                  {audience === 'SEVERAL' && (
                    <div className="swap-choices" role="group" aria-label="Collègues">
                      {options.colleagues.map((c) => (
                        <label key={c.userStableId} className="checkbox">
                          <input
                            type="checkbox"
                            checked={selected.includes(c.userStableId)}
                            onChange={(e) =>
                              setSelected((current) =>
                                e.target.checked
                                  ? [...current, c.userStableId]
                                  : current.filter((id) => id !== c.userStableId),
                              )
                            }
                          />
                          <span>
                            <strong>{personName(c)}</strong>
                          </span>
                        </label>
                      ))}
                    </div>
                  )}
                  {audience === 'ALL' && (
                    <p className="muted">
                      Tous les membres de la ligne {options.offered.lineName} verront votre demande et
                      pourront vous proposer une de leurs gardes.
                    </p>
                  )}
                </fieldset>
              )}

              {mode !== null && (
                <>
                  <ResponsibilityNotice />
                  <label className="checkbox swap-ack">
                    <input
                      type="checkbox"
                      checked={acknowledged}
                      disabled={submitting}
                      onChange={(e) => setAcknowledged(e.target.checked)}
                    />
                    <span>
                      <strong>J’ai compris</strong>
                      <small>
                        Je reste responsable de ma garde tant que l’échange n’est pas confirmé dans MedVue.
                      </small>
                    </span>
                  </label>
                </>
              )}

              {error && (
                <p ref={errorRef} role="alert" className="alert alert--error">
                  <Icon name="alert" size={18} strokeWidth={2} />
                  <span>{error}</span>
                </p>
              )}
            </>
          )}
        </div>
      )}
    </Overlay>
  )
}
