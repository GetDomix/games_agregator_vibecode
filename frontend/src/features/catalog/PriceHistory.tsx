import { useEffect, useId, useMemo, useState } from 'react'
import type { KeyboardEvent } from 'react'
import { api } from '../../shared/api/client'
import { useLocale } from '../../shared/i18n/LocaleProvider'

type PriceHistoryCurrent = {
  price_rub: number
  source: 'steam' | 'plati' | 'ggsel'
  offer_kind: 'official' | 'key'
  observed_at?: string | null
}

type PriceChange = {
  source: 'steam' | 'plati' | 'ggsel'
  offer_kind: 'official' | 'key'
  previous_price_rub: number
  price_rub: number
  change_percent: number | null
  observed_at: string
}

type PriceHistoryData = {
  period_days: number
  available_periods: number[]
  current: PriceHistoryCurrent | null
  statistics: {
    minimum_price_rub: number | null
    median_price_rub: number | null
  }
  coverage: {
    observations: number
    checks: number
    observed_days: number
    started_at?: string | null
    sufficient: boolean
  }
  verdict: 'low' | 'typical' | 'high' | 'insufficient'
  changes?: PriceChange[]
}

type PriceHistoryProps = {
  appid: number
  authenticated: boolean
  refreshKey?: string
  onRequireAuth: () => void
}

const SOURCE_LABELS = { steam: 'Steam', plati: 'Plati', ggsel: 'GGsel' } as const

function percentDifference(value: number | null, reference: number | null): number | null {
  if (value == null || reference == null || reference <= 0) return null
  return Math.round(((value - reference) / reference) * 100)
}

function differenceLabel(value: number | null, tr: (ru: string, en: string) => string): string {
  if (value == null) return '—'
  if (value === 0) return tr('сейчас столько же', 'same now')
  return `${tr('сейчас', 'now')} ${value > 0 ? '+' : '−'}${Math.abs(value)}%`
}

export function PriceHistory({ appid, authenticated, refreshKey = '', onRequireAuth }: PriceHistoryProps) {
  const { locale, formatPrice: money, tr } = useLocale()
  const [period, setPeriod] = useState(90)
  const [view, setView] = useState<'overview' | 'changes'>('overview')
  const [data, setData] = useState<PriceHistoryData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const id = useId().replace(/:/g, '')
  const overviewTabId = `price-history-overview-tab-${id}`
  const changesTabId = `price-history-changes-tab-${id}`
  const overviewPanelId = `price-history-overview-panel-${id}`
  const changesPanelId = `price-history-changes-panel-${id}`

  useEffect(() => {
    setView('overview')
    setPeriod(90)
  }, [appid])

  useEffect(() => {
    if (!authenticated && view === 'changes') setView('overview')
  }, [authenticated, view])

  useEffect(() => {
    let active = true
    setLoading(true)
    setError(false)
    const privateHistory = authenticated && view === 'changes'
    const path = privateHistory
      ? `/api/me/games/${appid}/price-history?days=${period}`
      : `/api/games/${appid}/price-history?days=${period}`
    api<PriceHistoryData>(path)
      .then((response) => {
        if (active) setData(response)
      })
      .catch(() => {
        if (active) setError(true)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => { active = false }
  }, [appid, authenticated, period, refreshKey, view])

  const currentPrice = data?.current?.price_rub ?? null
  const median = data?.statistics.median_price_rub ?? null
  const minimum = data?.statistics.minimum_price_rub ?? null
  const sufficient = Boolean(data?.coverage.sufficient)
  const medianDiff = percentDifference(currentPrice, median)
  const minimumDiff = percentDifference(currentPrice, minimum)
  const verdict = useMemo(() => {
    if (!data || data.verdict === 'insufficient') return null
    if (data.verdict === 'low') return {
      label: tr('Цена низкая', 'Low price'),
      hint: tr('можно покупать', 'a good time to buy'),
    }
    if (data.verdict === 'high') return {
      label: tr('Цена выше обычной', 'Above the usual price'),
      hint: tr('можно подождать', 'it may be worth waiting'),
    }
    return {
      label: tr('Цена обычная', 'Typical price'),
      hint: tr('решение зависит от магазина', 'compare the stores'),
    }
  }, [data, tr])

  function selectChanges() {
    if (!authenticated) {
      onRequireAuth()
      return
    }
    setView('changes')
  }

  function handleTabKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
    event.preventDefault()
    if (view === 'overview') {
      if (!authenticated) {
        onRequireAuth()
        return
      }
      setView('changes')
      window.requestAnimationFrame(() => document.getElementById(changesTabId)?.focus())
    } else {
      setView('overview')
      window.requestAnimationFrame(() => document.getElementById(overviewTabId)?.focus())
    }
  }

  function decisionCopy(): string {
    if (currentPrice == null || median == null || minimum == null || !data?.coverage.sufficient) return ''
    const medianGap = Math.abs(currentPrice - median)
    const minimumGap = Math.abs(currentPrice - minimum)
    if (data.verdict === 'low') {
      const first = tr(`Сейчас на ${money(medianGap)} дешевле обычной цены.`, `Now ${money(medianGap)} below the usual price.`)
      const second = minimumGap === 0
        ? tr('Это минимум выбранного периода.', 'This is the lowest price in the selected period.')
        : tr(`До минимума периода осталось ${money(minimumGap)}.`, `${money(minimumGap)} above the period low.`)
      return `${first} ${second}`
    }
    if (data.verdict === 'high') {
      return tr(
        `Сейчас на ${money(medianGap)} дороже обычной цены. Можно дождаться снижения.`,
        `Now ${money(medianGap)} above the usual price. Waiting may pay off.`,
      )
    }
    return tr(
      `Цена близка к обычной за период. До минимума — ${money(minimumGap)}.`,
      `The price is close to its usual level. The period low is ${money(minimumGap)} away.`,
    )
  }

  function formatChangeDate(value: string): { date: string; time: string } {
    const date = new Date(value)
    const today = new Date()
    const isToday = date.toDateString() === today.toDateString()
    return {
      date: isToday
        ? tr('Сегодня', 'Today')
        : new Intl.DateTimeFormat(locale === 'ru' ? 'ru-RU' : 'en-US', { day: 'numeric', month: 'short' }).format(date),
      time: new Intl.DateTimeFormat(locale === 'ru' ? 'ru-RU' : 'en-US', { hour: '2-digit', minute: '2-digit' }).format(date),
    }
  }

  if (loading && !data) {
    return (
      <section className="price-history-compact is-loading" role="status" aria-label={tr('История цены', 'Price history')}>
        <span className="price-history-compact-signal" aria-hidden="true"><i /></span>
        <div className="price-history-compact-copy">
          <strong>{tr('История цены', 'Price history')}</strong>
          <span>{tr('проверяем данные', 'checking data')}</span>
        </div>
      </section>
    )
  }

  if (error && !data) {
    return (
      <section className="price-history-compact is-error" role="status" aria-label={tr('История цены', 'Price history')}>
        <span className="price-history-compact-signal" aria-hidden="true"><i /></span>
        <div className="price-history-compact-copy">
          <strong>{tr('История цены', 'Price history')}</strong>
          <span>{tr('временно недоступна', 'temporarily unavailable')}</span>
        </div>
      </section>
    )
  }

  if (data && !data.coverage.sufficient) {
    return (
      <section className="price-history-compact" role="status" aria-label={tr('История цены', 'Price history')}>
        <span className="price-history-compact-signal" aria-hidden="true"><i /></span>
        <div className="price-history-compact-copy">
          <strong>{tr('История цены', 'Price history')}</strong>
          <span>{tr('собираем данные', 'collecting data')}</span>
        </div>
        <span className="price-history-compact-meta">
          {tr(
            `${data.coverage.observed_days} дн. · ${data.coverage.checks} проверок`,
            `${data.coverage.observed_days} days · ${data.coverage.checks} checks`,
          )}
        </span>
      </section>
    )
  }

  return (
    <section className="price-history" aria-labelledby={`price-history-title-${id}`}>
      <header className="price-history-head">
        <div>
          <span className="price-history-kicker">{tr('История цены', 'Price history')}</span>
          <h2 id={`price-history-title-${id}`}>{tr('Покупать или подождать?', 'Buy now or wait?')}</h2>
        </div>
        <label className="price-history-period">
          <span className="sr-only">{tr('Период истории цены', 'Price history period')}</span>
          <select value={period} onChange={(event) => setPeriod(Number(event.target.value))}>
            {(data?.available_periods ?? [30, 90, 365]).map((days) => (
              <option key={days} value={days}>{days} {tr('дней', 'days')}</option>
            ))}
          </select>
        </label>
      </header>

      <div className="price-history-tabs" role="tablist" aria-label={tr('Режим истории цены', 'Price history view')}>
        <button
          id={overviewTabId}
          type="button"
          role="tab"
          aria-selected={view === 'overview'}
          aria-controls={overviewPanelId}
          tabIndex={view === 'overview' ? 0 : -1}
          onClick={() => setView('overview')}
          onKeyDown={handleTabKeyDown}
        >
          {tr('Обзор', 'Overview')}
        </button>
        <button
          id={changesTabId}
          type="button"
          role="tab"
          aria-selected={view === 'changes'}
          aria-controls={changesPanelId}
          tabIndex={view === 'changes' ? 0 : -1}
          onClick={selectChanges}
          onKeyDown={handleTabKeyDown}
        >
          {tr('Изменения', 'Changes')}
          {!authenticated && (
            <svg className="price-history-lock" viewBox="0 0 16 16" aria-label={tr('Требуется вход', 'Sign-in required')}>
              <path d="M4.5 7V5.5a3.5 3.5 0 0 1 7 0V7M3.5 7.5h9v6h-9z" />
            </svg>
          )}
        </button>
      </div>

      {view === 'overview' ? (
        <div id={overviewPanelId} role="tabpanel" aria-labelledby={overviewTabId}>
          {loading && !data ? (
            <div className="price-history-state" role="status">{tr('Собираем ценовой контекст…', 'Loading price context…')}</div>
          ) : error && !data ? (
            <div className="price-history-state">{tr('История временно недоступна.', 'Price history is temporarily unavailable.')}</div>
          ) : (
            <div className="price-passport" aria-busy={loading}>
              <div className="price-passport-decision">
                <div>
                  <span className="price-history-current-label">{tr('Лучшая цена сейчас', 'Best price now')}</span>
                  <div className="price-history-current-price">{money(currentPrice)}</div>
                  {verdict ? (
                    <div className={`price-history-verdict is-${data?.verdict}`}>
                      <i aria-hidden="true" />
                      <strong>{verdict.label}</strong>
                      <span>{verdict.hint}</span>
                    </div>
                  ) : (
                    <p className="price-history-insufficient">
                      {tr('Пока данных недостаточно для оценки. Продолжаем наблюдение.', 'There is not enough data for an estimate yet. Observation continues.')}
                    </p>
                  )}
                  {verdict && <p className="price-history-decision-copy">{decisionCopy()}</p>}
                </div>
                <div className="price-history-scope">
                  <span>{tr('Steam официально + ключи', 'Official Steam + keys')}</span>
                  <span>{tr(`наблюдаем ${data?.coverage.observed_days ?? 0} дн.`, `${data?.coverage.observed_days ?? 0} days observed`)}</span>
                  <span>{tr(`${data?.coverage.checks ?? 0} проверок`, `${data?.coverage.checks ?? 0} checks`)}</span>
                </div>
              </div>
              <aside className="price-history-ledger">
                <div className="price-history-ledger-head">
                  <strong>{tr('Ценовой контекст', 'Price context')}</strong>
                  <span>{period} {tr('дней', 'days')}</span>
                </div>
                <div className="price-history-comparison">
                  <div className="price-history-compare-row">
                    <div><strong>{tr('Обычная цена', 'Usual price')}</strong><span>{tr('медиана периода', 'period median')}</span></div>
                    <div><b>{money(sufficient ? median : null)}</b><span className={!sufficient ? 'is-muted' : ''}>{differenceLabel(sufficient ? medianDiff : null, tr)}</span></div>
                  </div>
                  <div className="price-history-compare-row">
                    <div><strong>{tr('Минимум периода', 'Period low')}</strong><span>{tr('за время наблюдения', 'during observation')}</span></div>
                    <div><b>{money(sufficient ? minimum : null)}</b><span className={sufficient ? 'is-neutral' : 'is-muted'}>{differenceLabel(sufficient ? minimumDiff : null, tr)}</span></div>
                  </div>
                </div>
                <div className="price-history-ledger-foot">
                  {tr('Оценка относится к выбранному периоду, а не ко всей истории игры.', 'The estimate covers the selected observation period, not the game’s entire lifetime.')}
                </div>
              </aside>
            </div>
          )}
        </div>
      ) : (
        <div id={changesPanelId} role="tabpanel" aria-labelledby={changesTabId}>
          <div className="price-changes">
            <div className="price-changes-head" aria-hidden="true">
              <span>{tr('Дата', 'Date')}</span><span>{tr('Источник', 'Source')}</span><span>{tr('Изменение цены', 'Price change')}</span><span>{tr('Разница', 'Change')}</span>
            </div>
            <div className="price-change-list" tabIndex={0} aria-label={tr('Фактические изменения цены', 'Actual price changes')}>
              {loading && !data?.changes ? (
                <div className="price-history-state" role="status">{tr('Загружаем изменения…', 'Loading changes…')}</div>
              ) : (data?.changes ?? []).length === 0 ? (
                <div className="price-history-state">{tr('За выбранный период цена не менялась.', 'The price did not change during this period.')}</div>
              ) : data?.changes?.map((change, index) => {
                const when = formatChangeDate(change.observed_at)
                return (
                  <article className="price-change-row" key={`${change.source}-${change.offer_kind}-${change.observed_at}-${index}`}>
                    <time dateTime={change.observed_at}><span>{when.date}</span><span>{when.time}</span></time>
                    <div className={`price-change-source is-${change.source}`}>
                      <i aria-hidden="true" />
                      <div><strong>{SOURCE_LABELS[change.source]}</strong><span>{change.offer_kind === 'official' ? tr('официально', 'official') : tr('ключ', 'key')}</span></div>
                    </div>
                    <div className="price-change-movement"><span>{money(change.previous_price_rub)}</span><i aria-hidden="true">→</i><span>{money(change.price_rub)}</span></div>
                    <div className={`price-change-delta ${change.change_percent != null && change.change_percent > 0 ? 'is-up' : ''}`}>
                      {change.change_percent == null ? '—' : `${change.change_percent > 0 ? '+' : '−'}${Math.abs(change.change_percent)}%`}
                    </div>
                  </article>
                )
              })}
            </div>
            <div className="price-changes-foot">
              <span>{tr('Показаны только фактические изменения цены', 'Only actual price changes are shown')}</span>
              <span>{tr('Полная история · функция аккаунта', 'Full history · account feature')}</span>
            </div>
          </div>
        </div>
      )}
    </section>
  )
}
