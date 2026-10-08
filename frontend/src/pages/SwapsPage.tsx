import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Icon } from '../components/Icon'
import { Overlay } from '../components/Overlay'
import '../features/dashboard/dashboard.css'
import { monthShort } from '../features/dashboard/dates'
import { dutySpan } from '../features/duties/dutyDates'
import { fetchSwapOverview, fetchSwapRequest, swapErrorMessage } from '../features/swaps/api'
import { audienceLabel, personName, requestStatus, unitLabel } from '../features/swaps/labels'
import { SwapRequestPanel } from '../features/swaps/SwapRequestPanel'
import { SwapStatusPill } from '../features/swaps/SwapStatusPill'
import '../features/swaps/swaps.css'
import type { SwapOverview, SwapRequest, SwapRequestDetail } from '../features/swaps/types'

type Tab = 'mine' | 'received' | 'team'

const TABS: { id: Tab; label: string; empty: string }[] = [
  {
    id: 'mine',
    label: 'Mes demandes',
    empty: 'Vous n’avez demandé aucun échange. Depuis « Mes gardes », choisissez « Échanger ma garde ».',
  },
  { id: 'received', label: 'Propositions reçues', empty: 'Aucune demande d’échange ne vous a été adressée.' },
  {
    id: 'team',
    label: 'Demandes de l’équipe',
    empty: 'Aucune demande ouverte à toute votre équipe pour l’instant.',
  },
]

/** Something waits for the viewer on this request: a proposal to decide, or a request they may answer. */
function needsMe(request: SwapRequest): boolean {
  return (
    request.status === 'OPEN' &&
    (request.proposals.some((p) => p.status === 'PENDING' && p.actions.accept) ||
      (request.actions.propose && request.viewerRole !== 'REQUESTER'))
  )
}

/**
 * "Échanges" (docs/duty-swaps.md §9): the member's own requests, those addressed to them, and the open requests
 * of their lines. Opening one shows it in full, with its actions and its history. The planning itself is never
 * shown changed here — only "Mes gardes" and the planning, read from the server, show who holds what.
 */
export function SwapsPage() {
  const [overview, setOverview] = useState<SwapOverview | null>(null)
  const [error, setError] = useState(false)
  const [params, setParams] = useSearchParams()
  const openId = params.get('request')
  const [tab, setTab] = useState<Tab>((params.get('tab') as Tab | null) ?? 'mine')
  // Keyed by the request it belongs to, so a stale answer is never shown for another request.
  const [loaded, setLoaded] = useState<{
    id: string
    detail: SwapRequestDetail | null
    error: string | null
  } | null>(null)
  const detail = loaded && loaded.id === openId ? loaded.detail : null
  const detailError = loaded && loaded.id === openId ? loaded.error : null

  // Opened from an email link (?request= without ?tab=): show the list that request belongs to.
  const linkedRequest = useRef(params.get('tab') ? null : openId)

  const load = useCallback(() => {
    fetchSwapOverview()
      .then((value) => {
        setOverview(value)
        setError(false)
        const linked = linkedRequest.current
        if (linked) {
          linkedRequest.current = null
          const found = TABS.find((t) => value[t.id].some((r) => r.stableId === linked))
          if (found) setTab(found.id)
        }
      })
      .catch(() => setError(true))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    if (!openId) return
    let cancelled = false
    fetchSwapRequest(openId)
      .then((value) => {
        if (!cancelled) setLoaded({ id: openId, detail: value, error: null })
      })
      .catch((e: unknown) => {
        if (!cancelled)
          setLoaded({
            id: openId,
            detail: null,
            error: swapErrorMessage(e, 'Impossible de charger cette demande d’échange.'),
          })
      })
    return () => {
      cancelled = true
    }
  }, [openId])

  function open(request: SwapRequest) {
    setParams({ request: request.stableId, tab })
  }

  function close() {
    setParams({ tab })
  }

  const list = overview ? overview[tab] : []

  return (
    <section className="page db">
      <header className="page__header">
        <div>
          <h1>Échanges de gardes</h1>
          <p className="page__lead">
            Proposez, recevez et concluez vos échanges. Une garde ne change de titulaire qu’une fois l’échange
            accepté et enregistré dans MedVue.
          </p>
        </div>
        <Link to="/my-duties" className="btn btn--secondary">
          <Icon name="moon" size={18} strokeWidth={2} />
          Mes gardes
        </Link>
      </header>

      {overview === null && !error && (
        <p role="status" className="muted">
          Chargement…
        </p>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>Impossible de charger vos échanges.</span>
        </p>
      )}

      {overview !== null && (
        <>
          <div role="group" aria-label="Échanges" className="segmented swap-tabs">
            {TABS.map((t) => {
              const pending = overview[t.id].filter(needsMe).length
              return (
                <button
                  key={t.id}
                  type="button"
                  className="segmented__option"
                  aria-pressed={tab === t.id}
                  onClick={() => {
                    setTab(t.id)
                    setParams({ tab: t.id })
                  }}
                >
                  {t.label}
                  {pending > 0 && (
                    <span
                      className="swap-tabs__badge tnum"
                      aria-label={`${pending} en attente de votre réponse`}
                    >
                      {pending}
                    </span>
                  )}
                </button>
              )
            })}
          </div>

          <section aria-label={TABS.find((t) => t.id === tab)?.label} className="db-card">
            {list.length === 0 ? (
              <div className="db-empty db-empty-center">
                <span className="db-empty-icon">
                  <Icon name="arrow" size={24} strokeWidth={1.9} />
                </span>
                <div className="db-muted">{TABS.find((t) => t.id === tab)?.empty}</div>
              </div>
            ) : (
              <ul className="db-list">
                {list.map((request) => (
                  <li key={request.stableId}>
                    <SwapRow request={request} onOpen={() => open(request)} />
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}

      {openId && (
        <Overlay title="Demande d’échange" variant="drawer" onClose={close}>
          {!detail && !detailError && (
            <p role="status" className="muted">
              Chargement…
            </p>
          )}
          {detailError && (
            <p role="alert" className="alert alert--error">
              <Icon name="alert" size={18} strokeWidth={2} />
              <span>{detailError}</span>
            </p>
          )}
          {detail && (
            <SwapRequestPanel
              request={detail}
              onChanged={(updated) => {
                setLoaded({ id: updated.stableId, detail: updated, error: null })
                load()
              }}
            />
          )}
        </Overlay>
      )}
    </section>
  )
}

function SwapRow({ request, onOpen }: { request: SwapRequest; onOpen: () => void }) {
  const { first } = dutySpan(request.offered)
  const status = requestStatus(request)
  const mine = request.viewerRole === 'REQUESTER'
  return (
    <button type="button" className="db-row swap-row" onClick={onOpen}>
      <span className="db-date-tile" aria-hidden="true">
        <span className="db-date-tile-day">{first.getDate()}</span>
        <span className="db-date-tile-mon">{monthShort(first)}</span>
      </span>
      <span className="db-row-text">
        <span className="db-row-title db-num">{unitLabel(request.offered)}</span>
        <span className="db-muted">
          {mine ? `À : ${audienceLabel(request)}` : `De : ${personName(request.requester)}`} ·{' '}
          {request.line.name}
        </span>
        <span className="swap-row__status">
          <SwapStatusPill {...status} />
          {needsMe(request) && <span className="swap-row__todo">À traiter</span>}
        </span>
      </span>
      <span className="db-chevron">
        <Icon name="right" size={18} strokeWidth={2.2} />
      </span>
    </button>
  )
}
