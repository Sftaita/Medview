import { useMemo, useState } from 'react'
import type { BlockId, DayIndex, WeekStructure, WeekStructurePayload } from './weeklyStructure'
import {
  DAY_NAMES,
  DAY_SHORT,
  canCreateBlock,
  createBlock,
  dissolveBlock,
  dutyDaysPerWeek,
  isContiguous,
  members,
  renameBlock,
  setNone,
  setSolo,
  toPayload,
  unitsPerWeek,
  warnings,
} from './weeklyStructure'
import { useElementWidth } from './useElementWidth'
import './WeekStructureEditor.css'

export interface WeekStructureEditorProps {
  /** Structure contrôlée. */
  value: WeekStructure
  /** Appelé à chaque modification, avec la nouvelle structure et sa forme sérialisée. */
  onChange: (next: WeekStructure, payload: WeekStructurePayload) => void
  /** Titre de la carte. */
  title?: string
  /** Lecture seule : les jours ne sont plus sélectionnables. */
  readOnly?: boolean
}

type Tier = 'l' | 'm' | 's'
/** Paliers calculés sur la largeur du composant, pas de l'écran. */
const tierOf = (w: number): Tier => (w >= 820 ? 'l' : w >= 480 ? 'm' : 's')

const plural = (n: number, one: string, many: string) => `${n} ${n > 1 ? many : one}`

export function WeekStructureEditor({
  value,
  onChange,
  title = 'La semaine type',
  readOnly = false,
}: WeekStructureEditorProps) {
  const [ref, width] = useElementWidth<HTMLElement>()
  const tier = tierOf(width)
  const [selection, setSelection] = useState<DayIndex[]>([])

  // En lecture seule, la sélection est ignorée (dérivée, pas d'effet).
  const sel = useMemo(() => (readOnly ? [] : [...selection].sort((a, b) => a - b)), [readOnly, selection])
  const commit = (next: WeekStructure) => {
    setSelection([])
    onChange(next, toPayload(next))
  }
  const toggle = (i: DayIndex) => setSelection((s) => (s.includes(i) ? s.filter((x) => x !== i) : [...s, i]))

  const units = unitsPerWeek(value)
  const duty = dutyDaysPerWeek(value)
  const notes = warnings(value)
  const short = (i: DayIndex) => (tier === 's' ? DAY_SHORT[i][0] : DAY_SHORT[i])
  const list = (idx: DayIndex[]) => idx.map((i) => DAY_SHORT[i]).join(' · ')

  return (
    <section ref={ref} className="wse" data-tier={tier}>
      <header className="wse__head">
        <h2 className="wse__title">{title}</h2>
        <span className="wse__summary">
          {plural(units, 'unité', 'unités')} à attribuer · {plural(duty, 'jour', 'jours')} de garde
        </span>
      </header>

      <div className="wse__grid" role="group" aria-label="Jours de la semaine">
        {value.days.map((d, idx) => {
          const i = idx as DayIndex
          const picked = sel.includes(i)
          const block = d.mode === 'block' ? d.block : undefined
          const blockName = block && value.blocks.find((b) => b.id === block)?.name
          const long = d.mode === 'none' ? 'Pas de garde' : block ? `Bloc ${block}` : 'Garde isolée'
          const tag = d.mode === 'none' ? '—' : (block ?? 'G')
          return (
            <button
              key={i}
              type="button"
              className="wse__tile"
              data-mode={d.mode}
              data-block={block}
              aria-pressed={picked}
              disabled={readOnly}
              title={`${DAY_NAMES[i]} · ${long.toLowerCase()}${blockName ? ` (${blockName})` : ''}`}
              aria-label={`${DAY_NAMES[i]}, ${block ? `bloc ${block}` : long.toLowerCase()}${blockName ? ` (${blockName})` : ''}`}
              onClick={() => toggle(i)}
            >
              <span className="wse__bar" aria-hidden />
              <span className="wse__short">{short(i)}</span>
              <span className="wse__name">{DAY_NAMES[i]}</span>
              {tier === 'l' ? (
                <span className="wse__state">{long}</span>
              ) : (
                <span className="wse__tag" aria-label={long}>
                  {tag}
                </span>
              )}
              <span className="wse__pick" aria-hidden />
            </button>
          )
        })}
      </div>

      {value.blocks.map((b) => {
        const m = members(value, b.id)
        const first = m[0],
          last = m[m.length - 1]
        return (
          <div key={b.id} className="wse__grid wse__rail" data-block={b.id} aria-hidden>
            {value.days.map((_, idx) => {
              const i = idx as DayIndex
              const isMember = m.includes(i)
              const inSpan = i >= first && i <= last
              return (
                <div key={i} className="wse__rail-cell">
                  {isMember && (
                    <span className="wse__rail-bar" data-first={i === first} data-last={i === last} />
                  )}
                  {!isMember && inSpan && <span className="wse__rail-link" />}
                  {i === first && <span className="wse__rail-letter">{b.id}</span>}
                </div>
              )
            })}
          </div>
        )
      })}

      {!readOnly && (
        <div className="wse__actions" data-active={sel.length > 0}>
          {sel.length === 0 ? (
            <span className="wse__hint">
              Touchez un ou plusieurs jours pour changer leur rôle. Un bloc peut réunir des jours non
              consécutifs — vendredi et dimanche sans le samedi, par exemple.
            </span>
          ) : (
            <>
              <div className="wse__sel">
                <div className="wse__sel-title">
                  {plural(sel.length, 'jour sélectionné', 'jours sélectionnés')}
                </div>
                <div className="wse__sel-sub">
                  {list(sel)}
                  {sel.length === 1 ? ' · un bloc réunit au moins deux jours' : ''}
                </div>
              </div>
              <div className="wse__buttons">
                <button
                  type="button"
                  className="btn btn--primary"
                  disabled={!canCreateBlock(value, sel)}
                  onClick={() => commit(createBlock(value, sel))}
                >
                  Créer un bloc
                </button>
                <button
                  type="button"
                  className="btn btn--secondary"
                  onClick={() => commit(setSolo(value, sel))}
                >
                  Garde isolée
                </button>
                <button
                  type="button"
                  className="btn btn--secondary"
                  onClick={() => commit(setNone(value, sel))}
                >
                  Pas de garde
                </button>
                <button type="button" className="btn btn--ghost" onClick={() => setSelection([])}>
                  Annuler
                </button>
              </div>
            </>
          )}
        </div>
      )}

      {value.blocks.length > 0 && (
        <div className="wse__blocks">
          {value.blocks.map((b) => {
            const m = members(value, b.id)
            return (
              <div key={b.id} className="wse__block" data-block={b.id}>
                <span className="wse__chip">{b.id}</span>
                <input
                  className="wse__input"
                  value={b.name}
                  aria-label={`Nom du bloc ${b.id}`}
                  disabled={readOnly}
                  onChange={(e) => {
                    const next = renameBlock(value, b.id as BlockId, e.target.value)
                    onChange(next, toPayload(next))
                  }}
                />
                <span className="wse__members">
                  {list(m)} · {m.length} jours{isContiguous(m) ? '' : ' · non consécutifs'}
                </span>
                {!readOnly && (
                  <button
                    type="button"
                    className="btn btn--danger btn--sm wse__dissolve"
                    onClick={() => commit(dissolveBlock(value, b.id))}
                  >
                    Dissoudre
                  </button>
                )}
              </div>
            )
          })}
        </div>
      )}

      {notes.map((w, k) => (
        <div key={k} className="wse__note" data-tone={w.tone} role={w.tone === 'warn' ? 'alert' : undefined}>
          <span className="wse__note-dot" aria-hidden />
          <span>{w.text}</span>
        </div>
      ))}
    </section>
  )
}
