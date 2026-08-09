import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../api'

type SourceHealth = {
  source: string
  counts: Record<'pending' | 'fresh' | 'stale' | 'failed', number>
  last_success_at?: string | null
}

type AdminOverview = {
  generated_at: string
  stats: {
    users_total: number
    users_7d: number
    games_total: number
    searches_24h: number
    partner_clicks_7d: number
    alert_events_24h: number
  }
  operations: {
    queue: { pending: number; failed: number }
    sources: SourceHealth[]
    deliveries_24h: Record<'pending' | 'sent' | 'failed' | 'skipped', number>
  }
  recent_source_failures: {
    appid?: number | null
    game_name?: string | null
    source: string
    last_attempt_at?: string | null
    consecutive_failures: number
    error: string
  }[]
  popular_searches_7d: { query: string; searches: number }[]
  problem_searches: { query: string; searches: number; last_seen_at?: string | null }[]
  recent_audit: {
    id: number
    actor?: string | null
    action: string
    target_type?: string | null
    target_id?: string | null
    context?: Record<string, unknown> | null
    created_at?: string | null
  }[]
}

type AdminUser = {
  id: number
  email: string
  display_name: string
  admin_role: 'user' | 'admin' | 'owner'
  can_access_admin: boolean
  can_manage_admin_team: boolean
  telegram_linked: boolean
  radar_enabled: boolean
  favorites_count: number
  searches_count: number
  created_at?: string | null
  last_login_at?: string | null
}

const sourceLabels: Record<string, string> = { steam: 'Steam', plati: 'Plati', ggsel: 'GGsel' }
const actionLabels: Record<string, string> = {
  'user.admin_changed': 'Изменена роль',
  'game.refresh_requested': 'Запрошено обновление',
}

function dateTime(value?: string | null) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

function HealthBadge({ value, label }: { value: number; label: string }) {
  const tone = label === 'ошибок' && value > 0 ? 'danger' : label === 'свежих' ? 'ok' : ''
  return <span className={`admin-health-badge ${tone}`}>{value} {label}</span>
}

export function AdminPanel({ currentUserId }: { currentUserId?: number }) {
  const [overview, setOverview] = useState<AdminOverview | null>(null)
  const [users, setUsers] = useState<AdminUser[]>([])
  const [userQuery, setUserQuery] = useState('')
  const [appid, setAppid] = useState('')
  const [sources, setSources] = useState<string[]>(['steam', 'plati', 'ggsel'])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const loadOverview = useCallback(async () => {
    setError('')
    try {
      setOverview(await api<AdminOverview>('/api/admin/overview'))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Не удалось загрузить админку')
    }
  }, [])

  const loadUsers = useCallback(async (query = '') => {
    try {
      const data = await api<{ items: AdminUser[] }>(`/api/admin/users?q=${encodeURIComponent(query)}`)
      setUsers(data.items)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Не удалось загрузить пользователей')
    }
  }, [])

  useEffect(() => {
    void Promise.all([loadOverview(), loadUsers()])
  }, [loadOverview, loadUsers])

  const refreshAll = async () => {
    setBusy(true)
    await Promise.all([loadOverview(), loadUsers(userQuery)]).finally(() => setBusy(false))
  }

  const searchUsers = (event: FormEvent) => {
    event.preventDefault()
    void loadUsers(userQuery.trim())
  }

  const changeAdmin = async (target: AdminUser) => {
    const next = !target.can_access_admin
    if (!window.confirm(`${next ? 'Назначить' : 'Снять'} администратора: ${target.display_name || target.email}?`)) return
    setBusy(true)
    setError('')
    try {
      await api(`/api/admin/users/${target.id}/admin`, {
        method: 'POST',
        body: JSON.stringify({ is_admin: next }),
      })
      setNotice(next ? 'Администратор назначен' : 'Права администратора сняты')
      await Promise.all([loadUsers(userQuery), loadOverview()])
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Не удалось изменить роль')
    } finally {
      setBusy(false)
    }
  }

  const requestRefresh = async (event: FormEvent) => {
    event.preventDefault()
    if (!/^\d+$/.test(appid) || sources.length === 0) {
      setError('Укажите Steam AppID и хотя бы один источник')
      return
    }
    setBusy(true)
    setError('')
    try {
      await api(`/api/admin/games/${appid}/refresh`, {
        method: 'POST',
        body: JSON.stringify({ sources }),
      })
      setNotice(`Обновление AppID ${appid} поставлено в очередь`)
      setAppid('')
      await loadOverview()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Не удалось поставить обновление в очередь')
    } finally {
      setBusy(false)
    }
  }

  const toggleSource = (source: string) => {
    setSources((current) => current.includes(source) ? current.filter((item) => item !== source) : [...current, source])
  }

  return (
    <section className="section page-enter admin-console">
      <header className="admin-console-head">
        <div>
          <p className="eyebrow">Операционный центр</p>
          <h2>Пульт Игроскана</h2>
          <p className="muted">Источники цен, фоновые задачи, уведомления и поддержка пользователей.</p>
        </div>
        <div className="admin-head-actions">
          {overview && <span className="admin-updated">Срез: {dateTime(overview.generated_at)}</span>}
          <button type="button" className="btn ghost sm" disabled={busy} onClick={() => void refreshAll()}>
            {busy ? 'Обновляем…' : 'Обновить данные'}
          </button>
        </div>
      </header>

      {error && <div className="admin-message danger" role="alert">{error}</div>}
      {notice && <div className="admin-message ok" role="status">{notice}</div>}

      {overview && (
        <>
          <div className="admin-kpis">
            <div className="admin-kpi"><span>Пользователи</span><b>{overview.stats.users_total}</b><small>+{overview.stats.users_7d} за 7 дней</small></div>
            <div className="admin-kpi"><span>Игры в базе</span><b>{overview.stats.games_total}</b><small>канонических карточек</small></div>
            <div className="admin-kpi"><span>Поиски за сутки</span><b>{overview.stats.searches_24h}</b><small>{overview.stats.partner_clicks_7d} переходов за 7 дней</small></div>
            <div className="admin-kpi"><span>Алерты за сутки</span><b>{overview.stats.alert_events_24h}</b><small>{overview.operations.deliveries_24h.sent} доставлено</small></div>
          </div>

          <div className="admin-layout admin-layout-health">
            <article className="panel admin-panel-card">
              <div className="admin-card-title"><div><p className="eyebrow">Инфраструктура</p><h3>Очередь задач</h3></div><span className={`admin-status-dot ${overview.operations.queue.failed ? 'danger' : 'ok'}`} /></div>
              <div className="admin-queue-metrics">
                <div><b>{overview.operations.queue.pending}</b><span>ожидает</span></div>
                <div><b>{overview.operations.queue.failed}</b><span>упало</span></div>
              </div>
            </article>

            <article className="panel admin-panel-card admin-source-card">
              <div className="admin-card-title"><div><p className="eyebrow">Сбор цен</p><h3>Источники</h3></div></div>
              <div className="admin-source-list">
                {overview.operations.sources.map((source) => (
                  <div className="admin-source-row" key={source.source}>
                    <div><b>{sourceLabels[source.source] || source.source}</b><small>успех: {dateTime(source.last_success_at)}</small></div>
                    <div className="admin-health-badges">
                      <HealthBadge value={source.counts.fresh} label="свежих" />
                      <HealthBadge value={source.counts.stale} label="устарело" />
                      <HealthBadge value={source.counts.failed} label="ошибок" />
                    </div>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel admin-panel-card">
              <div className="admin-card-title"><div><p className="eyebrow">Telegram</p><h3>Доставка за 24 часа</h3></div></div>
              <div className="admin-delivery-grid">
                <div><b>{overview.operations.deliveries_24h.sent}</b><span>отправлено</span></div>
                <div><b>{overview.operations.deliveries_24h.pending}</b><span>ожидает</span></div>
                <div><b>{overview.operations.deliveries_24h.failed}</b><span>ошибок</span></div>
                <div><b>{overview.operations.deliveries_24h.skipped}</b><span>пропущено</span></div>
              </div>
            </article>
          </div>

          <div className="admin-layout admin-layout-main">
            <article className="panel admin-panel-card">
              <div className="admin-card-title"><div><p className="eyebrow">Безопасное действие</p><h3>Обновить игру</h3></div></div>
              <form className="admin-refresh-form" onSubmit={requestRefresh}>
                <label><span>Steam AppID</span><input value={appid} onChange={(e) => setAppid(e.target.value.trim())} inputMode="numeric" placeholder="1091500" /></label>
                <fieldset>
                  <legend>Источники</legend>
                  {['steam', 'plati', 'ggsel'].map((source) => (
                    <label key={source}><input type="checkbox" checked={sources.includes(source)} onChange={() => toggleSource(source)} /> {sourceLabels[source]}</label>
                  ))}
                </fieldset>
                <button className="btn primary" disabled={busy} type="submit">Поставить в очередь</button>
              </form>
            </article>

            <article className="panel admin-panel-card admin-failures">
              <div className="admin-card-title"><div><p className="eyebrow">Требует внимания</p><h3>Последние ошибки источников</h3></div><span className="admin-count">{overview.recent_source_failures.length}</span></div>
              {overview.recent_source_failures.length ? (
                <div className="admin-list">
                  {overview.recent_source_failures.map((failure, index) => (
                    <div className="admin-list-row" key={`${failure.appid}-${failure.source}-${index}`}>
                      <div><b>{failure.game_name || `AppID ${failure.appid}`}</b><small>{sourceLabels[failure.source] || failure.source} · {dateTime(failure.last_attempt_at)} · попыток: {failure.consecutive_failures}</small></div>
                      <span title={failure.error}>{failure.error || 'Причина не записана'}</span>
                    </div>
                  ))}
                </div>
              ) : <p className="admin-empty">Активных ошибок нет.</p>}
            </article>
          </div>

          <div className="admin-layout admin-layout-searches">
            <article className="panel admin-panel-card">
              <div className="admin-card-title"><div><p className="eyebrow">Спрос</p><h3>Популярные запросы · 7 дней</h3></div></div>
              <div className="admin-query-list">
                {overview.popular_searches_7d.map((item) => <div key={item.query}><span>{item.query}</span><b>{item.searches}</b></div>)}
                {!overview.popular_searches_7d.length && <p className="admin-empty">Запросов пока нет.</p>}
              </div>
            </article>
            <article className="panel admin-panel-card">
              <div className="admin-card-title"><div><p className="eyebrow">Пробелы каталога</p><h3>Запросы без цены</h3></div></div>
              <div className="admin-query-list">
                {overview.problem_searches.map((item) => <div key={item.query}><span>{item.query}<small>{dateTime(item.last_seen_at)}</small></span><b>{item.searches}</b></div>)}
                {!overview.problem_searches.length && <p className="admin-empty">Проблемных запросов нет.</p>}
              </div>
            </article>
          </div>
        </>
      )}

      <article className="panel admin-panel-card admin-users-card">
        <div className="admin-card-title"><div><p className="eyebrow">Поддержка</p><h3>Пользователи</h3></div></div>
        <form className="admin-user-search" onSubmit={searchUsers}>
          <input value={userQuery} onChange={(e) => setUserQuery(e.target.value)} placeholder="Email, имя или ID" />
          <button type="submit" className="btn ghost sm">Найти</button>
        </form>
        <div className="admin-table-wrap">
          <table className="admin-table">
            <thead><tr><th>Пользователь</th><th>Активность</th><th>Связи</th><th>Роль</th></tr></thead>
            <tbody>
              {users.map((item) => (
                <tr key={item.id}>
                  <td><b>{item.display_name || 'Без имени'}</b><span className="offer-meta">#{item.id} · {item.email}</span></td>
                  <td>{item.searches_count} поисков<span className="offer-meta">{item.favorites_count} в избранном · вход {dateTime(item.last_login_at)}</span></td>
                  <td>{item.telegram_linked ? 'Telegram' : '—'}{item.telegram_linked && <span className="offer-meta">Радар {item.radar_enabled ? 'включён' : 'выключен'}</span>}</td>
                  <td><button type="button" className={`btn ghost sm ${item.can_access_admin ? 'danger' : ''}`} disabled={busy || item.id === currentUserId} onClick={() => void changeAdmin(item)}>{item.can_access_admin ? 'Снять права' : 'Назначить'}</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </article>

      {overview && (
        <article className="panel admin-panel-card">
          <div className="admin-card-title"><div><p className="eyebrow">Контроль</p><h3>Журнал действий</h3></div></div>
          <div className="admin-audit-list">
            {overview.recent_audit.map((item) => (
              <div key={item.id}><span className="admin-audit-mark" /><div><b>{actionLabels[item.action] || item.action}</b><small>{item.actor || 'Системный администратор'} · {item.target_type} #{item.target_id} · {dateTime(item.created_at)}</small></div></div>
            ))}
            {!overview.recent_audit.length && <p className="admin-empty">Действий пока не было.</p>}
          </div>
        </article>
      )}
    </section>
  )
}
