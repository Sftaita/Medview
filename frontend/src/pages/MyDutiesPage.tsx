import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '../components/Icon'
import '../features/dashboard/dashboard.css'
import { startOfDay } from '../features/dashboard/dates'
import { fetchMyDuties } from '../features/duties/api'
import { CalendarSubscriptionModal } from '../features/duties/CalendarSubscriptionModal'
import '../features/duties/duties.css'
import { DutyRow } from '../features/duties/DutyRow'
import { groupByMonth, splitDuties } from '../features/duties/dutyDates'
import type { MyDuty } from '../features/duties/types'

type Tab = 'upcoming' | 'past'

/**
 * "Mes gardes" (docs/decisions.md D168): every duty the signed-in user holds on a published line, across all
 * their plannings — upcoming first, past ones on demand. Read only: changes happen in each planning.
 */
export function MyDutiesPage() {
  const [duties, setDuties] = useState<MyDuty[] | null>(null)
  const [error, setError] = useState(false)
  const [tab, setTab] = useState<Tab>('upcoming')
  const [subscribing, setSubscribing] = useState(false)
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
                      <li key={duty.key}>
                        <DutyRow duty={duty} today={tab === 'upcoming' ? today : undefined} />
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
    </section>
  )
}
