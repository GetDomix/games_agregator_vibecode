import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../api'
import type { User } from '../api'
import { RoleChangeDialog } from './RoleChangeDialog'
import type { AdminRole, AdminTabProps, SafeAdminUser, TeamResponse, UserDirectoryResponse } from './types'

type Transition = { target: SafeAdminUser; nextRole: AdminRole }

const roleLabels: Record<AdminRole, string> = {
  user: 'Пользователь',
  admin: 'Администратор',
  owner: 'Владелец',
}

function errorMessage(error: unknown, fallback: string) {
  return error instanceof Error ? error.message : fallback
}

export function AdminTeamTab({ currentUser, onError, onNotice }: AdminTabProps & { currentUser: User }) {
  const [team, setTeam] = useState<SafeAdminUser[]>([])
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<SafeAdminUser[]>([])
  const [drafts, setDrafts] = useState<Record<number, AdminRole>>({})
  const [transition, setTransition] = useState<Transition | null>(null)
  const [loading, setLoading] = useState(true)
  const [searching, setSearching] = useState(false)

  const loadTeam = useCallback(async () => {
    setLoading(true)
    try {
      const response = await api<TeamResponse>('/api/admin/team')
      setTeam(response.items)
    } catch (error) {
      onError(errorMessage(error, 'Не удалось загрузить команду'))
    } finally {
      setLoading(false)
    }
  }, [onError])

  useEffect(() => { void loadTeam() }, [loadTeam])

  const search = async (event: FormEvent) => {
    event.preventDefault()
    const term = query.trim()
    if (!term) {
      setResults([])
      return
    }
    setSearching(true)
    try {
      const response = await api<UserDirectoryResponse>(`/api/admin/users?q=${encodeURIComponent(term)}`)
      setResults(response.data)
    } catch (error) {
      onError(errorMessage(error, 'Не удалось найти пользователя'))
    } finally {
      setSearching(false)
    }
  }

  const chosenRole = (user: SafeAdminUser) => drafts[user.id] ?? user.admin_role

  const openTransition = (target: SafeAdminUser) => {
    const nextRole = chosenRole(target)
    if (nextRole === target.admin_role) {
      onError('Выберите новую роль пользователя')
      return
    }
    setTransition({ target, nextRole })
  }

  const confirmTransition = async (currentPassword?: string) => {
    if (!transition) return
    const { target, nextRole } = transition
    await api(`/api/admin/team/${target.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        role: nextRole,
        ...(currentPassword ? { current_password: currentPassword } : {}),
      }),
    })
    setTransition(null)
    setDrafts((current) => {
      const next = { ...current }
      delete next[target.id]
      return next
    })
    onNotice(`${target.display_name || target.email}: роль «${roleLabels[nextRole]}» применена`)
    await loadTeam()
    if (query.trim()) {
      const response = await api<UserDirectoryResponse>(`/api/admin/users?q=${encodeURIComponent(query.trim())}`)
      setResults(response.data)
    }
  }

  const renderRoleControl = (item: SafeAdminUser) => (
    <div className="admin-role-control">
      <label className="sr-only" htmlFor={`admin-role-${item.id}`}>Новая роль для {item.display_name || item.email}</label>
      <select
        id={`admin-role-${item.id}`}
        value={chosenRole(item)}
        onChange={(event) => setDrafts((current) => ({ ...current, [item.id]: event.target.value as AdminRole }))}
      >
        <option value="user">Пользователь</option>
        <option value="admin">Администратор</option>
        <option value="owner">Владелец</option>
      </select>
      <button type="button" className="btn ghost sm" disabled={chosenRole(item) === item.admin_role} onClick={() => openTransition(item)}>Изменить</button>
    </div>
  )

  const candidates = results.filter((candidate) => !team.some((member) => member.id === candidate.id))

  return (
    <div className="admin-tab-stack">
      <div className="admin-tab-heading">
        <div><p className="eyebrow">Только для владельца</p><h3>Команда сайта</h3></div>
        <span className="admin-count" aria-label={`Участников команды: ${team.length}`}>{team.length}</span>
      </div>
      <p className="admin-team-note">Назначайте администраторов и владельцев точечно. Любая смена роли завершает активные сессии выбранного пользователя.</p>

      <article className="panel admin-panel-card">
        <div className="admin-card-title"><div><p className="eyebrow">Действующий доступ</p><h3>Владельцы и администраторы</h3></div></div>
        <div className="admin-table-wrap">
          <table className="admin-table">
            <thead><tr><th>Участник</th><th>Текущая роль</th><th>Новая роль</th></tr></thead>
            <tbody>
              {team.map((item) => (
                <tr key={item.id}>
                  <td><b>{item.display_name || 'Без имени'}{item.id === currentUser.id ? ' · вы' : ''}</b><span className="offer-meta">#{item.id} · {item.email}</span></td>
                  <td><span className={`admin-role-badge ${item.admin_role}`}>{roleLabels[item.admin_role]}</span></td>
                  <td>{renderRoleControl(item)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {!loading && team.length === 0 && <p className="admin-empty">Команда не найдена. Проверьте конфигурацию владельцев.</p>}
          {loading && <p className="admin-empty" aria-live="polite">Загружаем команду…</p>}
        </div>
      </article>

      <article className="panel admin-panel-card">
        <div className="admin-card-title"><div><p className="eyebrow">Добавить участника</p><h3>Найти пользователя</h3></div></div>
        <form className="admin-user-search" onSubmit={(event) => void search(event)} role="search">
          <label className="sr-only" htmlFor="admin-team-query">Email, имя или ID пользователя</label>
          <input id="admin-team-query" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Email, имя или ID" />
          <button type="submit" className="btn ghost sm" disabled={searching}>{searching ? 'Ищем…' : 'Найти'}</button>
        </form>
        {candidates.length > 0 && (
          <div className="admin-table-wrap">
            <table className="admin-table">
              <thead><tr><th>Пользователь</th><th>Текущая роль</th><th>Назначить</th></tr></thead>
              <tbody>
                {candidates.map((item) => (
                  <tr key={item.id}>
                    <td><b>{item.display_name || 'Без имени'}</b><span className="offer-meta">#{item.id} · {item.email}</span></td>
                    <td>{roleLabels[item.admin_role]}</td>
                    <td>{renderRoleControl(item)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {!searching && query.trim() && results.length === 0 && <p className="admin-empty">Пользователь не найден.</p>}
        {!searching && results.length > 0 && candidates.length === 0 && <p className="admin-empty">Все найденные пользователи уже входят в команду.</p>}
      </article>

      {transition && (
        <RoleChangeDialog
          target={transition.target}
          nextRole={transition.nextRole}
          onCancel={() => setTransition(null)}
          onConfirm={confirmTransition}
        />
      )}
    </div>
  )
}
