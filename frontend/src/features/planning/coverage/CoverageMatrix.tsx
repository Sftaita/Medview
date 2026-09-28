import { useElementWidth } from '../../week-structure'
import type { MatrixRow, Selection } from './coverageModel'
import { setColumn, setDays, toggleDay } from './coverageModel'
import type { ApiWeekday } from './types'
import { ALL_API_WEEKDAYS, WEEKDAYS } from './weekdays'
import './coverage.css'

type Props = {
  rows: MatrixRow[]
  selection: Selection
  /** Days the line itself has no duty (its weekly structure): no new selection there, existing ones kept visible. */
  excludedWeekdays: ApiWeekday[]
  disabled?: boolean
  onChange: (selection: Selection) => void
}

/** Below this width, one card per person with its days on a wrapping row, instead of a 7-column table. */
export const STACKED_BELOW = 620

const fullName = (row: MatrixRow) => `${row.firstName} ${row.lastName}`.trim()

/**
 * "Chirurgiens × jours" (docs/decisions.md D167): for each person holding
 * duties on the line to reinforce, the days their duty requires a
 * reinforcement. Purely local until "Enregistrer": every change goes through
 * `onChange`, nothing is sent box by box. Same data, two layouts — a table on
 * a wide screen, one card per person on a narrow one.
 */
export function CoverageMatrix({ rows, selection, excludedWeekdays, disabled = false, onChange }: Props) {
  const [ref, width] = useElementWidth<HTMLDivElement>()
  const stacked = width < STACKED_BELOW
  const excluded = new Set(excludedWeekdays)
  const selected = (row: MatrixRow, day: ApiWeekday) => (selection[row.userStableId] ?? []).includes(day)
  const userIds = rows.map((row) => row.userStableId)

  function dayBox(row: MatrixRow, day: (typeof WEEKDAYS)[number], showLabel: boolean) {
    const checked = selected(row, day.api)
    const offDay = excluded.has(day.api)
    return (
      <label className={`cov-day${offDay ? ' cov-day--off' : ''}`} key={day.api}>
        <input
          type="checkbox"
          checked={checked}
          disabled={disabled || (offDay && !checked)}
          aria-label={`${fullName(row)} — ${day.long}${offDay ? ' (pas de garde de renfort ce jour)' : ''}`}
          onChange={() => onChange(toggleDay(selection, row.userStableId, day.api))}
        />
        {showLabel && <span aria-hidden>{day.short}</span>}
      </label>
    )
  }

  function rowActions(row: MatrixRow) {
    const available = ALL_API_WEEKDAYS.filter((day) => !excluded.has(day))
    return (
      <span className="cov-row-actions">
        <button
          type="button"
          className="pd-link"
          disabled={disabled}
          aria-label={`Tous les jours pour ${fullName(row)}`}
          onClick={() => onChange(setDays(selection, row.userStableId, available))}
        >
          Tous les jours
        </button>
        <button
          type="button"
          className="pd-link"
          disabled={disabled}
          aria-label={`Aucun jour pour ${fullName(row)}`}
          onClick={() => onChange(setDays(selection, row.userStableId, []))}
        >
          Aucun jour
        </button>
      </span>
    )
  }

  function outsideNote(row: MatrixRow) {
    return row.outsideSource ? <span className="cov-note">Ne fait plus partie de la ligne à renforcer</span> : null
  }

  if (rows.length === 0) {
    return (
      <div ref={ref}>
        <p className="muted">Aucun chirurgien n’est encore membre de la ligne à renforcer.</p>
      </div>
    )
  }

  return (
    <div ref={ref} className={stacked ? 'cov-matrix cov-matrix--stacked' : 'cov-matrix'} data-layout={stacked ? 'stacked' : 'table'}>
      {stacked ? (
        <ul className="cov-cards" aria-label="Jours nécessitant un renfort, par chirurgien">
          {rows.map((row) => (
            <li key={row.userStableId} className="cov-card">
              <fieldset>
                <legend className="cov-name">{fullName(row)}</legend>
                {outsideNote(row)}
                <div className="cov-days">{WEEKDAYS.map((day) => dayBox(row, day, true))}</div>
                {rowActions(row)}
              </fieldset>
            </li>
          ))}
        </ul>
      ) : (
        <table className="cov-table">
          <caption className="sr-only">Jours nécessitant un renfort, par chirurgien</caption>
          <thead>
            <tr>
              <th scope="col">Chirurgien</th>
              {WEEKDAYS.map((day) => {
                const allOn = rows.every((row) => selected(row, day.api))
                const offDay = excluded.has(day.api)
                return (
                  <th scope="col" key={day.api} className={offDay ? 'cov-off' : undefined}>
                    <button
                      type="button"
                      className="cov-col-toggle"
                      disabled={disabled || (offDay && !allOn)}
                      aria-label={`${allOn ? 'Désélectionner' : 'Sélectionner'} le ${day.long} pour tous`}
                      title={offDay ? 'Pas de garde de renfort ce jour dans la semaine type' : undefined}
                      onClick={() => onChange(setColumn(selection, userIds, day.api, !allOn))}
                    >
                      {day.short}
                    </button>
                  </th>
                )
              })}
              <th scope="col">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.userStableId}>
                <th scope="row" className="cov-name">
                  {fullName(row)}
                  {outsideNote(row)}
                </th>
                {WEEKDAYS.map((day) => (
                  <td key={day.api}>{dayBox(row, day, false)}</td>
                ))}
                <td>{rowActions(row)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
