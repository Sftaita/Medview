import { useRef, useState } from 'react'
import { Icon } from '../../../components/Icon'
import { previewDemandPolicy } from './api'
import type { MatrixRow, Selection } from './coverageModel'
import type { DemandPolicyInput, DemandPreview, InheritedStructure } from './types'
import { sortWeekdays, weekdayLabel } from './weekdays'

const DATE = new Intl.DateTimeFormat('fr-BE', {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  timeZone: 'UTC',
})

/** The API gives a period's end exclusive: the last day is the one before. */
function lastDay(endsAt: string): string {
  const d = new Date(`${endsAt}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() - 1)
  return d.toISOString().slice(0, 10)
}

function day(date: string): string {
  return DATE.format(new Date(`${date}T00:00:00Z`))
}

function list(days: string[]): string {
  if (days.length <= 1) return days.join('')
  return `${days.slice(0, -1).join(', ')} et ${days[days.length - 1]}`
}

/**
 * "Structure des blocs héritée de …" (docs/decisions.md D224): the source
 * line's structure, read-only — the reinforcement line has none of its own.
 */
export function InheritedStructurePanel({
  sourceName,
  structure,
}: {
  sourceName: string
  structure: InheritedStructure
}) {
  const solo = sortWeekdays(structure.soloWeekdays)
  const nothing = structure.blocks.length === 0 && solo.length === 0
  return (
    <div className="cov-inherited" aria-label={`Structure des blocs héritée de ${sourceName}`}>
      <p className="cov-inherited__title">
        <Icon name="layers" size={16} strokeWidth={2} />
        <span>
          Structure des blocs héritée de <strong>{sourceName}</strong>
        </span>
      </p>
      {nothing ? (
        <p className="cov-help">{sourceName} n’a pas encore de semaine type.</p>
      ) : (
        <ul className="cov-inherited__list">
          {structure.blocks.map((block) => (
            <li key={block.name}>
              Bloc « {block.name} » : {list(sortWeekdays(block.weekdays).map(weekdayLabel))}
            </li>
          ))}
          {solo.length > 0 && <li>Gardes isolées : {list(solo.map(weekdayLabel))}</li>}
        </ul>
      )}
      <p className="cov-help">
        Un renfort n’est créé que les jours qui le déclenchent, aux mêmes horaires que la garde renforcée.
        Dans un bloc, les jours déclenchés forment un bloc attribué à une seule personne (vendredi, samedi et
        dimanche, ou vendredi et dimanche) ; un seul jour déclenché reste une garde isolée. Aucun renfort
        n’est ajouté pour compléter un bloc.
      </p>
    </div>
  )
}

/** The triggers being edited, in words (D224) — never the members who hold the reinforcements. */
export function TriggerSummary({
  rows,
  selection,
  lineName,
}: {
  rows: MatrixRow[]
  selection: Selection
  lineName: string
}) {
  const configured = rows.filter((row) => (selection[row.userStableId] ?? []).length > 0)
  return (
    <div className="cov-summary" aria-label="Résumé des conditions de renfort">
      <h4>Résumé des conditions</h4>
      {configured.length === 0 ? (
        <p className="alert alert--warning">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>Aucune condition : aucune garde de renfort ne sera demandée.</span>
        </p>
      ) : (
        <ul>
          {configured.map((row) => (
            <li key={row.userStableId}>
              <strong>
                {row.firstName} {row.lastName}
              </strong>{' '}
              de garde : un renfort {lineName} le{' '}
              {list(sortWeekdays(selection[row.userStableId]).map(weekdayLabel))}.
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * "Prévisualiser les gardes nécessaires" (D224): what the configuration being
 * edited would ask for, from the source line's current calendar. Computed by
 * the server, nothing is saved, nothing is sent.
 */
export function DemandPreviewPanel({
  lineStableId,
  input,
  disabled,
}: {
  lineStableId: string
  input: DemandPolicyInput
  disabled: boolean
}) {
  const [preview, setPreview] = useState<DemandPreview | null>(null)
  const [state, setState] = useState<'idle' | 'loading' | 'error'>('idle')
  const inFlight = useRef(false)

  async function run() {
    if (inFlight.current) return
    inFlight.current = true
    setState('loading')
    try {
      setPreview(await previewDemandPolicy(lineStableId, input))
      setState('idle')
    } catch {
      setState('error')
    } finally {
      inFlight.current = false
    }
  }

  return (
    <div className="cov-preview">
      <button
        type="button"
        className="btn btn--secondary btn--sm"
        onClick={() => void run()}
        disabled={disabled || state === 'loading'}
        aria-busy={state === 'loading'}
      >
        <Icon name="eye" size={16} strokeWidth={2} />
        {state === 'loading' ? 'Calcul…' : 'Prévisualiser les gardes nécessaires'}
      </button>
      <span className="cov-help">
        Rien n’est enregistré : la configuration ci-dessus, appliquée au planning actuel.
      </span>
      {state === 'error' && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>La prévisualisation n’a pas pu être calculée.</span>
        </p>
      )}
      {preview && state !== 'loading' && (
        <div role="status" aria-label="Gardes de renfort nécessaires">
          {preview.periods.length === 0 && (
            <p className="cov-help">Aucune période commune avec la ligne à renforcer.</p>
          )}
          {preview.periods.map((entry) => {
            const blocks = entry.units.filter((u) => u.block).length
            return (
              <section key={entry.period.stableId} className="cov-preview__period">
                <h4>
                  {entry.period.number ? `Période ${entry.period.number}` : 'Période'} —{' '}
                  {day(entry.period.startsAt)} au {day(lastDay(entry.period.endsAt))}
                </h4>
                {!entry.sourceGenerated ? (
                  <p className="cov-help">
                    La ligne à renforcer n’est pas encore générée sur cette période : les renforts seront
                    connus après sa génération.
                  </p>
                ) : (
                  <>
                    <p>
                      <strong>
                        {entry.dutyCount} garde{entry.dutyCount > 1 ? 's' : ''} de renfort
                      </strong>
                      {blocks > 0 && ` dont ${blocks} bloc${blocks > 1 ? 's' : ''}`}
                      {entry.generated &&
                        ' — période déjà générée : ses règles restent celles de sa génération.'}
                    </p>
                    {entry.units.length > 0 && (
                      <ul className="cov-preview__units">
                        {entry.units.slice(0, 12).map((unit) => (
                          <li key={unit.dates.join()}>
                            {unit.block ? 'Bloc ' : ''}
                            {list(unit.dates.map(day))}
                            {unit.sourceHolder && (
                              <span className="cov-help">
                                {' '}
                                — {unit.sourceHolder.firstName} {unit.sourceHolder.lastName} de garde
                              </span>
                            )}
                          </li>
                        ))}
                        {entry.units.length > 12 && (
                          <li className="cov-help">… et {entry.units.length - 12} autres</li>
                        )}
                      </ul>
                    )}
                  </>
                )}
              </section>
            )
          })}
        </div>
      )}
    </div>
  )
}
