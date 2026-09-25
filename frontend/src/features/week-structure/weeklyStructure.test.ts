import { describe, expect, it } from 'vitest'
import {
  allSolo,
  createBlock,
  dissolveBlock,
  fromPayload,
  members,
  preset,
  setNone,
  setSolo,
  setBlockFamily,
  setSoloFamily,
  toPayload,
  unitsPerWeek,
  dutyDaysPerWeek,
  warnings,
  canCreateBlock,
  renameBlock,
} from './weeklyStructure'

describe('semaine type', () => {
  it('crée un bloc V·S·D : 5 unités, 7 jours', () => {
    const s = preset('vsd')
    expect(members(s, 'A')).toEqual([4, 5, 6])
    expect(unitsPerWeek(s)).toBe(5)
    expect(dutyDaysPerWeek(s)).toBe(7)
  })

  it('accepte un bloc non consécutif V·D, le samedi reste isolé', () => {
    const s = preset('vd')
    expect(members(s, 'A')).toEqual([4, 6])
    expect(s.days[5]).toEqual({ mode: 'solo' })
    expect(unitsPerWeek(s)).toBe(6)
  })

  it('refuse un bloc d’un seul jour', () => {
    const s = createBlock(allSolo(), [2])
    expect(s.blocks).toHaveLength(0)
    expect(canCreateBlock(allSolo(), [2])).toBe(false)
  })

  it('dissout un bloc tombé à un seul jour', () => {
    let s = preset('vd')
    s = setSolo(s, [6])
    expect(s.blocks).toHaveLength(0)
    expect(s.days[4]).toEqual({ mode: 'solo' })
  })

  it('retire un jour d’un bloc sans le dissoudre s’il en reste deux', () => {
    const s = setNone(preset('vsd'), [6])
    expect(members(s, 'A')).toEqual([4, 5])
    expect(dutyDaysPerWeek(s)).toBe(6)
  })

  it('un jour « pas de garde » ne compte ni en unité ni en jour', () => {
    const s = preset('nosun')
    expect(unitsPerWeek(s)).toBe(6)
    expect(dutyDaysPerWeek(s)).toBe(6)
    expect(warnings(s).some((w) => w.tone === 'info')).toBe(true)
  })

  it('attribue les lettres dans l’ordre, un bloc vidé libère la sienne', () => {
    let s = allSolo()
    s = createBlock(s, [0, 1])
    s = createBlock(s, [2, 3])
    s = createBlock(s, [4, 5])
    expect(s.blocks.map((b) => b.id)).toEqual(['A', 'B', 'C'])
    // Dim + Lun : le lundi quitte A, qui tombe à 1 jour et se dissout.
    s = createBlock(s, [6, 0])
    expect(s.blocks.map((b) => b.id)).toEqual(['B', 'C', 'D'])
    expect(s.days[1]).toEqual({ mode: 'solo' })
    s = createBlock(s, [1, 6])
    expect(s.blocks.map((b) => b.id)).toContain('A')
  })

  it('prévient pour un bloc de 5 jours ou plus', () => {
    const s = createBlock(allSolo(), [0, 1, 2, 3, 4])
    expect(warnings(s).some((w) => w.tone === 'warn')).toBe(true)
  })

  it('prévient quand plus aucune garde n’est générée', () => {
    const s = setNone(allSolo(), [0, 1, 2, 3, 4, 5, 6])
    expect(unitsPerWeek(s)).toBe(0)
    expect(warnings(s)[0].tone).toBe('warn')
  })

  it('dissout et renomme', () => {
    let s = renameBlock(preset('vsd'), 'A', 'WE')
    expect(s.blocks[0].name).toBe('WE')
    s = dissolveBlock(s, 'A')
    expect(s.blocks).toHaveLength(0)
    expect(unitsPerWeek(s)).toBe(7)
  })

  it('aller-retour payload sans perte', () => {
    for (const id of ['vsd', 'vd', 'nosun', 'two', 'solo'] as const) {
      const s = preset(id)
      expect(fromPayload(toPayload(s))).toEqual(s)
    }
  })

  it('payload lisible par le back', () => {
    expect(toPayload(preset('vd'))).toEqual({
      blocks: [{ id: 'A', name: 'Vendredi + dimanche', family: 'Week-end', days: ['VEN', 'DIM'] }],
      solo: ['LUN', 'MAR', 'MER', 'JEU', 'SAM'],
      soloFamily: 'Semaine',
      excluded: [],
    })
  })

  it('classe un bloc et les jours isolés dans une famille d’équité', () => {
    let s = createBlock(allSolo(), [4, 5, 6])
    s = setBlockFamily(s, 'A', 'Week-end')
    s = setSoloFamily(s, 'Semaine')
    expect(s.blocks[0].family).toBe('Week-end')
    expect(s.soloFamily).toBe('Semaine')
    expect(toPayload(s).blocks[0].family).toBe('Week-end')
    expect(toPayload(s).soloFamily).toBe('Semaine')
  })

  it('une famille vide veut dire « pas de famille », jamais devinée', () => {
    const s = createBlock(allSolo(), [4, 5, 6])
    expect(s.blocks[0].family).toBe('')
    expect(s.soloFamily).toBe('')
  })

  it('relit une famille absente du payload comme vide, jamais une erreur', () => {
    const s = fromPayload({
      blocks: [{ id: 'A', name: 'Week-end', days: ['VEN', 'SAM', 'DIM'] } as never],
      solo: ['LUN', 'MAR', 'MER', 'JEU'],
      excluded: [],
    } as never)
    expect(s.blocks[0].family).toBe('')
    expect(s.soloFamily).toBe('')
  })
})
