import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Icon } from '../../components/Icon'
import { fetchUsers, type UserListParams } from '../../features/admin/api'
import { AdminPageHeader, LoadState, Pagination, SegmentedChoice } from '../../features/admin/components'
import { formatDate, formatRelative } from '../../features/admin/format'
import { useAdminData } from '../../features/admin/useAdminData'

type Status = NonNullable<UserListParams['status']>
type Sort = NonNullable<UserListParams['sort']>

const SORTS: { value: string; label: string }[] = [
  { value: 'createdAt:desc', label: 'Inscription (récente d’abord)' },
  { value: 'createdAt:asc', label: 'Inscription (ancienne d’abord)' },
  { value: 'lastActivity:desc', label: 'Dernière activité' },
  { value: 'name:asc', label: 'Nom (A → Z)' },
  { value: 'email:asc', label: 'Email (A → Z)' },
]

/**
 * "Utilisateurs": every account, searched, filtered, sorted and paginated by the backend. Filters live in the
 * URL so a view can be shared and survives a return from a user's page.
 */
export function AdminUsersPage() {
  const [params, setParams] = useSearchParams()
  const search = params.get('search') ?? ''
  const status = (params.get('status') ?? 'all') as Status
  const sortKey = params.get('sort') ?? 'createdAt:desc'
  const page = Math.max(1, Number(params.get('page') ?? '1') || 1)
  const [draft, setDraft] = useState(search)

  const [sort, direction] = sortKey.split(':') as [Sort, 'asc' | 'desc']
  const users = useAdminData(
    () => fetchUsers({ search, status, sort, direction, page, perPage: 25 }),
    [search, status, sortKey, page],
  )

  function update(changes: Record<string, string | null>) {
    const next = new URLSearchParams(params)
    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === '') next.delete(key)
      else next.set(key, value)
    }
    if (!('page' in changes)) next.delete('page')
    setParams(next, { replace: true })
  }

  // Search as you type, debounced; the request runs once the URL changes.
  useEffect(() => {
    if (draft === search) return
    const timer = window.setTimeout(() => update({ search: draft.trim() }), 300)
    return () => window.clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [draft])

  return (
    <section className="page adm-page">
      <AdminPageHeader
        title="Utilisateurs"
        lead="Comptes MedVue : état, ancienneté et dernière activité connue."
      />

      <div className="adm-toolbar">
        <label className="adm-search">
          <span className="sr-only">Rechercher par nom ou email</span>
          <Icon name="search" size={18} strokeWidth={2} />
          <input
            type="search"
            className="field__input"
            placeholder="Rechercher par nom ou email"
            value={draft}
            maxLength={100}
            onChange={(event) => setDraft(event.target.value)}
          />
        </label>
        <SegmentedChoice
          label="État du compte"
          value={status}
          options={[
            { value: 'all', label: 'Tous' },
            { value: 'active', label: 'Actifs' },
            { value: 'disabled', label: 'Désactivés' },
          ]}
          onChange={(value) => update({ status: value === 'all' ? null : value })}
        />
        <label className="adm-select">
          <span className="sr-only">Trier</span>
          <select
            className="field__input"
            value={sortKey}
            onChange={(event) => update({ sort: event.target.value })}
          >
            {SORTS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
      </div>

      <LoadState loading={users.loading && !users.data} error={users.error} onRetry={users.reload} />
      {users.data && (
        <div className="card card--flush" aria-busy={users.loading}>
          {users.data.items.length === 0 ? (
            <p className="adm-empty">Aucun compte ne correspond à ces critères.</p>
          ) : (
            <div className="adm-table-wrap">
              <table className="adm-table adm-table--users">
                <thead>
                  <tr>
                    <th scope="col">Nom</th>
                    <th scope="col">Email</th>
                    <th scope="col">Téléphone</th>
                    <th scope="col">Inscription</th>
                    <th scope="col">État</th>
                    <th scope="col">Dernière activité</th>
                    <th scope="col" className="adm-num">
                      Plannings
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {users.data.items.map((user) => (
                    <tr key={user.stableId}>
                      <th scope="row" data-label="Nom">
                        <Link to={`/admin/users/${user.stableId}`} className="adm-link">
                          {user.firstName} {user.lastName}
                        </Link>
                        {user.platformAdmin && <span className="tag tag--blue adm-inline-tag">Admin</span>}
                      </th>
                      <td data-label="Email" className="adm-ellipsis">
                        {user.email}
                      </td>
                      <td data-label="Téléphone" className="tnum">
                        {user.phone ?? '—'}
                      </td>
                      <td data-label="Inscription">{formatDate(user.createdAt)}</td>
                      <td data-label="État">
                        {user.active ? (
                          <span className="tag tag--green">Actif</span>
                        ) : (
                          <span className="tag tag--red">Désactivé</span>
                        )}
                      </td>
                      <td data-label="Dernière activité">{formatRelative(user.lastActivityAt)}</td>
                      <td data-label="Plannings" className="adm-num tnum">
                        {user.planningCount}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <Pagination
            page={users.data.page}
            pageCount={users.data.pageCount}
            total={users.data.total}
            onChange={(next) => update({ page: String(next) })}
          />
        </div>
      )}
      <p className="adm-footnote">
        « Plannings » : plannings dans lesquels la personne a une participation en cours. « Dernière activité
        » : dernière ouverture ou prolongation de session connue.
      </p>
    </section>
  )
}
