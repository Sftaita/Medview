import { useEffect, useMemo, useState } from 'react'
import {
  createCalendarPeriod,
  deleteCalendarPeriod,
  fetchMyCalendar,
  updateCalendarPeriod,
} from '../features/availability/api'
import {
  groupContiguousDateKeys,
  startOfDay,
  startOfNextDay,
  toDateKey,
} from '../features/availability/dateUtils'
import type {
  UpsertUserAvailabilityPeriodInput,
  UserAvailabilityPeriod,
  UserAvailabilityType,
} from '../features/availability/types'
import { ApiError } from '../lib/apiClient'

const WEEKDAY_LABELS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']

function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1)
}

/** Monday-first 6-week grid covering the whole month, including the leading/trailing days from neighboring months. */
function buildMonthGrid(monthCursor: Date): Date[] {
  const first = startOfMonth(monthCursor)
  const firstWeekday = (first.getDay() + 6) % 7 // 0 = Monday
  const gridStart = new Date(first)
  gridStart.setDate(gridStart.getDate() - firstWeekday)

  return Array.from({ length: 42 }, (_, index) => {
    const day = new Date(gridStart)
    day.setDate(day.getDate() + index)
    return day
  })
}

function periodsByDay(periods: UserAvailabilityPeriod[]): Map<string, UserAvailabilityPeriod[]> {
  const map = new Map<string, UserAvailabilityPeriod[]>()
  for (const period of periods) {
    const start = new Date(period.startsAt)
    const end = new Date(period.endsAt)
    const cursor = new Date(start.getFullYear(), start.getMonth(), start.getDate())
    while (cursor < end) {
      const key = toDateKey(cursor)
      const dayStart = new Date(cursor)
      const dayEnd = new Date(cursor)
      dayEnd.setDate(dayEnd.getDate() + 1)
      if (start < dayEnd && end > dayStart) {
        const existing = map.get(key) ?? []
        existing.push(period)
        map.set(key, existing)
      }
      cursor.setDate(cursor.getDate() + 1)
    }
  }
  return map
}

function toDatetimeLocalValue(iso: string): string {
  const date = new Date(iso)
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

export function MyAvailabilityPage() {
  const [monthCursor, setMonthCursor] = useState(() => startOfMonth(new Date()))
  const [periods, setPeriods] = useState<UserAvailabilityPeriod[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)

  const [selectionMode, setSelectionMode] = useState<'days' | 'range'>('days')
  const [selectedKeys, setSelectedKeys] = useState<Set<string>>(new Set())
  const [rangeAnchor, setRangeAnchor] = useState<string | null>(null)

  const [formType, setFormType] = useState<UserAvailabilityType>('UNAVAILABLE')
  const [useCustomTime, setUseCustomTime] = useState(false)
  const [customStart, setCustomStart] = useState('08:00')
  const [customEnd, setCustomEnd] = useState('18:00')
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const [editingPeriod, setEditingPeriod] = useState<UserAvailabilityPeriod | null>(null)
  const [editType, setEditType] = useState<UserAvailabilityType>('UNAVAILABLE')
  const [editStart, setEditStart] = useState('')
  const [editEnd, setEditEnd] = useState('')
  const [editError, setEditError] = useState<string | null>(null)
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  function loadPeriods() {
    setLoading(true)
    setLoadError(null)
    fetchMyCalendar()
      .then(setPeriods)
      .catch(() => setLoadError('Impossible de charger votre calendrier.'))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadPeriods()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const dayMap = useMemo(() => periodsByDay(periods), [periods])
  const grid = useMemo(() => buildMonthGrid(monthCursor), [monthCursor])

  function clearSelection() {
    setSelectedKeys(new Set())
    setRangeAnchor(null)
    setFormError(null)
  }

  function handleDayClick(day: Date) {
    const key = toDateKey(day)
    const existing = dayMap.get(key)

    if (existing && existing.length > 0) {
      const period = existing[0]
      setEditingPeriod(period)
      setEditType(period.type)
      setEditStart(toDatetimeLocalValue(period.startsAt))
      setEditEnd(toDatetimeLocalValue(period.endsAt))
      setEditError(null)
      setConfirmingDelete(false)
      return
    }

    setEditingPeriod(null)

    if (selectionMode === 'days') {
      setSelectedKeys((previous) => {
        const next = new Set(previous)
        if (next.has(key)) {
          next.delete(key)
        } else {
          next.add(key)
        }
        return next
      })
      return
    }

    // Range mode: first click sets the anchor, second click fills the range.
    if (rangeAnchor === null) {
      setRangeAnchor(key)
      setSelectedKeys(new Set([key]))
      return
    }

    const [from, to] = [rangeAnchor, key].sort()
    const filled = new Set<string>()
    const cursor = startOfDay(from)
    const end = startOfDay(to)
    while (cursor <= end) {
      filled.add(toDateKey(cursor))
      cursor.setDate(cursor.getDate() + 1)
    }
    setSelectedKeys(filled)
    setRangeAnchor(null)
  }

  async function handleCreateSubmit() {
    if (selectedKeys.size === 0) {
      return
    }

    setSaving(true)
    setFormError(null)

    const runs = groupContiguousDateKeys(selectedKeys)
    try {
      for (const run of runs) {
        let input: UpsertUserAvailabilityPeriodInput

        if (useCustomTime && selectedKeys.size === 1) {
          const day = run[0]
          input = {
            type: formType,
            startsAt: new Date(`${day}T${customStart}`).toISOString(),
            endsAt: new Date(`${day}T${customEnd}`).toISOString(),
          }
        } else {
          input = {
            type: formType,
            startsAt: startOfDay(run[0]).toISOString(),
            endsAt: startOfNextDay(run[run.length - 1]).toISOString(),
          }
        }

        await createCalendarPeriod(input)
      }

      clearSelection()
      loadPeriods()
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setFormError('Une période de même type chevauche ou touche déjà ces dates.')
      } else if (err instanceof ApiError && err.status === 422) {
        setFormError('Cette période est invalide.')
      } else {
        setFormError('Une erreur est survenue. Merci de réessayer.')
      }
      loadPeriods()
    } finally {
      setSaving(false)
    }
  }

  async function handleEditSave() {
    if (!editingPeriod) {
      return
    }

    setSaving(true)
    setEditError(null)

    try {
      await updateCalendarPeriod(editingPeriod.stableId, {
        type: editType,
        startsAt: new Date(editStart).toISOString(),
        endsAt: new Date(editEnd).toISOString(),
      })
      setEditingPeriod(null)
      loadPeriods()
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setEditError('Une période de même type chevauche ou touche déjà ces dates.')
      } else if (err instanceof ApiError && err.status === 422) {
        setEditError('Cette période est invalide.')
      } else {
        setEditError('Une erreur est survenue. Merci de réessayer.')
      }
    } finally {
      setSaving(false)
    }
  }

  async function handleDeleteConfirmed() {
    if (!editingPeriod) {
      return
    }

    setSaving(true)
    try {
      await deleteCalendarPeriod(editingPeriod.stableId)
      setEditingPeriod(null)
      setConfirmingDelete(false)
      loadPeriods()
    } catch {
      setEditError('Impossible de supprimer cette période.')
    } finally {
      setSaving(false)
    }
  }

  const monthLabel = monthCursor.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
  const todayKey = toDateKey(new Date())

  return (
    <section className="availability-page">
      <h1>Mes indisponibilités</h1>
      <p>
        Indiquez les dates où vous êtes indisponible ou préférez être de garde. Ces informations sont
        partagées par toutes vos équipes.
      </p>

      <div className="availability-legend">
        <span className="availability-legend-item">
          <span className="availability-dot availability-dot--unavailable" /> Indisponible
        </span>
        <span className="availability-legend-item">
          <span className="availability-dot availability-dot--prefer" /> Préférence de garde
        </span>
      </div>

      <div className="availability-toolbar">
        <div className="availability-month-nav">
          <button
            type="button"
            onClick={() =>
              setMonthCursor((current) => new Date(current.getFullYear(), current.getMonth() - 1, 1))
            }
          >
            ‹
          </button>
          <strong>{monthLabel}</strong>
          <button
            type="button"
            onClick={() =>
              setMonthCursor((current) => new Date(current.getFullYear(), current.getMonth() + 1, 1))
            }
          >
            ›
          </button>
        </div>

        <div className="availability-mode-toggle" role="group" aria-label="Mode de sélection">
          <button
            type="button"
            className={selectionMode === 'days' ? 'is-active' : ''}
            onClick={() => {
              setSelectionMode('days')
              clearSelection()
            }}
          >
            Jours
          </button>
          <button
            type="button"
            className={selectionMode === 'range' ? 'is-active' : ''}
            onClick={() => {
              setSelectionMode('range')
              clearSelection()
            }}
          >
            Plage
          </button>
        </div>
      </div>

      {loading && <p>Chargement…</p>}
      {loadError && (
        <p role="alert" className="availability-error">
          {loadError}
        </p>
      )}

      {!loading && !loadError && (
        <div className="availability-calendar">
          {WEEKDAY_LABELS.map((label) => (
            <div key={label} className="availability-weekday">
              {label}
            </div>
          ))}
          {grid.map((day) => {
            const key = toDateKey(day)
            const inMonth = day.getMonth() === monthCursor.getMonth()
            const dayPeriods = dayMap.get(key) ?? []
            const hasUnavailable = dayPeriods.some((p) => p.type === 'UNAVAILABLE')
            const hasPrefer = dayPeriods.some((p) => p.type === 'PREFER_DUTY')
            const isSelected = selectedKeys.has(key)

            return (
              <button
                key={key}
                type="button"
                onClick={() => handleDayClick(day)}
                className={[
                  'availability-day',
                  inMonth ? '' : 'availability-day--outside',
                  isSelected ? 'availability-day--selected' : '',
                  key === todayKey ? 'availability-day--today' : '',
                ]
                  .filter(Boolean)
                  .join(' ')}
              >
                <span>{day.getDate()}</span>
                <span className="availability-day-dots">
                  {hasUnavailable && <span className="availability-dot availability-dot--unavailable" />}
                  {hasPrefer && <span className="availability-dot availability-dot--prefer" />}
                </span>
              </button>
            )
          })}
        </div>
      )}

      {selectedKeys.size > 0 && !editingPeriod && (
        <div className="availability-form">
          <h2>
            {selectedKeys.size} jour{selectedKeys.size > 1 ? 's' : ''} sélectionné
            {selectedKeys.size > 1 ? 's' : ''}
          </h2>

          <div className="availability-type-choice" role="group" aria-label="Type">
            <label>
              <input
                type="radio"
                name="type"
                checked={formType === 'UNAVAILABLE'}
                onChange={() => setFormType('UNAVAILABLE')}
              />
              Indisponible
            </label>
            <label>
              <input
                type="radio"
                name="type"
                checked={formType === 'PREFER_DUTY'}
                onChange={() => setFormType('PREFER_DUTY')}
              />
              Préférence de garde
            </label>
          </div>

          {selectedKeys.size === 1 && (
            <label className="availability-custom-time-toggle">
              <input
                type="checkbox"
                checked={useCustomTime}
                onChange={(event) => setUseCustomTime(event.target.checked)}
              />
              Préciser une plage horaire
            </label>
          )}

          {useCustomTime && selectedKeys.size === 1 && (
            <div className="availability-time-range">
              <label>
                De
                <input
                  type="time"
                  value={customStart}
                  onChange={(event) => setCustomStart(event.target.value)}
                />
              </label>
              <label>
                À
                <input type="time" value={customEnd} onChange={(event) => setCustomEnd(event.target.value)} />
              </label>
            </div>
          )}

          {formError && (
            <p role="alert" className="availability-error">
              {formError}
            </p>
          )}

          <div className="availability-form-actions">
            <button type="button" onClick={handleCreateSubmit} disabled={saving}>
              Enregistrer
            </button>
            <button type="button" onClick={clearSelection} disabled={saving}>
              Annuler
            </button>
          </div>
        </div>
      )}

      {editingPeriod && (
        <div className="availability-form">
          <h2>Modifier la période</h2>

          <div className="availability-type-choice" role="group" aria-label="Type">
            <label>
              <input
                type="radio"
                name="edit-type"
                checked={editType === 'UNAVAILABLE'}
                onChange={() => setEditType('UNAVAILABLE')}
              />
              Indisponible
            </label>
            <label>
              <input
                type="radio"
                name="edit-type"
                checked={editType === 'PREFER_DUTY'}
                onChange={() => setEditType('PREFER_DUTY')}
              />
              Préférence de garde
            </label>
          </div>

          <div className="availability-time-range">
            <label>
              Début
              <input
                type="datetime-local"
                value={editStart}
                onChange={(event) => setEditStart(event.target.value)}
              />
            </label>
            <label>
              Fin
              <input
                type="datetime-local"
                value={editEnd}
                onChange={(event) => setEditEnd(event.target.value)}
              />
            </label>
          </div>

          {editError && (
            <p role="alert" className="availability-error">
              {editError}
            </p>
          )}

          <div className="availability-form-actions">
            <button type="button" onClick={handleEditSave} disabled={saving}>
              Enregistrer
            </button>
            {!confirmingDelete && (
              <button type="button" onClick={() => setConfirmingDelete(true)} disabled={saving}>
                Supprimer
              </button>
            )}
            {confirmingDelete && (
              <button type="button" onClick={handleDeleteConfirmed} disabled={saving}>
                Confirmer la suppression
              </button>
            )}
            <button
              type="button"
              onClick={() => {
                setEditingPeriod(null)
                setConfirmingDelete(false)
              }}
              disabled={saving}
            >
              Annuler
            </button>
          </div>
        </div>
      )}
    </section>
  )
}
