import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Icon } from '../components/Icon'
import '../features/dashboard/dashboard.css'
import { startOfDay } from '../features/dashboard/dates'
import { fetchMyDuties } from '../features/duties/api'
import { CalendarSubscriptionModal } from '../features/duties/CalendarSubscriptionModal'
import '../features/duties/duties.css'
import { DutyRow } from '../features/duties/DutyRow'
import { groupByMonth, splitDuties } from '../features/duties/dutyDates'
import type { MyDuty } from '../features/duties/types'
import { SwapRequestDialog } from '../features/swaps/SwapRequestDialog'
import '../features/swaps/swaps.css'

type Tab = 'upcoming' | 'past'

/**
 * "Mes gardes" (docs/decisions.md D168): every duty the signed-in user holds on a published line, across all
 * their plannings — upcoming first, past ones on demand. Each upcoming unit offers "Échanger ma garde"
 * (docs/decisions.md D178), or shows "Échange demandé" while a request is open — the unit stays listed here,
 * because its holder remains responsible for it until a swap is confirmed.
 */
export function MyDutiesPage() {
  const [duties, setDuties] = useState<MyDuty[] | null>(null)
  const [error, setError] = useState(false)
  const [tab, setTab] = useState<Tab>('upcoming')
  const [subscribing, setSubscribing] = useState(false)
  // The unit (its first duty) whose "Échanger ma garde" dialog is open.
  const [swapping, setSwapping] = useState<string | null>(null)
  const navigate = useNavigate()
  const today = useMemo(() => startOfDay(new Date()), [])

  useEffect(() => {
    let cancelled = false
    fetchMyDuties()
      .then((result) => {
        if (!cancelled) setDuties(result)
      })
      .catch(() => {
        if (!cancelled) setError(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  const { upcoming, past } = useMemo(() => splitDuties(duties ?? [], today), [duties, today])
  const shown = tab === 'upcoming' ? upcoming : past

  return (
    <section className="page db">
      <header className="page__header">
        <div>
          <h1>Mes gardes</h1>
          <p className="page__lead">Vos gardes des plannings publiés, toutes équipes confondues.</p>
        </div>
        <button type="button" className="btn btn--secondary" onClick={() => setSubscribing(true)}>
          <Icon name="calendar" size={18} strokeWidth={2} />
          Ajouter à mon agenda
        </button>
      </header>

      {duties === null && !error && (
        <p role="status" className="muted">
          Chargement…
        </p>
      )}
      {error && (
        <p role="alert" className="alert alert--error">
          <Icon name="alert" size={18} strokeWidth={2} />
          <span>Impossible de charger vos gardes.</span>
        </p>
      )}

      {duties !== null && (
        <>
          <div role="group" aria-label="Période" className="segmented duties-toolbar">
            <button
              type="button"
              className="segmented__option"
              aria-pressed={tab === 'upcoming'}
              onClick={() => setTab('upcoming')}
            >
              À venir <span className="tnum">({upcoming.length})</span>
            </button>
            <button
              type="button"
              className="segmented__option"
              aria-pressed={tab === 'past'}
              onClick={() => setTab('past')}
            >
              Passées <span className="tnum">({past.length})</span>
            </button>
          </div>

          {shown.length === 0 ? (
            <div className="db-card">
              <div className="db-empty db-empty-center">
                <span className="db-empty-icon">
                  <Icon name="moon" size={24} strokeWidth={1.9} />
                </span>
                <div className="db-empty-title">
                  {tab === 'upcoming' ? 'Aucune garde à venir' : 'Aucune garde passée'}
                </div>
                {tab === 'upcoming' && (
                  <div className="db-muted">
                    Vos gardes apparaissent ici dès la publication d&apos;un planning.{' '}
                    <Link to="/plannings">Voir mes plannings</Link>
                  </div>
                )}
              </div>
            </div>
          ) : (
            <div className="db-card">
              {groupByMonth(shown).map((group) => (
                <section key={group.label} aria-label={group.label}>
                  <h2 className="duties-month">{group.label}</h2>
                  <ul className="db-list">
                    {group.duties.map((duty) => (
                      <li key={duty.key} className="duty-item">
                        <DutyRow duty={duty} today={tab === 'upcoming' ? today : undefined} />
                        {tab === 'upcoming' && (duty.swapRequestStableId || duty.swappable) && (
                          <div className="duty-item__swap">
                            {duty.swapRequestStableId ? (
                              <>
                                {/* The unit is listed because the user STILL holds it: never shown as transferred. */}
                                <span className="db-pill swap-pill swap-pill--info">
                                  <Icon name="swap" size={14} strokeWidth={2} />
                                  Échange demandé
                                </span>
                                <Link
                                  to={`/swaps?request=${duty.swapRequestStableId}`}
                                  className="btn btn--ghost btn--sm"
                                >
                                  Voir la demande
                                </Link>
                              </>
                            ) : (
                              <button
                                type="button"
                                className="btn btn--secondary btn--sm"
                                onClick={() => setSwapping(duty.dutyStableId)}
                              >
                                <Icon name="swap" size={16} strokeWidth={2} />
                                Échanger ma garde
                              </button>
                            )}
                          </div>
                        )}
                      </li>
                    ))}
                  </ul>
                </section>
              ))}
            </div>
          )}
        </>
      )}

      {subscribing && <CalendarSubscriptionModal onClose={() => setSubscribing(false)} />}
      {swapping && (
        <SwapRequestDialog
          dutyStableId={swapping}
          onClose={() => setSwapping(null)}
          onCreated={(request) => navigate(`/swaps?request=${request.stableId}`)}
        />
      )}
    </section>
  )
}
