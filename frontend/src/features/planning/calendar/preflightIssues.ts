import type { PublicationMemberRef, PublicationPreflight, PublicationUnitRef } from '../result/types'

/**
 * One reason the current calendar cannot be (re)published, said so that a
 * manager can find it and fix it: which duty or block, on which line, held
 * by whom, which rule it breaks and what to do (docs/decisions.md D133 —
 * the preflight already computes every one of these; only its wording
 * lives here, never a new check).
 */
export type PreflightIssue = {
  key: string
  kind: 'assignment' | 'inconsistent' | 'undetermined' | 'uncovered' | 'line'
  /** Where "Voir dans le calendrier" goes — null when the issue is not a duty (a line never generated). */
  dutyStableId: string | null
  /** Every duty of the unit, to mark all of a block's days in the calendar. */
  dutyStableIds: string[]
  /** "Jeudi 29 octobre · Première ligne" */
  where: string
  /** What is wrong, with the people concerned. */
  detail: string
  /** The rule the current state breaks. */
  rule: string
  /** What to do about it. */
  fix: string
  /** Short word shown on the calendar cell ("indisponible"). */
  tag: string
}

const DAY = new Intl.DateTimeFormat('fr-BE', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  timeZone: 'UTC',
})

function day(date: string): string {
  return DAY.format(new Date(`${date}T00:00:00Z`))
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** "Jeudi 29 octobre" or "Du samedi 17 octobre au dimanche 18 octobre" — a block by its first and last day. */
export function datesLabel(dates: string[]): string {
  if (dates.length === 0) return ''
  if (dates.length === 1) return capitalize(day(dates[0]))
  return `Du ${day(dates[0])} au ${day(dates[dates.length - 1])}`
}

function where(dates: string[], lineName: string | null | undefined): string {
  return [datesLabel(dates), lineName ? `ligne « ${lineName} »` : null].filter(Boolean).join(' · ')
}

function person(member: PublicationMemberRef): string {
  return `${member.firstName} ${member.lastName}`
}

function unit(item: PublicationUnitRef, fallbackDutyStableId: string, fallbackDate: string) {
  return {
    dutyStableIds:
      item.dutyStableIds && item.dutyStableIds.length > 0 ? item.dutyStableIds : [fallbackDutyStableId],
    dates: item.dates && item.dates.length > 0 ? item.dates : [fallbackDate],
  }
}

type RuleText = { rule: string; detail: (who: string, block: boolean) => string }

/** The rule behind each exclusion code a live check can report (backend ExclusionReason). */
const RULES: Record<string, RuleText> = {
  UNAVAILABLE: {
    rule: 'personne n’est de garde un jour où elle s’est déclarée indisponible',
    detail: (who, block) =>
      `${who} a déclaré une indisponibilité ${block ? 'pendant ce bloc' : 'ce jour-là'}.`,
  },
  NON_PARTICIPATION: {
    rule: 'personne n’est de garde pendant une non-participation déclarée',
    detail: (who) => `${who} a une non-participation déclarée sur cette période.`,
  },
  USER_INACTIVE: {
    rule: 'un compte désactivé ne peut pas être de garde',
    detail: (who) => `Le compte de ${who} est désactivé.`,
  },
  NOT_TEAM_MEMBER: {
    rule: 'le titulaire doit être membre de la ligne à cette date',
    detail: (who) => `${who} ne fait pas partie de cette ligne à cette date.`,
  },
  MEMBERSHIP_OUT_OF_RANGE: {
    rule: 'le titulaire doit être membre de la ligne à cette date',
    detail: (who) => `L’adhésion de ${who} à cette ligne ne couvre pas cette date.`,
  },
  CONFLICT: {
    rule: 'personne n’a deux gardes qui se chevauchent',
    detail: (who) => `${who} est déjà de garde au même moment.`,
  },
  CROSS_LINE_CONFLICT: {
    rule: 'personne n’a deux gardes qui se chevauchent, toutes lignes confondues',
    detail: (who) => `${who} est déjà de garde au même moment sur une autre ligne.`,
  },
  LEGAL_MIN_REST: {
    rule: 'repos légal minimal entre deux gardes',
    detail: (who) => `${who} n’a pas le repos légal minimal entre cette garde et une autre.`,
  },
  TEAM_MIN_REST: {
    rule: 'repos minimal choisi pour cette génération',
    detail: (who) => `${who} n’a pas le repos minimal entre cette garde et une autre.`,
  },
  CROSS_LINE_LEGAL_MIN_REST: {
    rule: 'repos légal minimal entre deux gardes, toutes lignes confondues',
    detail: (who) => `${who} n’a pas le repos légal minimal avec une garde sur une autre ligne.`,
  },
  CROSS_LINE_TEAM_MIN_REST: {
    rule: 'repos minimal choisi pour cette génération, toutes lignes confondues',
    detail: (who) => `${who} n’a pas le repos minimal avec une garde sur une autre ligne.`,
  },
  SELF_COVERAGE: {
    rule: 'on ne se renforce pas soi-même',
    detail: (who) => `${who} est déjà de garde sur la ligne que ce renfort complète.`,
  },
}

const ASSIGNMENT_FIX = 'Remplacez le titulaire ou retirez l’affectation, puis republiez.'

/**
 * The blocking issues of a preflight, one per duty or block (never one per
 * category): what the calendar marks and what the (re)publication dialog
 * lists. `mode` follows the server's own rule (docs/decisions.md D143): on a
 * republication, an uncovered duty only blocks on a line never published yet.
 */
export function blockingIssues(
  preflight: PublicationPreflight,
  mode: 'publish' | 'republish',
): PreflightIssue[] {
  const issues: PreflightIssue[] = []
  // Every list may be missing from an older server response: read as empty, never a crash of the calendar.
  const statusByLine = new Map((preflight.lines ?? []).map((line) => [line.lineStableId, line.periodStatus]))

  for (const line of preflight.lines ?? []) {
    if (!line.hasGeneration) {
      issues.push({
        key: `line-${line.lineStableId}`,
        kind: 'line',
        dutyStableId: null,
        dutyStableIds: [],
        where: `Ligne « ${line.lineName} »`,
        detail: 'Aucun planning n’a encore été généré pour cette ligne.',
        rule: 'chaque ligne active doit avoir été générée',
        fix: 'Lancez la génération.',
        tag: '',
      })
    }
  }

  for (const item of [...(preflight.invalidAssignments ?? []), ...(preflight.conflicts ?? [])]) {
    const { dutyStableIds, dates } = unit(item, item.duty.dutyStableId, item.duty.date)
    const text = item.reasonCode ? RULES[item.reasonCode] : undefined
    const who = person(item.member)
    issues.push({
      key: `assignment-${item.unitStableKey ?? item.duty.dutyStableId}`,
      kind: 'assignment',
      dutyStableId: dutyStableIds[0],
      dutyStableIds,
      where: where(dates, item.duty.lineName),
      detail: text ? text.detail(who, dates.length > 1) : `${who} : ${item.reason}.`,
      rule: text?.rule ?? item.reason,
      fix: ASSIGNMENT_FIX,
      tag: item.reason,
    })
  }

  for (const group of preflight.inconsistentGroups ?? []) {
    const first = group.dutyStableIds?.[0] ?? group.duty?.dutyStableId ?? null
    const holders = (group.members ?? []).map(person)
    issues.push({
      key: `inconsistent-${group.groupInstanceStableId}`,
      kind: 'inconsistent',
      dutyStableId: first,
      dutyStableIds: group.dutyStableIds ?? (first ? [first] : []),
      where:
        where(group.dates ?? (group.duty ? [group.duty.date] : []), group.duty?.lineName) || 'Bloc de garde',
      detail:
        holders.length > 1
          ? `Les jours de ce bloc sont attribués à des personnes différentes (${holders.join(', ')}).`
          : 'Les jours de ce bloc ne sont pas tous attribués à la même personne.',
      rule: 'un bloc de garde s’attribue d’un seul tenant, à une seule personne',
      fix: 'Attribuez le bloc entier à une seule personne.',
      tag: 'bloc incohérent',
    })
  }

  for (const item of preflight.undeterminedDuties ?? []) {
    issues.push({
      key: `undetermined-${item.unitStableKey}`,
      kind: 'undetermined',
      dutyStableId: item.duty.dutyStableId,
      dutyStableIds: [item.duty.dutyStableId],
      where: where(item.dates, item.lineName),
      detail: item.explanation,
      rule: 'un renfort dépend du titulaire de sa garde source',
      fix: 'Attribuez d’abord la garde source.',
      tag: '',
    })
  }

  const uncovered = (preflight.uncoveredDuties ?? []).filter(
    (duty) => mode === 'publish' || !duty.lineStableId || statusByLine.get(duty.lineStableId) !== 'PUBLISHED',
  )
  if (uncovered.length > 0) {
    const first = uncovered[0]
    const count = uncovered.length
    issues.push({
      key: 'uncovered',
      kind: 'uncovered',
      dutyStableId: first.dutyStableId,
      dutyStableIds: uncovered.map((duty) => duty.dutyStableId),
      where:
        count === 1 ? where([first.date], first.lineName) : `${count} gardes, à partir du ${day(first.date)}`,
      detail: `${count === 1 ? 'Une garde obligatoire n’a' : `${count} gardes obligatoires n’ont`} pas de titulaire.`,
      rule:
        mode === 'publish'
          ? 'une première publication exige que toutes les gardes obligatoires soient couvertes'
          : 'une ligne jamais publiée exige que toutes ses gardes obligatoires soient couvertes',
      fix: 'Attribuez-les une à une ou utilisez « Compléter automatiquement ».',
      tag: '',
    })
  }

  return issues
}

/**
 * The issues that sit on an assigned duty — what the calendar marks on the
 * cell itself, keyed by every duty of the unit. Uncovered and undetermined
 * duties already have their own look in the calendar.
 */
export function issuesByDuty(issues: PreflightIssue[]): Map<string, PreflightIssue> {
  const map = new Map<string, PreflightIssue>()
  for (const issue of issues) {
    if (issue.kind !== 'assignment' && issue.kind !== 'inconsistent') continue
    for (const id of issue.dutyStableIds) {
      if (!map.has(id)) map.set(id, issue)
    }
  }
  return map
}
