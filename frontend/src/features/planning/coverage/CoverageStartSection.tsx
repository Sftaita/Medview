import { useEffect, useId, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { ApiError } from '../../../lib/apiClient'
import { datesLabel } from '../calendar/preflightIssues'
import { fetchCoverageStart, previewCoverageStart, saveCoverageStart } from './api'
import type { CoverageStartPlan } from './types'

type Props = {
  lineStableId: string
  /** Something was saved: the caller may refresh what depends on it. */
  onSaved?: () => void
}

const FULL_DATE = new Intl.DateTimeFormat('fr-BE', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

function fullDate(date: string): string {
  return FULL_DATE.format(new Date(`${date}T00:00:00Z`))
}

function errorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    const code = (err.body as { error?: string } | null)?.error
    if (code === 'coverage_start_confirmation_required')
      return 'Le calendrier a changé depuis cette vérification. Vérifiez à nouveau.'
    if (code === 'job_in_progress') return 'Une génération ou une complétion est en cours. Réessayez ensuite.'
  }
  return 'Cette date n’a pas pu être enregistrée.'
}

/**
 * "Début de couverture de cette ligne" (docs/decisions.md D233). A choice with
 * consequences, saved on its own: the server says what the date would do, the
 * manager confirms exactly that. Nothing is computed here.
 */
export function CoverageStartSection({ lineStableId, onSaved }: Props) {
  const inputId = useId()
  const [saved, setSaved] = useState<string | null | undefined>(undefined)
  const [value, setValue] = useState('')
  const [plan, setPlan] = useState<CoverageStartPlan | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  useEffect(() => {
    let stale = false
    fetchCoverageStart(lineStableId)
      .then((current) => {
        if (stale) return
        setSaved(current.coverageStartsOn)
        setValue(current.coverageStartsOn ?? '')
      })
      .catch(() => {
        if (!stale) setError('Impossible de lire le début de couverture de cette ligne.')
      })
    return () => {
      stale = true
    }
  }, [lineStableId])

  const wanted = value === '' ? null : value
  const changed = saved !== undefined && wanted !== saved

  async function check() {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      setPlan(await previewCoverageStart(lineStableId, wanted))
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  async function confirm() {
    if (!plan) return
    setBusy(true)
    setError(null)
    try {
      const outcome = await saveCoverageStart(lineStableId, wanted, plan.confirmation)
      setSaved(outcome.coverageStartsOn)
      setPlan(null)
      setNotice(
        outcome.retiredDutyCount > 0
          ? `Date enregistrée. ${outcome.retiredDutyCount === 1 ? '1 garde sans titulaire a été retirée' : `${outcome.retiredDutyCount} gardes sans titulaire ont été retirées`} du planning actif ; la trace est conservée.`
          : 'Date enregistrée.',
      )
      onSaved?.()
    } catch (err) {
      setPlan(null)
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="cov-section" aria-labelledby={`${inputId}-title`}>
      <h3 id={`${inputId}-title`}>Début de couverture de cette ligne</h3>
      <span className="cov-help">
        Cette date détermine à partir de quand des gardes de cette ligne sont nécessaires, indépendamment de
        l’inscription des membres. Avant elle, aucune garde n’est demandée ni comptée comme non pourvue. Sans
        date, la ligne est couverte dès le début de chaque période.
      </span>
      <div className="cov-start__row">
        <label htmlFor={inputId}>Date de début</label>
        <input
          id={inputId}
          type="date"
          className="mv-input"
          value={value}
          disabled={busy || saved === undefined}
          onChange={(event) => {
            setValue(event.target.value)
            setPlan(null)
            setNotice(null)
            setError(null)
          }}
        />
        {value !== '' && (
          <button
            type="button"
            className="btn btn--secondary btn--sm"
            disabled={busy}
            onClick={() => {
              setValue('')
              setPlan(null)
              setNotice(null)
            }}
          >
            Sans date
          </button>
        )}
        {changed && !plan && (
          <button
            type="button"
            className="btn btn--secondary btn--sm"
            disabled={busy}
            onClick={() => void check()}
          >
            Vérifier l’effet
          </button>
        )}
      </div>

      {plan && (
        <div className="cov-start__plan" role="status">
          <strong>{wanted ? `À partir du ${fullDate(wanted)}` : 'Sans date de début'}</strong>
          {plan.retired.length > 0 && (
            <div>
              <span>
                {plan.retired.length === 1
                  ? '1 garde sans titulaire sera retirée du planning actif'
                  : `${plan.retired.length} gardes sans titulaire seront retirées du planning actif`}{' '}
                (la trace est conservée) :
              </span>
              <ul>
                {plan.retired.map((duty) => (
                  <li key={duty.dutyStableId}>{datesLabel([duty.date])}</li>
                ))}
              </ul>
            </div>
          )}
          {plan.kept.length > 0 && (
            <div>
              <span>
                {plan.kept.length === 1
                  ? '1 garde avec titulaire reste telle quelle'
                  : `${plan.kept.length} gardes avec titulaire restent telles quelles`}{' '}
                (jamais retirée ni réattribuée, mais plus requise) :
              </span>
              <ul>
                {plan.kept.map((duty) => (
                  <li key={duty.dutyStableId}>
                    {datesLabel([duty.date])} — {duty.holder.firstName} {duty.holder.lastName}
                  </li>
                ))}
              </ul>
            </div>
          )}
          {plan.retired.length === 0 && plan.kept.length === 0 && (
            <span>Aucune garde existante n’est touchée.</span>
          )}
          <span className="cov-help">
            Aucune date d’adhésion n’est modifiée et aucun e-mail n’est envoyé.
          </span>
          <div className="cov-start__actions">
            <button
              type="button"
              className="btn btn--secondary btn--sm"
              disabled={busy}
              onClick={() => setPlan(null)}
            >
              Annuler
            </button>
            <button
              type="button"
              className="btn btn--primary btn--sm"
              disabled={busy}
              aria-busy={busy}
              onClick={() => void confirm()}
            >
              Confirmer
            </button>
          </div>
        </div>
      )}

      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>{error}</span>
        </p>
      )}
      {notice && (
        <p role="status" className="alert alert--success">
          <span>{notice}</span>
        </p>
      )}
    </section>
  )
}
