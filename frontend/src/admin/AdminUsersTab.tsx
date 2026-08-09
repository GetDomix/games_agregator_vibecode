import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../api'
import type { AdminTabProps, SafeAdminUser, UserDirectoryResponse } from './types'

function dateTime(value?: string | null) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

function roleLabel(user: SafeAdminUser) {
  if (user.admin_role === 'owner') return 'Владелец'
  if (user.admin_role === 'admin') return 'Администратор'
  return 'Пользователь'
}

export function AdminUsersTab({ onError }: AdminTabProps) {
  const [query, setQuery] = useState('')
  const [submittedQuery, setSubmittedQuery] = useState('')
  const [users, setUsers] = useState<SafeAdminUser[]>([])
  const [meta, setMeta] = useState({ page: 1, per_page: 30, total: 0 })
  const [loading, setLoading] = useState(true)

  const load = useCallback(async (term: string, page: number) => {
    setLoading(true)
    try {
      const response = await api<UserDirectoryResponse>(`/api/admin/users?q=${encodeURIComponent(term)}&page=${page}`)
      setUsers(response.data)
      setMeta(response.meta)
    } catch (error) {
      onError(error instanceof Error ? error.message : 'Не удалось загрузить пользователей')
    } finally {
      setLoading(false)
    }
  }, [onError])

  useEffect(() => { void load('', 1) }, [load])

  const search = (event: FormEvent) => {
    event.preventDefault()
    const term = query.trim()
    setSubmittedQuery(term)
    void load(term, 1)
  }

  const lastPage = Math.max(1, Math.ceil(meta.total / meta.per_page))

  return (
    <div className="admin-tab-stack">
      <div className="admin-tab-heading">
        <div><p className="eyebrow">Поддержка</p><h3>Пользователи</h3></div>
        <span className="admin-count" aria-label={`Всего пользователей: ${meta.total}`}>{meta.total}</span>
      </div>
      <article className="panel admin-panel-card admin-users-card">
        <form className="admin-user-search" onSubmit={search} role="search">
          <label className="sr-only" htmlFor="admin-user-query">Email, имя или ID</label>
          <input id="admin-user-query" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Email, имя или ID" />
          <button type="submit" className="btn ghost sm" disabled={loading}>Найти</button>
        </form>
        <div className="admin-table-wrap">
          <table className="admin-table">
            <thead><tr><th>Пользователь</th><th>Активность</th><th>Связи</th><th>Роль</th></tr></thead>
            <tbody>
              {users.map((item) => (
                <tr key={item.id}>
                  <td><b>{item.display_name || 'Без имени'}</b><span className="offer-meta">#{item.id} · {item.email}</span></td>
                  <td>{item.searches_count ?? 0} поисков<span className="offer-meta">{item.favorites_count ?? 0} в избранном · вход {dateTime(item.last_login_at)}</span></td>
                  <td>{item.telegram_linked ? 'Telegram подключён' : 'Telegram не подключён'}{item.telegram_linked && <span className="offer-meta">Радар {item.radar_enabled ? 'включён' : 'выключен'}</span>}</td>
                  <td><span className={`admin-role-badge ${item.admin_role}`}>{roleLabel(item)}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
          {!loading && users.length === 0 && <p className="admin-empty">По запросу ничего не найдено.</p>}
          {loading && <p className="admin-empty" aria-live="polite">Загружаем пользователей…</p>}
        </div>
        <div className="admin-pagination" aria-label="Страницы пользователей">
          <button type="button" className="btn ghost sm" disabled={loading || meta.page <= 1} onClick={() => void load(submittedQuery, meta.page - 1)}>Назад</button>
          <span>{meta.page} / {lastPage}</span>
          <button type="button" className="btn ghost sm" disabled={loading || meta.page >= lastPage} onClick={() => void load(submittedQuery, meta.page + 1)}>Дальше</button>
        </div>
      </article>
    </div>
  )
}
