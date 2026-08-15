import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../../shared/api/client'
import type { AdminOverview, AdminTabProps } from './types'

const sourceLabels: Record<string, string> = { steam: 'Steam', plati: 'Plati', ggsel: 'GGsel' }

function dateTime(value?: string | null) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

function errorMessage(error: unknown, fallback: string) {
  return error instanceof Error ? error.message : fallback
}

function HealthBadge({ value, label }: { value: number; label: string }) {
  const tone = label === 'ошибок' && value > 0 ? 'danger' : label === 'свежих' ? 'ok' : ''
  return <span className={`admin-health-badge ${tone}`}>{value} {label}</span>
}

export function AdminOverviewTab({ onError }: AdminTabProps) {
  const [overview, setOverview] = useState<AdminOverview | null>(null)
  const [loading, setLoading] = useState(true)
  const mounted = useRef(false)
  const requestGeneration = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestGeneration.current
    setLoading(true)
    try {
      const response = await api<AdminOverview>('/api/admin/overview')
      if (mounted.current && request === requestGeneration.current) setOverview(response)
    } catch (error) {
      if (mounted.current && request === requestGeneration.current) {
        onError(errorMessage(error, 'Не удалось загрузить обзор'))
      }
    } finally {
      if (mounted.current && request === requestGeneration.current) setLoading(false)
    }
  }, [onError])

  useEffect(() => {
    mounted.current = true
    void load()
    return () => {
      mounted.current = false
      requestGeneration.current += 1
    }
  }, [load])

  if (!overview && loading) return <p className="admin-empty" aria-live="polite">Собираем состояние системы…</p>
  if (!overview) return <p className="admin-empty">Обзор временно недоступен. Попробуйте обновить данные.</p>

  return (
    <div className="admin-tab-stack">
      <div className="admin-tab-heading">
        <div><p className="eyebrow">Срез системы</p><h3>Главные сигналы</h3></div>
        <div className="admin-head-actions">
          <span className="admin-updated">{dateTime(overview.generated_at)}</span>
          <button type="button" className="btn ghost sm" disabled={loading} onClick={() => void load()}>
            {loading ? 'Обновляем…' : 'Обновить'}
          </button>
        </div>
      </div>

      <div className="admin-kpis">
        <div className="admin-kpi"><span>Пользователи</span><b>{overview.stats.users_total}</b><small>+{overview.stats.users_7d} за 7 дней</small></div>
        <div className="admin-kpi"><span>Игры в базе</span><b>{overview.stats.games_total}</b><small>карточек каталога</small></div>
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
          <div className="admin-card-title"><div><p className="eyebrow">Telegram</p><h3>Доставка · 24 часа</h3></div></div>
          <div className="admin-delivery-grid">
            <div><b>{overview.operations.deliveries_24h.sent}</b><span>отправлено</span></div>
            <div><b>{overview.operations.deliveries_24h.pending}</b><span>ожидает</span></div>
            <div><b>{overview.operations.deliveries_24h.failed}</b><span>ошибок</span></div>
            <div><b>{overview.operations.deliveries_24h.skipped}</b><span>пропущено</span></div>
          </div>
        </article>
      </div>

      <article className="panel admin-panel-card">
        <div className="admin-card-title"><div><p className="eyebrow">Спрос</p><h3>Популярные запросы · 7 дней</h3></div></div>
        <div className="admin-query-list">
          {overview.popular_searches_7d.map((item) => <div key={item.query}><span>{item.query}</span><b>{item.searches}</b></div>)}
          {!overview.popular_searches_7d.length && <p className="admin-empty">Новых запросов пока нет.</p>}
        </div>
      </article>
    </div>
  )
}
