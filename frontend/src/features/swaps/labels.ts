import { dutyWhat, formatDutyDates } from '../duties/dutyDates'
import type { SwapEvent, SwapPerson, SwapProposal, SwapRequest, SwapUnit } from './types'

/** French labels of the swap workflow (docs/duty-swaps.md §9) — the codes come from the server, the words from here. */

export function personName(person: SwapPerson): string {
  return `${person.firstName} ${person.lastName}`
}

/** « Mar. 5 janv. · Garde » / « Sam. 9 → dim. 10 janv. · Bloc Week-end ». */
export function unitLabel(unit: SwapUnit): string {
  return `${formatDutyDates(unit)} · ${dutyWhat(unit)}`
}

type Tone = 'info' | 'live' | 'done' | 'warn' | 'muted'

/** The request's status as the viewer reads it. */
export function requestStatus(request: SwapRequest): { label: string; tone: Tone } {
  switch (request.status) {
    case 'OPEN': {
      const pendingForMe = request.proposals.some((p) => p.status === 'PENDING' && p.actions.accept)
      return pendingForMe
        ? { label: 'Proposition reçue', tone: 'live' }
        : { label: 'En attente de réponse', tone: 'info' }
    }
    case 'COMPLETED':
      return { label: 'Échange confirmé', tone: 'done' }
    case 'REFUSED':
      return { label: 'Refusé', tone: 'warn' }
    case 'CANCELLED':
      return { label: 'Annulé', tone: 'muted' }
    case 'EXPIRED':
      return { label: 'Expiré', tone: 'muted' }
    case 'OBSOLETE':
      return { label: 'Devenu indisponible', tone: 'muted' }
  }
}

export function proposalStatus(proposal: SwapProposal): { label: string; tone: Tone } {
  switch (proposal.status) {
    case 'PENDING':
      return proposal.actions.accept
        ? { label: 'Proposition reçue', tone: 'live' }
        : { label: 'En attente de réponse', tone: 'info' }
    case 'ACCEPTED':
      return { label: 'Échange confirmé', tone: 'done' }
    case 'REFUSED':
      return { label: 'Refusé', tone: 'warn' }
    case 'WITHDRAWN':
      return { label: 'Retirée', tone: 'muted' }
    case 'NOT_SELECTED':
      return { label: 'Non retenu', tone: 'muted' }
    case 'CANCELLED':
      return { label: 'Annulé', tone: 'muted' }
    case 'EXPIRED':
      return { label: 'Expiré', tone: 'muted' }
    case 'OBSOLETE':
      return { label: 'Devenu indisponible', tone: 'muted' }
  }
}

/** « Toute l'équipe » / « Pierre Durand » / « Pierre Durand, Anne Roy » — who a request is addressed to. */
export function audienceLabel(request: SwapRequest): string {
  if (request.audience === 'ALL') return `Toute l’équipe (${request.line.name})`
  if (request.recipients.length === 0) return 'Collègues sélectionnés'
  return request.recipients.map(personName).join(', ')
}

/** One line of the chronology. */
export function eventLabel(event: SwapEvent): string {
  const who = event.actor ? personName(event.actor) : 'MedVue'
  switch (event.type) {
    case 'REQUEST_CREATED':
      return `${who} a demandé un échange`
    case 'REQUEST_SENT': {
      const count = typeof event.data.notifiedCount === 'number' ? event.data.notifiedCount : null
      return event.data.audience === 'ALL'
        ? `Demande diffusée à toute l’équipe${count !== null ? ` (${count} personne${count > 1 ? 's' : ''} prévenue${count > 1 ? 's' : ''})` : ''}`
        : 'Demande envoyée'
    }
    case 'PROPOSAL_CREATED':
      return `${who} a fait une proposition`
    case 'PROPOSAL_WITHDRAWN':
      return `${who} a retiré sa proposition`
    case 'PROPOSAL_REFUSED':
      return `${who} a refusé la proposition`
    case 'PROPOSAL_ACCEPTED':
      return `${who} a accepté la proposition`
    case 'PROPOSAL_NOT_SELECTED':
      return 'Proposition non retenue (une autre a été acceptée)'
    case 'PROPOSAL_OBSOLETE':
      return 'Proposition devenue indisponible (la garde a changé de titulaire ou a commencé)'
    case 'SWAP_COMPLETED':
      return 'Échange enregistré dans le planning'
    case 'REQUEST_REFUSED':
      return 'Demande refusée'
    case 'REQUEST_CANCELLED':
      return `${who} a annulé la demande`
    case 'REQUEST_EXPIRED':
      return 'Demande expirée : la garde a commencé'
    case 'REQUEST_OBSOLETE':
      return 'Demande devenue indisponible : la garde a changé de titulaire'
    case 'SWAP_VALIDATION_FAILED':
      return `Tentative d’acceptation refusée par la vérification finale — rien n’a été modifié`
  }
}

const WHEN = new Intl.DateTimeFormat('fr-BE', {
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
})

export function formatWhen(iso: string): string {
  return WHEN.format(new Date(iso))
}
