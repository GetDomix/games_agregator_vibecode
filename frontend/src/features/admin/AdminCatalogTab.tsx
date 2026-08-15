import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../../shared/api/client'
import type { AdminOverview, AdminTabProps } from './types'

const allSources = ['steam', 'plati', 'ggsel'] as const
const sourceLabels: Record<string, string> = { steam: 'Steam', plati: 'Plati', ggsel: 'GGsel' }

function dateTime(value?: string | null) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

function errorMessage(error: unknown, fallback: string) {
  return error instanceof Error ? error.message : fallback
}

export function AdminCatalogTab({ onError, onNotice }: AdminTabProps) {
  const [overview, setOverview] = useState<AdminOverview | null>(null)
  const [appid, setAppid] = useState('')
  const [sources, setSources] = useState<string[]>([...allSources])
  const [busy, setBusy] = useState(false)
  const mounted = useRef(false)
  const requestGeneration = useRef(0)

  const load = useCallback(async () => {
    const request = ++requestGeneration.current
    try {
      const response = await api<AdminOverview>('/api/admin/overview')
      if (mounted.current && request === requestGeneration.current) setOverview(response)
    } catch (error) {
      if (mounted.current && request === requestGeneration.current) {
        onError(errorMessage(error, 'Не удалось загрузить состояние каталога'))
      }
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

  const requestRefresh = async (event: FormEvent) => {
    event.preventDefault()
    if (!/^\d+$/.test(appid) || sources.length === 0) {
      onError('Укажите Steam AppID и хотя бы один источник')
      return
    }
    setBusy(true)
    try {
      await api(`/api/admin/games/${appid}/refresh`, {
        method: 'POST',
        body: JSON.stringify({ sources }),
      })
      if (!mounted.current) return
      onNotice(`Обновление AppID ${appid} поставлено в очередь`)
      setAppid('')
      await load()
    } catch (error) {
      if (mounted.current) onError(errorMessage(error, 'Не удалось поставить обновление в очередь'))
    } finally {
      if (mounted.current) setBusy(false)
    }
  }

  const toggleSource = (source: string) => {
    setSources((current) => current.includes(source) ? current.filter((item) => item !== source) : [...current, source])
  }

  return (
    <div className="admin-tab-stack">
      <div className="admin-tab-heading"><div><p className="eyebrow">Каталог</p><h3>Игры и источники цен</h3></div></div>
      <div className="admin-layout admin-layout-main">
        <article className="panel admin-panel-card">
          <div className="admin-card-title"><div><p className="eyebrow">Ручное действие</p><h3>Обновить игру</h3></div></div>
          <form className="admin-refresh-form" onSubmit={requestRefresh}>
            <label htmlFor="admin-appid"><span>Steam AppID</span></label>
            <input id="admin-appid" value={appid} onChange={(event) => setAppid(event.target.value.trim())} inputMode="numeric" placeholder="1091500" />
            <fieldset>
              <legend>Источники</legend>
              {allSources.map((source) => (
                <label key={source}><input type="checkbox" checked={sources.includes(source)} onChange={() => toggleSource(source)} /> {sourceLabels[source]}</label>
              ))}
            </fieldset>
            <button className="btn primary" disabled={busy} type="submit">{busy ? 'Ставим в очередь…' : 'Поставить в очередь'}</button>
          </form>
        </article>

        <article className="panel admin-panel-card admin-failures">
          <div className="admin-card-title"><div><p className="eyebrow">Требует внимания</p><h3>Ошибки источников</h3></div><span className="admin-count">{overview?.recent_source_failures.length ?? 0}</span></div>
          {overview?.recent_source_failures.length ? (
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

      <article className="panel admin-panel-card">
        <div className="admin-card-title"><div><p className="eyebrow">Пробелы каталога</p><h3>Запросы без цены</h3></div></div>
        <div className="admin-query-list">
          {overview?.problem_searches.map((item) => <div key={item.query}><span>{item.query}<small>{dateTime(item.last_seen_at)}</small></span><b>{item.searches}</b></div>)}
          {!overview?.problem_searches.length && <p className="admin-empty">Проблемных запросов нет.</p>}
        </div>
      </article>
    </div>
  )
}
