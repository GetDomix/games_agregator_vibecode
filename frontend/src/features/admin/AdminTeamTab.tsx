import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../../shared/api/client'
import type { User } from '../../shared/api/client'
import { RoleChangeDialog } from './RoleChangeDialog'
import type { AdminRole, AdminTabProps, AdminTeamUser, SafeAdminUser, TeamResponse, UserDirectoryResponse } from './types'

type Transition = { target: SafeAdminUser; nextRole: AdminRole }
type EditableUser = SafeAdminUser | AdminTeamUser

const roleLabels: Record<AdminRole, string> = {
  user: 'Пользователь',
  admin: 'Администратор',
  owner: 'Владелец',
}

function errorMessage(error: unknown, fallback: string) {
  return error instanceof Error ? error.message : fallback
}

function userName(user: SafeAdminUser) {
  return user.display_name || user.email || 'Без имени'
}

export function AdminTeamTab({ currentUser, onError, onNotice }: AdminTabProps & { currentUser: User }) {
  const [team, setTeam] = useState<AdminTeamUser[]>([])
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<SafeAdminUser[]>([])
  const [drafts, setDrafts] = useState<Record<number, AdminRole>>({})
  const [transition, setTransition] = useState<Transition | null>(null)
  const [loading, setLoading] = useState(true)
  const [searching, setSearching] = useState(false)
  const focusFallbackRef = useRef<HTMLHeadingElement>(null)
  const mounted = useRef(true)
  const teamRequest = useRef(0)
  const searchRequest = useRef(0)

  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
      teamRequest.current += 1
      searchRequest.current += 1
    }
  }, [])

  const loadTeam = useCallback(async () => {
    const request = ++teamRequest.current
    setLoading(true)
    try {
      const response = await api<TeamResponse>('/api/admin/team')
      if (!mounted.current || request !== teamRequest.current) return false
      setTeam(response.items)
      return true
    } catch (error) {
      if (mounted.current && request === teamRequest.current) {
        onError(errorMessage(error, 'Не удалось загрузить команду'))
      }
      return false
    } finally {
      if (mounted.current && request === teamRequest.current) setLoading(false)
    }
  }, [onError])

  useEffect(() => { void loadTeam() }, [loadTeam])

  const loadSearch = async (term: string) => {
    const request = ++searchRequest.current
    setSearching(true)
    try {
      const response = await api<UserDirectoryResponse>(`/api/admin/users?q=${encodeURIComponent(term)}`)
      if (!mounted.current || request !== searchRequest.current) return false
      setResults(response.data)
      return true
    } catch (error) {
      if (mounted.current && request === searchRequest.current) {
        onError(errorMessage(error, 'Не удалось найти пользователя'))
      }
      return false
    } finally {
      if (mounted.current && request === searchRequest.current) setSearching(false)
    }
  }

  const search = async (event: FormEvent) => {
    event.preventDefault()
    const term = query.trim()
    if (!term) {
      searchRequest.current += 1
      setResults([])
      return
    }
    await loadSearch(term)
  }

  const chosenRole = (user: SafeAdminUser) => drafts[user.id] ?? user.admin_role
  const effectiveOwnerCount = team.filter((member) => member.admin_role === 'owner').length

  const demotionReason = (user: EditableUser) => {
    if (user.admin_role !== 'owner') return null
    if ('is_server_managed_owner' in user && user.is_server_managed_owner) {
      return 'Этот владелец управляется серверной конфигурацией'
    }
    if (effectiveOwnerCount <= 1) return 'Нельзя понизить единственного эффективного владельца'
    return null
  }

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
    if (!mounted.current) return

    setDrafts((current) => {
      const next = { ...current }
      delete next[target.id]
      return next
    })
    onNotice(`${userName(target)}: роль «${roleLabels[nextRole]}» применена`)
    await loadTeam()
    if (query.trim() && mounted.current) await loadSearch(query.trim())
  }

  const renderRoleControl = (item: EditableUser) => {
    const restriction = demotionReason(item)
    const reasonId = restriction ? `admin-role-reason-${item.id}` : undefined
    return (
      <div className="admin-role-control-wrap">
        <div className="admin-role-control">
          <label className="sr-only" htmlFor={`admin-role-${item.id}`}>Новая роль для {userName(item)}</label>
          <select
            id={`admin-role-${item.id}`}
            aria-describedby={reasonId}
            value={chosenRole(item)}
            onChange={(event) => setDrafts((current) => ({ ...current, [item.id]: event.target.value as AdminRole }))}
          >
            <option value="user" disabled={Boolean(restriction)}>Пользователь</option>
            <option value="admin" disabled={Boolean(restriction)}>Администратор</option>
            <option value="owner">Владелец</option>
          </select>
          <button type="button" className="btn ghost sm" disabled={chosenRole(item) === item.admin_role} onClick={() => openTransition(item)}>Изменить</button>
        </div>
        {restriction && <small id={reasonId} className="admin-role-reason">{restriction}</small>}
      </div>
    )
  }

  const candidates = results.filter((candidate) => !team.some((member) => member.id === candidate.id))

  return (
    <div className="admin-tab-stack">
      <div className="admin-tab-heading">
        <div><p className="eyebrow">Только для владельца</p><h3 ref={focusFallbackRef} tabIndex={-1}>Команда сайта</h3></div>
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
                  <td><b>{userName(item)}{item.id === currentUser.id ? ' · вы' : ''}</b><span className="offer-meta">#{item.id} · {item.email || 'Без email'}</span></td>
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
                    <td><b>{userName(item)}</b><span className="offer-meta">#{item.id} · {item.email || 'Без email'}</span></td>
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
          onSuccess={() => setTransition(null)}
          returnFocusFallbackRef={focusFallbackRef}
        />
      )}
    </div>
  )
}
