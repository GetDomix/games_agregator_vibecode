import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../api'
import type { AdminAuditEntry, AdminTabProps, AuditPage } from './types'

const actionLabels: Record<string, string> = {
  'admin.role_changed': 'Изменена роль в команде',
  'game.refresh_requested': 'Запрошено обновление игры',
}

function dateTime(value?: string | null) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

function auditContext(item: AdminAuditEntry) {
  if (item.action === 'admin.role_changed') {
    const from = typeof item.context.old_role === 'string' ? item.context.old_role : '—'
    const to = typeof item.context.new_role === 'string' ? item.context.new_role : '—'
    return `${from} → ${to}`
  }
  if (item.action === 'game.refresh_requested' && Array.isArray(item.context.sources)) {
    return item.context.sources.filter((value): value is string => typeof value === 'string').join(', ')
  }
  return ''
}

export function AdminAuditTab({ onError }: AdminTabProps) {
  const [page, setPage] = useState<AuditPage | null>(null)
  const [loading, setLoading] = useState(true)
  const mounted = useRef(false)
  const requestGeneration = useRef(0)

  const load = useCallback(async (pageNumber: number) => {
    const request = ++requestGeneration.current
    setLoading(true)
    try {
      const response = await api<AuditPage>(`/api/admin/audit?page=${pageNumber}&per_page=25`)
      if (mounted.current && request === requestGeneration.current) setPage(response)
    } catch (error) {
      if (mounted.current && request === requestGeneration.current) {
        onError(error instanceof Error ? error.message : 'Не удалось загрузить аудит')
      }
    } finally {
      if (mounted.current && request === requestGeneration.current) setLoading(false)
    }
  }, [onError])

  useEffect(() => {
    mounted.current = true
    void load(1)
    return () => {
      mounted.current = false
      requestGeneration.current += 1
    }
  }, [load])

  return (
    <div className="admin-tab-stack">
      <div className="admin-tab-heading"><div><p className="eyebrow">Контроль</p><h3>Журнал действий</h3></div><span className="admin-count">{page?.total ?? 0}</span></div>
      <article className="panel admin-panel-card">
        <div className="admin-audit-list">
          {page?.data.map((item) => (
            <div key={item.id}>
              <span className="admin-audit-mark" />
              <div>
                <b>{actionLabels[item.action] || item.action}</b>
                <small>{item.actor || 'Системное действие'} · {item.target_type || 'объект'} #{item.target_id || '—'} · {dateTime(item.created_at)}</small>
                {auditContext(item) && <small>{auditContext(item)}</small>}
              </div>
            </div>
          ))}
          {!loading && !page?.data.length && <p className="admin-empty">Действий пока не было.</p>}
          {loading && <p className="admin-empty" aria-live="polite">Загружаем журнал…</p>}
        </div>
        <div className="admin-pagination" aria-label="Страницы аудита">
          <button type="button" className="btn ghost sm" disabled={loading || !page || page.current_page <= 1} onClick={() => void load((page?.current_page ?? 1) - 1)}>Назад</button>
          <span>{page?.current_page ?? 1} / {page?.last_page ?? 1}</span>
          <button type="button" className="btn ghost sm" disabled={loading || !page || page.current_page >= page.last_page} onClick={() => void load((page?.current_page ?? 1) + 1)}>Дальше</button>
        </div>
      </article>
    </div>
  )
}
