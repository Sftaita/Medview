import { useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Icon } from '../components/Icon'
import { AvailabilityCalendar, CalendarLegend } from '../features/availability/AvailabilityCalendar'
import { TYPE_LABEL } from '../features/availability/labels'
import {
  buildMonths,
  dayIndexOfDate,
  monthIndexOfDay,
  type MonthInfo,
} from '../features/availability/calendarAxis'
import { CollectionCallout } from '../features/availability/CollectionCallout'
import { collectionDays } from '../features/availability/collectionFormat'
import type { AvailabilityCollection } from '../features/availability/collectionTypes'
import { countDays, type DayRange } from '../features/availability/selection'
import { SelectionSummary } from '../features/availability/SelectionSummary'
import type { UserAvailabilityType } from '../features/availability/types'
import { useDaySelection } from '../features/availability/useDaySelection'
import { useMyAvailability } from '../features/availability/useMyAvailability'
import { useVisibleMonths } from '../features/availability/useVisibleMonths'

/** Months offered ahead of the current one. */
const MONTHS_AHEAD = 18

/** How long "Tout effacer" waits for its confirming second click. */
const CONFIRM_CLEAR_MS = 4000

/** The month rail always covers the current window, every run already saved, and the collection being answered. */
function buildAxis(
  ranges: DayRange[],
  today: Date,
  extra: { start: number; end: number } | null,
): MonthInfo[] {
  const monthOf = (dayIndex: number) => {
    const date = new Date(dayIndex * 86_400_000)
    return date.getUTCFullYear() * 12 + date.getUTCMonth()
  }
  let first = today.getFullYear() * 12 + today.getMonth()
  let last = first + MONTHS_AHEAD - 1

  for (const range of ranges) {
    first = Math.min(first, monthOf(range.start))
    last = Math.max(last, monthOf(range.end))
  }
  if (extra) {
    first = Math.min(first, monthOf(extra.start))
    last = Math.max(last, monthOf(extra.end))
  }

  return buildMonths(Math.floor(first / 12), first % 12, last - first + 1)
}

const NATURE_HELP: Record<UserAvailabilityType, string> = {
  UNAVAILABLE: 'Les dates touchées seront déclarées comme non travaillables.',
  PREFER_DUTY: 'Les dates touchées seront proposées en priorité pour une garde.',
}

export function MyAvailabilityPage() {
  const { ranges, loadError, reload, collections } = useMyAvailability()
  const [params] = useSearchParams()
  const collectionId = params.get('collection')

  // Show what the shared store already knows at once, and revalidate in the background.
  useEffect(() => {
    void reload()
  }, [reload])

  const collection = collectionId ? (collections?.find((c) => c.stableId === collectionId) ?? null) : null
  // The calendar must not be built before we know which window to open on.
  const waitingForCollection = collectionId !== null && collections === null

  return (
    <section className="page availability availability__page">
      <header className="page__header">
        <div>
          <div className="eyebrow">Calendrier personnel</div>
          <h1>Mes indisponibilités</h1>
          <p className="page__lead">
            Indiquez les jours où vous êtes indisponible ou préférez être de garde. Chaque modification est
            enregistrée aussitôt et partagée par toutes vos équipes.
          </p>
        </div>
      </header>

      {(ranges === null || waitingForCollection) && !loadError && (
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
      {collectionId !== null && collections !== null && collection === null && (
        <p role="status" className="alert alert--info">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>Cette collecte n&apos;est plus ouverte ou ne vous concerne pas.</span>
        </p>
      )}
      {ranges !== null && !waitingForCollection && (
        <AvailabilityEditor key={collection?.stableId ?? 'free'} collection={collection} />
      )}
    </section>
  )
}

function AvailabilityEditor({ collection }: { collection: AvailabilityCollection | null }) {
  const {
    ranges: storedRanges,
    editRanges,
    syncing,
    syncError,
    dismissSyncError,
    lastSavedAt,
  } = useMyAvailability()
  const ranges = storedRanges ?? []

  const today = useMemo(() => new Date(), [])
  const todayIndex = dayIndexOfDate(today)
  const windowDays = useMemo(() => (collection ? collectionDays(collection) : null), [collection])
  // The axis is fixed for the life of the editor, so an edit never moves the rail under the user.
  const [months] = useState(() => buildAxis(ranges, today, windowDays))
  const visible = useVisibleMonths()

  const selection = useDaySelection({
    months,
    visible,
    initialPage: Math.max(0, monthIndexOfDay(months, windowDays ? windowDays.start : todayIndex)),
    ranges,
    setRanges: editRanges,
  })

  const days = countDays(ranges)

  // "Tout effacer" deletes everything at once and there is no undo: ask for a second click.
  const [confirmingClear, setConfirmingClear] = useState(false)
  const clearTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  useEffect(
    () => () => {
      if (clearTimer.current) clearTimeout(clearTimer.current)
    },
    [],
  )
  function handleClear() {
    if (!confirmingClear) {
      setConfirmingClear(true)
      clearTimer.current = setTimeout(() => setConfirmingClear(false), CONFIRM_CLEAR_MS)
      return
    }
    if (clearTimer.current) clearTimeout(clearTimer.current)
    setConfirmingClear(false)
    selection.clear()
  }

  return (
    <>
      {collection && <CollectionCallout collection={collection} mode="calendar" />}

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
            highlight={windowDays}
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
          <SelectionSummary ranges={ranges} activeType={selection.type} onRemove={selection.removeRange} />

          <div className="availability__actions">
            <button
              type="button"
              className="btn btn--ghost btn--sm"
              onClick={handleClear}
              disabled={ranges.length === 0}
            >
              {confirmingClear ? 'Confirmer : tout effacer' : 'Tout effacer'}
            </button>
          </div>

          <div aria-live="polite" className="availability__status">
            {syncing && <p className="muted">Enregistrement…</p>}
            {!syncing && !syncError && lastSavedAt !== null && (
              <p role="status" className="availability__saved">
                <Icon name="check" size={16} strokeWidth={2} />
                Enregistré automatiquement · {days} jour(s)
              </p>
            )}
          </div>
          {syncError && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{syncError}</span>
              <button type="button" className="btn btn--ghost btn--sm" onClick={dismissSyncError}>
                Fermer
              </button>
            </p>
          )}
        </div>
      </div>
    </>
  )
}
