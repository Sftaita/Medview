import { useEffect, useMemo, useRef, useState } from 'react'
import { Icon } from '../components/Icon'
import { AvailabilityCalendar, CalendarLegend } from '../features/availability/AvailabilityCalendar'
import { TYPE_LABEL } from '../features/availability/labels'
import { createCalendarPeriod, deleteCalendarPeriod, fetchMyCalendar } from '../features/availability/api'
import {
  buildMonths,
  dayIndexOfDate,
  monthIndexOfDay,
  type MonthInfo,
} from '../features/availability/calendarAxis'
import { periodsToRanges, planSave, rangeToInput } from '../features/availability/periodMapping'
import { countDays, type DayRange } from '../features/availability/selection'
import { SelectionSummary } from '../features/availability/SelectionSummary'
import type { UserAvailabilityPeriod, UserAvailabilityType } from '../features/availability/types'
import { useDaySelection } from '../features/availability/useDaySelection'
import { useVisibleMonths } from '../features/availability/useVisibleMonths'
import { ApiError } from '../lib/apiClient'

/** Months offered ahead of the current one. */
const MONTHS_AHEAD = 18

/** The month rail always covers the current window *and* every stored period. */
function buildAxis(periods: UserAvailabilityPeriod[], today: Date): MonthInfo[] {
  let first = today.getFullYear() * 12 + today.getMonth()
  let last = first + MONTHS_AHEAD - 1

  for (const period of periods) {
    const start = new Date(period.startsAt)
    const end = new Date(new Date(period.endsAt).getTime() - 1)
    first = Math.min(first, start.getFullYear() * 12 + start.getMonth())
    last = Math.max(last, end.getFullYear() * 12 + end.getMonth())
  }

  return buildMonths(Math.floor(first / 12), first % 12, last - first + 1)
}

function saveErrorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 409) {
    return 'Une période de même type chevauche ou touche déjà ces dates.'
  }
  if (err instanceof ApiError && err.status === 422) {
    return 'Cette période est invalide.'
  }
  return 'Une erreur est survenue. Merci de réessayer.'
}

const NATURE_HELP: Record<UserAvailabilityType, string> = {
  UNAVAILABLE: 'Les dates touchées seront déclarées comme non travaillables.',
  PREFER_DUTY: 'Les dates touchées seront proposées en priorité pour une garde.',
}

export function MyAvailabilityPage() {
  const [periods, setPeriods] = useState<UserAvailabilityPeriod[] | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    fetchMyCalendar()
      .then((result) => {
        if (!cancelled) setPeriods(result)
      })
      .catch(() => {
        if (!cancelled) setLoadError('Impossible de charger votre calendrier.')
      })
    return () => {
      cancelled = true
    }
  }, [])

  return (
    <section className="page availability availability__page">
      <header className="page__header">
        <div>
          <div className="eyebrow">Calendrier personnel</div>
          <h1>Mes indisponibilités</h1>
          <p className="page__lead">
            Indiquez les jours où vous êtes indisponible ou préférez être de garde. Ces informations sont
            partagées par toutes vos équipes.
          </p>
        </div>
      </header>

      {periods === null && !loadError && (
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
      {periods !== null && <AvailabilityEditor initialPeriods={periods} />}
    </section>
  )
}

function AvailabilityEditor({ initialPeriods }: { initialPeriods: UserAvailabilityPeriod[] }) {
  const today = useMemo(() => new Date(), [])
  const todayIndex = dayIndexOfDate(today)
  // The axis is fixed for the life of the editor, so a save never moves the rail under the user.
  const months = useMemo(() => buildAxis(initialPeriods, today), [initialPeriods, today])
  const visible = useVisibleMonths()
  const initialRanges = useMemo(() => periodsToRanges(initialPeriods), [initialPeriods])

  const selection = useDaySelection({
    months,
    visible,
    initialPage: Math.max(0, monthIndexOfDay(months, todayIndex)),
    initialRanges,
  })

  const [stored, setStored] = useState(initialPeriods)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // The message is tied to the selection it was shown for: any later edit hides it.
  const [savedNotice, setSavedNotice] = useState<{ message: string; ranges: DayRange[] } | null>(null)
  // Same reason as the login form: `disabled` alone does not stop a fast double click.
  const savingRef = useRef(false)

  const plan = useMemo(() => planSave(stored, selection.ranges), [stored, selection.ranges])
  const dirty = plan.toDelete.length + plan.toCreate.length > 0
  const days = countDays(selection.ranges)

  const notice = savedNotice && savedNotice.ranges === selection.ranges ? savedNotice.message : null

  async function handleSave() {
    if (savingRef.current || !dirty) {
      return
    }
    savingRef.current = true
    setSaving(true)
    setError(null)
    setSavedNotice(null)
    const savedRanges = selection.ranges

    try {
      // Deletions first: the backend refuses same-type periods that overlap or touch.
      for (const period of plan.toDelete) {
        await deleteCalendarPeriod(period.stableId)
      }
      for (const range of plan.toCreate) {
        await createCalendarPeriod(rangeToInput(range))
      }
      setStored(await fetchMyCalendar())
      setSavedNotice({ message: 'Modifications enregistrées.', ranges: savedRanges })
    } catch (err) {
      setError(saveErrorMessage(err))
      // Some calls may have gone through: show the truth on the next save.
      fetchMyCalendar()
        .then(setStored)
        .catch(() => undefined)
    } finally {
      savingRef.current = false
      setSaving(false)
    }
  }

  return (
    <>
      <div className={`nature${selection.type === 'PREFER_DUTY' ? ' nature--prefer' : ''}`}>
        <div role="group" aria-label="Nature de la sélection" className="nature__group">
          {(['UNAVAILABLE', 'PREFER_DUTY'] as const).map((type) => (
            <button
              key={type}
              type="button"
              aria-pressed={selection.type === type}
              className={`nature__option nature__option--${type === 'UNAVAILABLE' ? 'unavailable' : 'prefer'}`}
              onClick={() => selection.setType(type)}
            >
              <span className="nature__dot" />
              {TYPE_LABEL[type]}
            </button>
          ))}
        </div>
        <p className="nature__help">{NATURE_HELP[selection.type]}</p>
      </div>

      <div className="availability__layout">
        <div className="card cal-card">
          <AvailabilityCalendar
            months={months}
            page={selection.page}
            visible={visible}
            ranges={selection.effectiveRanges}
            drag={selection.drag}
            todayIndex={todayIndex}
            onPrevious={() => selection.setPage(selection.page - 1)}
            onNext={() => selection.setPage(selection.page + 1)}
            onDayPointerDown={selection.startDrag}
            onGridPointerMove={selection.trackPointer}
            onRailPointerMove={selection.trackRailPointer}
            onDayKeyboardToggle={selection.toggleFromKeyboard}
          />
          <CalendarLegend />
          <p className="cal__help cal__help--mobile">
            Touchez une date pour l&apos;ajouter ou la retirer · Glissez pour tracer une période · Glissez
            jusqu&apos;au bord pour passer au mois suivant
          </p>
          <p className="cal__help cal__help--desktop">
            Clic : ajouter ou retirer une journée · Clic-glisser : période continue — chaque glisser crée une
            période <em>supplémentaire</em> · En glissant au-delà du dernier jour affiché, le calendrier
            défile vers le mois suivant · Ctrl/Cmd + clic : retirer · Maj + clic : étendre la dernière plage
          </p>
        </div>

        <div className="availability__aside">
          <SelectionSummary
            ranges={selection.ranges}
            activeType={selection.type}
            onRemove={selection.removeRange}
          />

          <div className="availability__actions">
            <button
              type="button"
              className="btn btn--ghost btn--sm"
              onClick={selection.clear}
              disabled={saving || selection.ranges.length === 0}
            >
              Tout effacer
            </button>
            <button
              type="button"
              className="btn btn--primary"
              onClick={handleSave}
              disabled={saving || !dirty}
            >
              {saving ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </div>

          <div aria-live="polite" className="availability__status">
            {dirty && !saving && !error && (
              <p className="muted">Modifications non enregistrées : {days} jour(s) affiché(s).</p>
            )}
            {notice && (
              <p role="status" className="alert alert--success">
                <Icon name="check" size={18} strokeWidth={2} />
                <span>{notice}</span>
              </p>
            )}
          </div>
          {error && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{error}</span>
            </p>
          )}
        </div>
      </div>
    </>
  )
}
