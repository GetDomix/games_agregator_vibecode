import { AnimatePresence, motion } from 'framer-motion'
import { useCallback, useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { api, authHeaders, getStoredUser, getToken, setSession } from './api'
import type { User } from './api'
import { BRAND, BrandMark } from './brand'
import { AlertSettingsModal } from './components/AlertSettingsModal'
import { WatchlistAlerts } from './components/WatchlistAlerts'
import type { AlertItem, AlertScope, FavoriteItem } from './watchlist'
import './styles.css'

type RecentItem = { q: string; appid?: number | null; at: number }
const RECENT_KEY = 'gpa_recent_v1'
function loadRecents(): RecentItem[] {
  try {
    return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]') as RecentItem[]
  } catch {
    return []
  }
}
function pushRecent(q: string, appid?: number | null) {
  const term = q.trim()
  if (!term) return
  const prev = loadRecents().filter((r) => r.q.toLowerCase() !== term.toLowerCase())
  const next = [{ q: term, appid: appid ?? null, at: Date.now() }, ...prev].slice(0, 8)
  localStorage.setItem(RECENT_KEY, JSON.stringify(next))
}

type Offer = { title: string; url: string; price_rub: number; sales?: number; seller_name?: string | null; kind?: string }
type KindStats = { kind: string; label: string; count: number; min_price: number | null; avg_price: number | null; popular?: Offer | null; cheapest?: Offer | null }
type Market = { marketplace: string; label: string; total_offers: number; scanned_offers: number; by_kind: KindStats[]; error?: string | null }
type Steam = { appid: number; name: string; header_image?: string | null; store_url: string; price_rub?: number | null; price_initial_rub?: number | null; discount_percent?: number; is_free?: boolean; available_in_ru?: boolean; note?: string | null }
type Deal = { score: number; label: string; is_better: boolean; market_min_rub?: number | null; market_source?: string | null; savings_rub?: number | null; savings_percent?: number | null }
type Quota = {
  limit: number | null
  used: number
  remaining: number | null
  is_guest: boolean
  is_pro?: boolean
  unlimited?: boolean
  plan?: string
  reset_hint?: string
  upgrade_hint?: string | null
}
type PlanCard = {
  id: string
  name: string
  price_rub: number
  period?: string | null
  searches_per_day?: number | null
  unlimited?: boolean
  features: string[]
  cta?: string
}
type PlansResponse = {
  currency: string
  note?: string
  billing_contact_email?: string
  plans: PlanCard[]
  promo_hint?: string
  checkout_message?: string
}
type Freshness = { source: string; status: string; last_success_at?: string | null; next_refresh_at?: string | null }
type PriceResponse = { query: string; steam: Steam | null; candidates: { appid: number; name: string; tiny_image?: string; price_rub?: number | null }[]; plati: Market; ggsel: Market; warnings: string[]; saved_to_history?: boolean; is_favorite?: boolean; deal?: Deal | null; quota?: Quota | null; refreshing?: boolean; freshness?: Freshness[] }
type PopularItem = { query: string; game_name?: string | null; appid?: number | null; header_image?: string | null; count?: number }
type Fav = { id: number; appid: number; game_name: string; header_image?: string | null; target_price_rub?: number | null; last_steam_price_rub?: number | null; price_below_target?: boolean }
type Hist = { id: number; query: string; appid?: number | null; game_name?: string | null; header_image?: string | null; steam_price_rub?: number | null; plati_min_rub?: number | null; ggsel_min_rub?: number | null; created_at?: string }
type AdSlotDef = {
  id: string
  placement: string
  format: string
  size_hint?: string
  title: string
  subtitle?: string
  cta?: string
  provider?: string
  click_url?: string | null
  image_url?: string | null
  html?: string | null
}
type AdsConfig = {
  enabled: boolean
  contact_email?: string
  label?: string
  note?: string
  slots: AdSlotDef[]
}

const rub = (v?: number | null) =>
  v == null || Number.isNaN(Number(v))
    ? '—'
    : new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(Number(v))

function useTheme() {
  const [theme, setTheme] = useState(() => localStorage.getItem('gpa_theme') || 'light')
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme)
    localStorage.setItem('gpa_theme', theme)
    const meta = document.querySelector('meta[name="theme-color"]')
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#0b0f17' : '#f6f1e8')
  }, [theme])
  return { theme, toggle: () => setTheme((t) => (t === 'dark' ? 'light' : 'dark')) }
}

export default function App() {
  const { theme, toggle } = useTheme()
  const [user, setUser] = useState<User | null>(getStoredUser())
  const [token, setToken] = useState<string | null>(getToken())
  const [view, setView] = useState<'home' | 'cabinet' | 'guide' | 'plans' | 'admin' | 'radar'>('home')
  const [linkCode, setLinkCode] = useState<string | null>(null)
  const [linkDeep, setLinkDeep] = useState<string | null>(null)
  const [plans, setPlans] = useState<PlansResponse | null>(null)
  const [promoCode, setPromoCode] = useState('')
  const [promoMsg, setPromoMsg] = useState('')
  const [query, setQuery] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [result, setResult] = useState<PriceResponse | null>(null)
  const [popular, setPopular] = useState<PopularItem[]>([])
  const [recents, setRecents] = useState<RecentItem[]>(() => loadRecents())
  const [toast, setToast] = useState('')
  const [authOpen, setAuthOpen] = useState(false)
  const [authTab, setAuthTab] = useState<'login' | 'register'>('login')
  const [authError, setAuthError] = useState('')
  const [dashboard, setDashboard] = useState<{
    recent_history: Hist[]
    favorites_preview: Fav[]
    price_hits: Fav[]
    favorites_count: number
    searches_total: number
    searches_this_week: number
    alerts_count: number
    ctas: string[]
  } | null>(null)
  const [adminData, setAdminData] = useState<{
    stats: Record<string, number | Record<string, number>>
    recent_users: { id: number; email: string; display_name: string; plan: string; is_admin: boolean; created_at?: string }[]
    promo_codes: string
  } | null>(null)
  const [ads, setAds] = useState<AdsConfig | null>(null)
  const [marketTab, setMarketTab] = useState<'plati' | 'ggsel'>('plati')
  const [aboutOpen, setAboutOpen] = useState(false)
  const [suggests, setSuggests] = useState<{ appid: number; name: string; tiny_image?: string; price_rub?: number | null }[]>([])
  const [suggestOpen, setSuggestOpen] = useState(false)
  const [tgStatus, setTgStatus] = useState<{
    linked: boolean
    identity_linked?: boolean
    telegram_username?: string | null
    radar_enabled?: boolean
    bot_username?: string | null
  } | null>(null)
  const [tgBusy, setTgBusy] = useState(false)
  const [watchlist, setWatchlist] = useState<FavoriteItem[]>([])
  const [alertItems, setAlertItems] = useState<AlertItem[]>([])
  const [alertModal, setAlertModal] = useState<{ favorite: FavoriteItem; create: boolean } | null>(null)

  const loggedIn = Boolean(token && user)
  const isPro = Boolean(
    user && (user.plan === 'pro' || user.plan === 'unlimited' || user.plan_label === 'Pro'),
  )

  useEffect(() => {
    const onTelegramOidc = (event: MessageEvent) => {
      if (event.origin !== window.location.origin) return
      const payload = event.data as { type?: string; ok?: boolean; detail?: string; access_token?: string; user?: User }
      if (payload.type !== 'igroscan:telegram-oidc') return
      if (!payload.ok || !payload.access_token || !payload.user) {
        setToast(payload.detail || 'Не удалось подтвердить Telegram')
        return
      }
      setSession(payload.access_token, payload.user)
      setToken(payload.access_token)
      setUser(payload.user)
      setTgStatus((current) => (current ? { ...current, identity_linked: true } : current))
      setToast('Telegram подтверждён — аккаунты объединены')
    }
    window.addEventListener('message', onTelegramOidc)
    return () => window.removeEventListener('message', onTelegramOidc)
  }, [])
  // Ads for everyone except active Pro
  const showAds = Boolean(ads?.enabled && !isPro)

  const adByPlacement = useCallback(
    (placement: string) => (showAds ? ads?.slots.find((s) => s.placement === placement) : undefined),
    [ads, showAds],
  )

  const refreshMe = useCallback(async () => {
    if (!getToken()) return
    try {
      const me = await api<User>('/api/auth/me')
      setUser(me)
      setSession(getToken(), me)
    } catch {
      setSession(null, null)
      setUser(null)
      setToken(null)
    }
  }, [])

  useEffect(() => {
    refreshMe()
    api<{ items: PopularItem[] }>('/api/trends/popular?limit=8')
      .then((d) => setPopular(d.items || []))
      .catch(() => {})
    api<AdsConfig>('/api/ads/config')
      .then((d) => setAds(d))
      .catch(() => setAds(null))
    api<PlansResponse>('/api/plans')
      .then((d) => setPlans(d))
      .catch(() => setPlans(null))
  }, [refreshMe])

  const loadDashboard = useCallback(async () => {
    if (!getToken()) return
    const d = await api<typeof dashboard>('/api/me/dashboard')
    setDashboard(d as NonNullable<typeof dashboard>)
  }, [])

  const loadTgStatus = useCallback(async () => {
    if (!getToken()) {
      setTgStatus(null)
      return
    }
    try {
      const s = await api<{
        linked: boolean
        telegram_username?: string | null
        radar_enabled?: boolean
        bot_username?: string | null
      }>('/api/telegram/status')
      setTgStatus(s)
    } catch {
      setTgStatus(null)
    }
  }, [])

  const loadWatchlist = useCallback(async () => {
    if (!getToken()) return
    const [favorites, alerts] = await Promise.all([
      api<{ items: FavoriteItem[] }>('/api/me/favorites'),
      api<{ items: AlertItem[] }>('/api/me/alerts'),
    ])
    setWatchlist(favorites.items)
    setAlertItems(alerts.items)
  }, [])

  useEffect(() => {
    if (loggedIn && view === 'cabinet') {
      loadDashboard().catch(() => {})
      loadWatchlist().catch(() => {})
    }
    if (loggedIn && (view === 'cabinet' || view === 'radar')) loadTgStatus()
  }, [loggedIn, view, loadDashboard, loadWatchlist, loadTgStatus])

  useEffect(() => {
    if (loggedIn && view === 'admin' && user?.is_admin) {
      api<NonNullable<typeof adminData>>('/api/admin/overview')
        .then(setAdminData)
        .catch((e) => setError(e instanceof Error ? e.message : 'Админка'))
    }
  }, [loggedIn, view, user?.is_admin])

  useEffect(() => {
    if (!toast) return
    const t = window.setTimeout(() => setToast(''), 2400)
    return () => window.clearTimeout(t)
  }, [toast])

  // Autocomplete: «cyb» → Cyberpunk 2077 (Steam storesearch)
  useEffect(() => {
    const q = query.trim()
    if (q.length < 2) {
      setSuggests([])
      return
    }
    let cancelled = false
    const t = window.setTimeout(() => {
      api<{ candidates: { appid: number; name: string; tiny_image?: string; price_rub?: number | null }[] }>(
        `/api/search?q=${encodeURIComponent(q)}`,
      )
        .then((d) => {
          if (!cancelled) setSuggests((d.candidates || []).slice(0, 8))
        })
        .catch(() => {
          if (!cancelled) setSuggests([])
        })
    }, 260)
    return () => {
      cancelled = true
      window.clearTimeout(t)
    }
  }, [query])

  // Deep link ?q=&appid=
  useEffect(() => {
    const sp = new URLSearchParams(window.location.search)
    const q = sp.get('q')
    if (q) {
      const appid = sp.get('appid')
      runSearch(q, appid ? Number(appid) : null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function runSearch(q: string, appid?: number | null) {
    const term = q.trim()
    if (!term) return
    setView('home')
    setQuery(term)
    setLoading(true)
    setError('')
    try {
      const params = new URLSearchParams({ q: term })
      if (appid) params.set('appid', String(appid))
      const data = await api<PriceResponse>(`/api/prices?${params}`, { headers: authHeaders() })
      setResult(data)
      pushRecent(term, appid ?? data.steam?.appid)
      setRecents(loadRecents())
      const url = new URL(window.location.href)
      url.searchParams.set('q', term)
      if (data.steam?.appid) url.searchParams.set('appid', String(data.steam.appid))
      else url.searchParams.delete('appid')
      window.history.replaceState({}, '', url.toString())
      if (loggedIn) loadDashboard().catch(() => {})
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Ошибка поиска')
      setResult(null)
    } finally {
      setLoading(false)
    }
  }

  function shareResult() {
    if (!result) return
    const url = window.location.href
    const title = result.steam?.name || result.query
    if (navigator.share) {
      navigator.share({ title: `${title} — ${BRAND.name}`, url }).catch(() => {})
    } else {
      navigator.clipboard.writeText(url).then(() => setToast('Ссылка скопирована')).catch(() => setToast(url))
    }
  }

  async function onAuth(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setAuthError('')
    const fd = new FormData(e.currentTarget)
    const body: Record<string, string> = {
      email: String(fd.get('email') || ''),
      password: String(fd.get('password') || ''),
    }
    const name = String(fd.get('display_name') || '').trim()
    if (authTab === 'register' && name) body.display_name = name
    try {
      const data = await api<{ access_token: string; user: User }>(
        authTab === 'register' ? '/api/auth/register' : '/api/auth/login',
        { method: 'POST', body: JSON.stringify(body) },
      )
      setSession(data.access_token, data.user)
      setToken(data.access_token)
      setUser(data.user)
      setAuthOpen(false)
    } catch (err) {
      setAuthError(err instanceof Error ? err.message : 'Ошибка')
    }
  }

  function logout() {
    setSession(null, null)
    setToken(null)
    setUser(null)
    setDashboard(null)
    setView('home')
  }

  async function toggleFavorite() {
    if (!loggedIn) {
      setAuthTab('register')
      setAuthOpen(true)
      return
    }
    if (!result?.steam) return
    const steam = result.steam
    try {
      if (result.is_favorite) {
        await api(`/api/me/favorites/${steam.appid}`, { method: 'DELETE' })
        setResult({ ...result, is_favorite: false })
      } else {
        setAlertModal({ favorite: { id: 0, appid: steam.appid, game_name: steam.name, header_image: steam.header_image }, create: true })
      }
      loadDashboard().catch(() => {})
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Избранное')
    }
  }

  function trackClick(marketplace: string, url: string, price?: number) {
    fetch('/api/track/click', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...authHeaders() },
      body: JSON.stringify({
        marketplace,
        url,
        price_rub: price ?? null,
        appid: result?.steam?.appid ?? null,
        query: result?.query ?? null,
      }),
    }).catch(() => {})
  }

  const steamPrice = useMemo(
    () => (result?.steam && !result.steam.is_free ? result.steam.price_rub : null),
    [result],
  )

  return (
    <>
      <div className="app-bg" aria-hidden />
      <header className="header">
        <div className="header-inner">
          <button type="button" className="brand" onClick={() => setView('home')}>
            <span className="brand-mark-wrap">
              <BrandMark size={42} />
            </span>
            <div className="brand-text">
              <h1>{BRAND.name}</h1>
              <p className="brand-tagline">{BRAND.tagline} · {BRAND.shortTagline}</p>
            </div>
          </button>
          <button type="button" className="btn ghost sm icon-btn m-only" onClick={toggle} aria-label="Тема">
            {theme === 'dark' ? '☀' : '☾'}
          </button>
          <div className="header-actions desk-only" data-auth={loggedIn ? 'user' : 'guest'}>
            <button type="button" className="btn ghost sm icon-btn" onClick={toggle} aria-label="Тема">
              {theme === 'dark' ? '☀' : '☾'}
            </button>
            <button type="button" className="btn ghost sm" onClick={() => setView('guide')}>
              Как пользоваться
            </button>
            <button type="button" className="btn ghost sm" onClick={() => setView('plans')}>
              Pro
            </button>
            <button
              type="button"
              className="btn ghost sm"
              onClick={() => {
                if (loggedIn) setView('radar')
                else {
                  setAuthTab('login')
                  setAuthOpen(true)
                }
              }}
            >
              📡 Радар
            </button>
            {user?.is_admin && (
              <button type="button" className="btn ghost sm" onClick={() => setView('admin')}>
                Admin
              </button>
            )}
            {loggedIn ? (
              <>
                <button type="button" className="btn ghost" onClick={() => setView('cabinet')}>
                  Кабинет
                </button>
                <div className="chip-user">
                  <span className="avatar">{(user?.display_name || user?.email || '?').charAt(0).toUpperCase()}</span>
                  <span className="chip-user-name muted">{user?.display_name || user?.email}</span>
                  <button type="button" className="btn ghost sm" onClick={logout}>Выйти</button>
                </div>
              </>
            ) : (
              <>
                <button type="button" className="btn ghost" onClick={() => { setAuthTab('login'); setAuthOpen(true) }}>Войти</button>
                <button type="button" className="btn primary" onClick={() => { setAuthTab('register'); setAuthOpen(true) }}>
                  Регистрация
                </button>
              </>
            )}
          </div>
        </div>
      </header>

      <main className="shell has-tabbar">
        {adByPlacement('header') && (
          <AdSlot slot={adByPlacement('header')!} label={ads?.label} />
        )}

        {view === 'home' && (
          <>
            <section className="hero hero-search">
              <p className="eyebrow desk-only">Сравнение цен · регион RU · ₽</p>
              <h2 className="search-title">Найти цену</h2>
              <p className="lead desk-only">
                {BRAND.name} — {BRAND.tagline}: сравниваем Steam RU, Plati.Market и GGsel.
                Мы не продаём ключи — собираем цены и ссылки, чтобы быстрее решить, где выгоднее.
              </p>
              <form
                className="search-row"
                onSubmit={(e) => {
                  e.preventDefault()
                  setSuggestOpen(false)
                  runSearch(query)
                }}
              >
                <div className="search-field search-field--suggest">
                  <span aria-hidden>⌕</span>
                  <input
                    value={query}
                    onChange={(e) => {
                      setQuery(e.target.value)
                      setSuggestOpen(true)
                    }}
                    onFocus={() => setSuggestOpen(true)}
                    onBlur={() => {
                      // delay so click on suggestion works
                      window.setTimeout(() => setSuggestOpen(false), 180)
                    }}
                    placeholder="Hades, Elden Ring, Cyberpunk…"
                    maxLength={120}
                    required
                    enterKeyHint="search"
                    autoComplete="off"
                    aria-autocomplete="list"
                    aria-expanded={suggestOpen && suggests.length > 0}
                  />
                  {suggestOpen && suggests.length > 0 && (
                    <ul className="suggest-list" role="listbox">
                      {suggests.map((s) => (
                        <li key={s.appid}>
                          <button
                            type="button"
                            className="suggest-item"
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => {
                              setQuery(s.name)
                              setSuggestOpen(false)
                              runSearch(s.name, s.appid)
                            }}
                          >
                            {s.tiny_image ? <img src={s.tiny_image} alt="" /> : <span className="ph" />}
                            <span className="suggest-name">{s.name}</span>
                            <span className="suggest-price muted">{rub(s.price_rub)}</span>
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
                <button className="btn primary" type="submit" disabled={loading}>
                  {loading ? 'Ищем…' : 'Сравнить'}
                </button>
              </form>

              <div className="history-under-search">
                <div className="history-under-head">
                  <span className="history-label">Недавние</span>
                  {recents.length > 0 && (
                    <button
                      type="button"
                      className="btn ghost sm"
                      onClick={() => {
                        localStorage.removeItem(RECENT_KEY)
                        setRecents([])
                      }}
                    >
                      Очистить
                    </button>
                  )}
                </div>
                {recents.length > 0 ? (
                  <div className="recent-row" aria-label="Недавние поиски">
                    {recents.slice(0, 8).map((r) => (
                      <button
                        key={r.q + String(r.at)}
                        type="button"
                        className="recent-chip"
                        onClick={() => runSearch(r.q, r.appid)}
                      >
                        {r.q}
                      </button>
                    ))}
                  </div>
                ) : (
                  <p className="muted history-empty">
                    Пока пусто — после первого поиска здесь появятся быстрые чипы. Или начни с «Сейчас ищут» ниже.
                  </p>
                )}
              </div>

              <div className="pills desk-only">
                <span className="pill steam">Steam RU</span>
                <span className="pill plati">Plati.Market</span>
                <span className="pill ggsel">GGsel</span>
              </div>
            </section>

            {adByPlacement('mid') && (
              <AdSlot slot={adByPlacement('mid')!} label={ads?.label} />
            )}

            <section className="section panel about-panel">
              <button type="button" className="about-toggle" onClick={() => setAboutOpen((v) => !v)} aria-expanded={aboutOpen}>
                <h3 style={{ margin: 0 }}>Зачем это нужно</h3>
                <span className="muted">{aboutOpen ? 'свернуть' : 'подробнее'}</span>
              </button>
              <div className={`about-body ${aboutOpen ? 'open' : ''}`}>
                <p className="lead" style={{ marginBottom: '1rem' }}>
                  Цены на одну и ту же игру на разных площадках отличаются: регион, скидки, тип товара
                  (ключ, гифт, аккаунт, аренда). Игроскан сводит это в одном экране и показывает,
                  насколько рынок дешевле Steam.
                </p>
                <div className="steps">
                  <div className="step">
                    <h4>Экономия времени</h4>
                    <p>Не нужно открывать три вкладки и вручную сопоставлять кривые названия товаров.</p>
                  </div>
                  <div className="step">
                    <h4>Понятная выгода</h4>
                    <p>Оценка сделки относительно Steam, минимум и средняя цена, ссылки на офферы.</p>
                  </div>
                  <div className="step">
                    <h4>Следи за ценой</h4>
                    <p>В аккаунте — история, избранное и целевая цена.</p>
                  </div>
                </div>
              </div>
            </section>

            <section className="section panel radar-home-cta">
              <h3 style={{ marginTop: 0 }}>📡 Не пропусти скидку в Steam</h3>
              <p className="muted">
                Радар + бот <strong>@igroscan_bot</strong>: избранное, целевая цена, уведомление в Telegram.
              </p>
              <button
                type="button"
                className="btn primary"
                style={{ marginTop: '0.5rem' }}
                onClick={() => {
                  if (loggedIn) setView('radar')
                  else {
                    setAuthTab('register')
                    setAuthOpen(true)
                  }
                }}
              >
                {loggedIn ? 'Настроить радар' : 'Войти и включить радар'}
              </button>
            </section>

            {popular.length > 0 && (
              <section className="section panel">
                <h3>Сейчас ищут</h3>
                <div className="chip-list">
                  {popular.map((p) => (
                    <button key={p.query} type="button" className="chip" onClick={() => runSearch(p.query, p.appid)}>
                      {p.header_image ? <img src={p.header_image} alt="" /> : null}
                      <span>{p.game_name || p.query}</span>
                    </button>
                  ))}
                </div>
              </section>
            )}

            {loading && <div className="status">Ищем сохранённые цены…</div>}
            {error && <div className="status error">{error}</div>}

            {result && (
              <section className="section">
                {result.warnings?.length > 0 && (
                  <div className="status" style={{ marginBottom: 12 }}>
                    {result.warnings.map((w) => (
                      <div key={w}>{w}</div>
                    ))}
                  </div>
                )}
                {result.refreshing && <div className="status" style={{ marginBottom: 12 }}>Игра поставлена на фоновое обновление. Цены появятся после следующего цикла.</div>}

                <div className="results-meta">
                  {result.deal && (
                    <div className={`deal-card ${result.deal.is_better ? 'hot' : ''}`}>
                      <div className="deal-score">
                        {result.deal.score}
                        <span>/100</span>
                      </div>
                      <div>
                        <strong>{result.deal.label}</strong>
                        <span className="offer-meta">
                          рынок от {rub(result.deal.market_min_rub)}
                          {result.deal.savings_percent != null
                            ? ` · ${result.deal.savings_percent > 0 ? '−' : ''}${Math.abs(result.deal.savings_percent)}% vs Steam`
                            : ''}
                        </span>
                      </div>
                    </div>
                  )}
                  {result.quota && (
                    <div
                      className={`quota-pill ${
                        !result.quota.unlimited && result.quota.remaining != null && result.quota.remaining <= 1
                          ? 'low'
                          : ''
                      }`}
                    >
                      {result.quota.unlimited
                        ? `поисков сегодня: ${result.quota.used} · Pro ∞`
                        : `поисков сегодня: ${result.quota.used}/${result.quota.limit}`}
                      {result.quota.is_guest ? ' · гость' : result.quota.is_pro ? '' : ' · free'}
                      {!result.quota.unlimited && (
                        <button type="button" className="btn ghost sm" style={{ marginLeft: 8 }} onClick={() => setView('plans')}>
                          Снять лимит
                        </button>
                      )}
                    </div>
                  )}
                </div>

                {result.candidates?.length > 0 && (
                  <div className="panel" style={{ marginBottom: 12, padding: '0.85rem' }}>
                    <h3 style={{ marginTop: 0 }}>Совпадения Steam</h3>
                    <div className="candidates">
                      {result.candidates.map((c) => (
                        <button key={c.appid} type="button" className="candidate" onClick={() => runSearch(c.name, c.appid)}>
                          {c.tiny_image ? <img src={c.tiny_image} alt="" /> : <span className="ph" />}
                          <div>
                            <strong>{c.name}</strong>
                            <span className="offer-meta">AppID {c.appid} · {rub(c.price_rub)}</span>
                          </div>
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                {result.steam && (
                  <article className="hero steam-card" style={{ marginTop: 0 }}>
                    <div>{result.steam.header_image ? <img src={result.steam.header_image} alt="" /> : null}</div>
                    <div>
                      <div>
                        <span className={`badge ${result.steam.available_in_ru ? 'ok' : 'warn'}`}>
                          {result.steam.available_in_ru ? 'Steam RU' : 'не в RU'}
                        </span>
                        {(result.steam.discount_percent || 0) > 0 && (
                          <span className="badge hot">−{result.steam.discount_percent}%</span>
                        )}
                        {result.saved_to_history && <span className="badge ok">в истории</span>}
                      </div>
                      <h2 style={{ margin: '0.4rem 0' }}>{result.steam.name}</h2>
                      <div className="price-xl">
                        {result.steam.is_free
                          ? 'Бесплатно'
                          : rub(result.steam.price_rub)}
                        {result.steam.price_initial_rub &&
                          result.steam.price_rub != null &&
                          result.steam.price_initial_rub > result.steam.price_rub && (
                            <span className="old">{rub(result.steam.price_initial_rub)}</span>
                          )}
                      </div>
                      {result.steam.note && <p className="muted">{result.steam.note}</p>}
                      <div className="actions">
                        <a className="btn ghost" href={result.steam.store_url} target="_blank" rel="noreferrer">
                          Steam
                        </a>
                        <button type="button" className={`btn ${result.is_favorite ? 'primary' : 'ghost'}`} onClick={toggleFavorite}>
                          {result.is_favorite ? '★ В избранном' : '☆ В избранное'}
                        </button>
                        <button type="button" className="btn ghost" onClick={shareResult}>
                          Поделиться
                        </button>
                      </div>
                    </div>
                  </article>
                )}

                {adByPlacement('inline_results') && (
                  <AdSlot slot={adByPlacement('inline_results')!} label={ads?.label} />
                )}

                <div className="market-tabs m-only" role="tablist">
                  <button
                    type="button"
                    role="tab"
                    className={`market-tab ${marketTab === 'plati' ? 'active' : ''}`}
                    onClick={() => setMarketTab('plati')}
                  >
                    Plati
                  </button>
                  <button
                    type="button"
                    role="tab"
                    className={`market-tab ${marketTab === 'ggsel' ? 'active' : ''}`}
                    onClick={() => setMarketTab('ggsel')}
                  >
                    GGsel
                  </button>
                </div>
                <div className="grid-2 desk-only">
                  <MarketCard market={result.plati} steamPrice={steamPrice} onTrack={trackClick} />
                  <MarketCard market={result.ggsel} steamPrice={steamPrice} onTrack={trackClick} />
                </div>
                <div className="m-only">
                  <MarketCard
                    market={marketTab === 'plati' ? result.plati : result.ggsel}
                    steamPrice={steamPrice}
                    onTrack={trackClick}
                  />
                </div>
              </section>
            )}
          </>
        )}

        {view === 'guide' && (
          <section className="section hero">
            <p className="eyebrow">Инструкция</p>
            <h2>Как пользоваться Игроскан</h2>
            <p className="lead">
              Сервис создан, чтобы за 30–60 секунд понять: покупать игру в Steam сейчас или смотреть
              предложения на маркетплейсах — и какие варианты (ключ, гифт, аккаунт) вообще есть.
            </p>

            <h3 style={{ marginTop: '1.5rem' }}>Быстрый старт</h3>
            <div className="steps" style={{ marginTop: '0.75rem' }}>
              <div className="step">
                <h4>1. Введите игру</h4>
                <p>Лучше название как в Steam. Из списка совпадений выберите нужную карточку (по AppID/обложке).</p>
              </div>
              <div className="step">
                <h4>2. Сравните цены</h4>
                <p>
                  Сверху — Steam. Ниже — Plati и GGsel: минимум, средняя, популярный и самый дешёвый оффер.
                  Оценка сделки показывает, насколько рынок дешевле Steam.
                </p>
              </div>
              <div className="step">
                <h4>3. Сохраните интересное</h4>
                <p>
                  Зарегистрируйтесь, добавьте игру в избранное и укажите целевую цену. В кабинете можно
                  обновить цены и увидеть, что уже «на цели».
                </p>
              </div>
            </div>

            <h3 style={{ marginTop: '1.75rem' }}>Что означают типы товаров</h3>
            <ul className="guide-list">
              <li><strong>Ключ</strong> — активационный код (проверьте регион и платформу у продавца).</li>
              <li><strong>Гифт</strong> — подарок в Steam/другой магазин (часто нужен обмен дружбой / регион).</li>
              <li><strong>Аккаунт</strong> — доступ к уже купленной игре на чужом/общем аккаунте (риски выше).</li>
              <li><strong>Аренда</strong> — временный доступ, не полноценная покупка.</li>
            </ul>

            <h3 style={{ marginTop: '1.5rem' }}>Ограничения и честные ожидания</h3>
            <ul className="guide-list">
              <li>Цены ориентировочные: у продавца может закончиться товар или смениться стоимость.</li>
              <li>Некоторые игры недоступны в Steam RU — тогда сравниваем только маркетплейсы.</li>
              <li>
                Лимит поисков (гость / Free) защищает сервер: каждый запрос ходит на Steam, Plati и GGsel.
                Тариф Pro снимает дневной кап — раздел «Pro» в шапке.
              </li>
              <li>Мы не принимаем оплату за игры: покупка всегда на стороне Steam / Plati / GGsel.</li>
            </ul>

            <h3 style={{ marginTop: '1.5rem' }}>Безопасность покупки</h3>
            <p className="lead">
              Перед оплатой читайте описание лота, рейтинг продавца, регион активации и условия возврата.
              Слишком низкая цена относительно рынка — повод проверить отзывы особенно внимательно.
            </p>

            <div className="actions" style={{ marginTop: '1.25rem' }}>
              <button type="button" className="btn primary" onClick={() => setView('home')}>
                К поиску
              </button>
              {!loggedIn && (
                <button type="button" className="btn ghost" onClick={() => { setAuthTab('register'); setAuthOpen(true) }}>
                  Создать аккаунт
                </button>
              )}
            </div>
          </section>
        )}

        {view === 'plans' && (
          <section className="section">
            <div className="hero">
              <p className="eyebrow">Тарифы</p>
              <h2>Зачем лимит и как его снять</h2>
              <p className="lead">
                {plans?.note ||
                  'Каждый поиск дергает Steam, Plati и GGsel. Лимит на Free/гостя — чтобы сервис не клали боты и не сжигали исходящий трафик. Pro убирает дневной кап.'}
              </p>
            </div>
            <div className="plans-grid section">
              {(plans?.plans || []).map((p) => (
                <article key={p.id} className={`panel plan-card ${p.unlimited ? 'plan-card--pro' : ''}`}>
                  <h3>{p.name}</h3>
                  <div className="plan-price">
                    {p.price_rub > 0 ? (
                      <>
                        <strong>{p.price_rub.toLocaleString('ru-RU')} ₽</strong>
                        <span className="muted">/{p.period === 'year' ? 'год' : 'мес'}</span>
                      </>
                    ) : (
                      <strong>0 ₽</strong>
                    )}
                  </div>
                  <p className="muted">
                    {p.unlimited ? 'Поиски без дневного лимита' : `${p.searches_per_day ?? '—'} поисков / сутки`}
                  </p>
                  <ul className="guide-list">
                    {p.features.map((f) => (
                      <li key={f}>{f}</li>
                    ))}
                  </ul>
                  {p.unlimited && (
                    <div className="actions" style={{ marginTop: '0.85rem' }}>
                      {loggedIn ? (
                        <button
                          type="button"
                          className="btn primary"
                          onClick={async () => {
                            try {
                              const r = await api<{ message: string; mailto?: string }>('/api/billing/request', {
                                method: 'POST',
                                body: JSON.stringify({ plan_id: p.id }),
                              })
                              setPromoMsg(r.message)
                              if (r.mailto) window.location.href = r.mailto
                            } catch (e) {
                              setPromoMsg(e instanceof Error ? e.message : 'Ошибка')
                            }
                          }}
                        >
                          {p.cta || 'Оформить'}
                        </button>
                      ) : (
                        <button
                          type="button"
                          className="btn primary"
                          onClick={() => {
                            setAuthTab('register')
                            setAuthOpen(true)
                          }}
                        >
                          Сначала аккаунт
                        </button>
                      )}
                    </div>
                  )}
                </article>
              ))}
            </div>
            <div className="panel section">
              <h3>Промокод</h3>
              <p className="muted">Для теста Pro: <code>KEYSIGNAL-PRO</code> (30 дней).</p>
              {loggedIn ? (
                <form
                  className="search-row"
                  style={{ marginTop: '0.75rem' }}
                  onSubmit={async (e) => {
                    e.preventDefault()
                    setPromoMsg('')
                    try {
                      const r = await api<{ message: string; user: User }>('/api/billing/promo', {
                        method: 'POST',
                        body: JSON.stringify({ code: promoCode }),
                      })
                      setSession(getToken(), r.user)
                      setUser(r.user)
                      setPromoMsg(r.message)
                      setPromoCode('')
                    } catch (err) {
                      setPromoMsg(err instanceof Error ? err.message : 'Ошибка')
                    }
                  }}
                >
                  <div className="search-field">
                    <input
                      value={promoCode}
                      onChange={(e) => setPromoCode(e.target.value)}
                      placeholder="Промокод"
                      maxLength={40}
                      required
                    />
                  </div>
                  <button className="btn primary" type="submit">
                    Активировать
                  </button>
                </form>
              ) : (
                <p className="muted">Войдите в аккаунт, чтобы активировать промокод.</p>
              )}
              {promoMsg && <p className="status" style={{ marginTop: '0.75rem' }}>{promoMsg}</p>}
              {plans?.checkout_message && <p className="muted" style={{ marginTop: '0.75rem' }}>{plans.checkout_message}</p>}
            </div>
          </section>
        )}

        {view === 'radar' && (
          <section className="section page-enter radar-page">
            <div className="hero">
              <p className="eyebrow">Уведомления</p>
              <h2>📡 Радар цен</h2>
              <p className="lead">
                Бот <strong>@igroscan_bot</strong> (Игроскан Радар) пишет в Telegram, когда в{' '}
                <strong>Steam</strong> у игры из избранного цена упала или достигла твоей цели.
              </p>
            </div>

            {!loggedIn ? (
              <div className="panel section">
                <h3>Сначала войди</h3>
                <p className="muted">Радар привязан к аккаунту: избранное и цели хранятся у тебя в профиле.</p>
                <div className="actions" style={{ marginTop: '0.85rem' }}>
                  <button type="button" className="btn primary" onClick={() => { setAuthTab('login'); setAuthOpen(true) }}>
                    Войти
                  </button>
                  <button type="button" className="btn ghost" onClick={() => { setAuthTab('register'); setAuthOpen(true) }}>
                    Регистрация
                  </button>
                </div>
              </div>
            ) : (
              <>
                <div className="radar-steps section">
                  <article className="panel radar-step">
                    <span className="radar-step-n">1</span>
                    <h3>Избранное + цель</h3>
                    <p className="muted">
                      Найди игру → ☆ в избранное → укажи целевую цену Steam (или не указывай — тогда только «цена упала»).
                    </p>
                    <button type="button" className="btn ghost sm" onClick={() => setView('home')}>
                      К поиску
                    </button>
                  </article>
                  <article className="panel radar-step">
                    <span className="radar-step-n">2</span>
                    <h3>Привяжи Telegram</h3>
                    <p className="muted">
                      Сначала подтверди Telegram через официальный вход, затем открой бота <strong>@igroscan_bot</strong>,
                      чтобы получать уведомления в чат.
                    </p>
                  </article>
                  <article className="panel radar-step">
                    <span className="radar-step-n">3</span>
                    <h3>Жди уведомления</h3>
                    <p className="muted">
                      Раз в несколько часов проверяем Steam. Цель достигнута → 🎯. Заметное падение → 📉.
                    </p>
                  </article>
                </div>

                <div className="panel section radar-panel">
                  <h3 style={{ marginTop: 0 }}>Статус</h3>
                  <p className={tgStatus?.identity_linked ? 'radar-status ok' : 'radar-status warn'}>
                    {tgStatus?.identity_linked ? '✅ Telegram-аккаунт подтверждён' : '⚪ Telegram-аккаунт ещё не подтверждён'}
                  </p>
                  {!tgStatus?.identity_linked && (
                    <button
                      type="button"
                      className="btn ghost"
                      disabled={tgBusy}
                      onClick={async () => {
                        const popup = window.open('about:blank', 'igroscan-telegram-oidc', 'width=520,height=720')
                        if (!popup) {
                          setToast('Браузер заблокировал окно Telegram')
                          return
                        }
                        setTgBusy(true)
                        try {
                          const result = await api<{ authorization_url: string }>('/api/telegram/oidc/begin', { method: 'POST' })
                          popup.location.href = result.authorization_url
                        } catch (e) {
                          popup.close()
                          setToast(e instanceof Error ? e.message : 'Ошибка')
                        } finally {
                          setTgBusy(false)
                        }
                      }}
                    >
                      Подтвердить Telegram
                    </button>
                  )}
                  {tgStatus?.linked ? (
                    <>
                      <p className="radar-status ok">
                        ✅ Telegram привязан
                        {tgStatus.telegram_username ? ` (@${tgStatus.telegram_username})` : ''}
                      </p>
                      <p>
                        Уведомления:{' '}
                        <strong className={tgStatus.radar_enabled ? 'text-ok' : 'text-warn'}>
                          {tgStatus.radar_enabled ? 'включены' : 'выключены'}
                        </strong>
                      </p>
                      <div className="actions" style={{ marginTop: '0.85rem' }}>
                        <button
                          type="button"
                          className="btn primary"
                          disabled={tgBusy}
                          onClick={async () => {
                            setTgBusy(true)
                            try {
                              const r = await api<{ radar_enabled: boolean }>('/api/telegram/radar', {
                                method: 'POST',
                                body: JSON.stringify({ radar_enabled: !tgStatus.radar_enabled }),
                              })
                              setTgStatus({ ...tgStatus, radar_enabled: r.radar_enabled })
                              setToast(r.radar_enabled ? 'Уведомления включены' : 'Уведомления выключены')
                            } catch (e) {
                              setToast(e instanceof Error ? e.message : 'Ошибка')
                            } finally {
                              setTgBusy(false)
                            }
                          }}
                        >
                          {tgStatus.radar_enabled ? 'Выключить уведомления' : 'Включить уведомления'}
                        </button>
                        <button
                          type="button"
                          className="btn ghost"
                          disabled={tgBusy}
                          onClick={async () => {
                            setTgBusy(true)
                            try {
                              await api('/api/telegram/link', { method: 'DELETE' })
                              setLinkCode(null)
                              setLinkDeep(null)
                              setTgStatus({
                                linked: false,
                                radar_enabled: true,
                                bot_username: tgStatus.bot_username || 'igroscan_bot',
                              })
                              setToast('Telegram отвязан')
                            } catch (e) {
                              setToast(e instanceof Error ? e.message : 'Ошибка')
                            } finally {
                              setTgBusy(false)
                            }
                          }}
                        >
                          Отвязать бота
                        </button>
                        <a
                          className="btn ghost"
                          href="https://t.me/igroscan_bot"
                          target="_blank"
                          rel="noreferrer"
                        >
                          Открыть @igroscan_bot
                        </a>
                      </div>
                    </>
                  ) : (
                    <>
                      <p className="radar-status warn">⚪ Telegram ещё не привязан</p>
                      <div className="actions" style={{ marginTop: '0.85rem' }}>
                        <button
                          type="button"
                          className="btn primary"
                          disabled={tgBusy}
                          onClick={async () => {
                            setTgBusy(true)
                            try {
                              const r = await api<{
                                code: string
                                deep_link?: string | null
                                bot_username?: string | null
                              }>('/api/telegram/link-code', { method: 'POST' })
                              setLinkCode(r.code)
                              setLinkDeep(r.deep_link || `https://t.me/igroscan_bot?start=${r.code}`)
                              setTgStatus({
                                linked: false,
                                bot_username: r.bot_username || 'igroscan_bot',
                                radar_enabled: true,
                              })
                              if (r.deep_link) window.open(r.deep_link, '_blank', 'noopener')
                              setToast('Код создан — подтверди в Telegram')
                            } catch (e) {
                              setToast(e instanceof Error ? e.message : 'Ошибка')
                            } finally {
                              setTgBusy(false)
                            }
                          }}
                        >
                          Привязать Telegram
                        </button>
                        <a className="btn ghost" href="https://t.me/igroscan_bot" target="_blank" rel="noreferrer">
                          Открыть бота
                        </a>
                      </div>
                      {linkCode && (
                        <div className="link-code-box">
                          <p className="muted" style={{ margin: '0 0 0.35rem' }}>Твой код (20 минут):</p>
                          <code className="link-code">{linkCode}</code>
                          <p className="muted" style={{ marginTop: '0.65rem' }}>
                            В боте: <code>/start {linkCode}</code>
                            {linkDeep && (
                              <>
                                {' · '}
                                <a href={linkDeep} target="_blank" rel="noreferrer">
                                  открыть ссылку
                                </a>
                              </>
                            )}
                          </p>
                          <button
                            type="button"
                            className="btn ghost sm"
                            style={{ marginTop: '0.5rem' }}
                            onClick={() => {
                              navigator.clipboard.writeText(`/start ${linkCode}`).then(() => setToast('Скопировано'))
                            }}
                          >
                            Копировать /start {linkCode}
                          </button>
                          <button
                            type="button"
                            className="btn ghost sm"
                            style={{ marginTop: '0.5rem', marginLeft: '0.35rem' }}
                            onClick={() => loadTgStatus().then(() => setToast('Статус обновлён'))}
                          >
                            Я привязал — проверить
                          </button>
                        </div>
                      )}
                    </>
                  )}
                </div>

                <div className="panel section">
                  <h3>Когда придёт сообщение</h3>
                  <ul className="guide-list">
                    <li>
                      <strong>🎯 Цель</strong> — цена Steam ≤ твоей целевой (задаётся при добавлении в избранное).
                    </li>
                    <li>
                      <strong>📉 Скидка Steam</strong> — цена упала минимум на 5% или на 30 ₽ (если цели нет).
                    </li>
                    <li>Проверка несколько раз в сутки (не мгновенно после каждой скидки Steam).</li>
                    <li>Pro не обязателен для радара; без привязки Telegram уведомлений не будет.</li>
                  </ul>
                  <button type="button" className="btn ghost" style={{ marginTop: '0.75rem' }} onClick={() => setView('cabinet')}>
                    К избранному в кабинете
                  </button>
                </div>
              </>
            )}
          </section>
        )}

        {view === 'admin' && loggedIn && user?.is_admin && (
          <section className="section page-enter">
            <div className="hero">
              <p className="eyebrow">Админка</p>
              <h2>Панель {BRAND.name}</h2>
              <p className="muted">Метрики, пользователи, выдача Pro. Доступ: is_admin или ADMIN_EMAILS.</p>
            </div>
            {adminData && (
              <>
                <div className="stats section stagger">
                  <div className="stat"><b>{adminData.stats.users_total as number}</b><span>пользователей</span></div>
                  <div className="stat"><b>{adminData.stats.pro_active as number}</b><span>Pro</span></div>
                  <div className="stat"><b>{adminData.stats.searches_today as number}</b><span>поисков сегодня</span></div>
                  <div className="stat"><b>{adminData.stats.partner_clicks_7d as number}</b><span>клики 7д</span></div>
                </div>
                <div className="panel section">
                  <h3>Пользователи</h3>
                  <div style={{ overflowX: 'auto' }}>
                    <table className="admin-table">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>Email</th>
                          <th>План</th>
                          <th>Действия</th>
                        </tr>
                      </thead>
                      <tbody>
                        {adminData.recent_users.map((u) => (
                          <tr key={u.id}>
                            <td>{u.id}</td>
                            <td>
                              {u.display_name}
                              <span className="offer-meta">{u.email}{u.is_admin ? ' · admin' : ''}</span>
                            </td>
                            <td>{u.plan}</td>
                            <td>
                              <div className="actions">
                                <button
                                  type="button"
                                  className="btn ghost sm"
                                  onClick={async () => {
                                    await api(`/api/admin/users/${u.id}/plan`, {
                                      method: 'POST',
                                      body: JSON.stringify({ plan: 'pro', days: 30 }),
                                    })
                                    setToast(`Pro 30д → ${u.email}`)
                                    const d = await api<NonNullable<typeof adminData>>('/api/admin/overview')
                                    setAdminData(d)
                                  }}
                                >
                                  +Pro 30д
                                </button>
                                <button
                                  type="button"
                                  className="btn ghost sm"
                                  onClick={async () => {
                                    await api(`/api/admin/users/${u.id}/plan`, {
                                      method: 'POST',
                                      body: JSON.stringify({ plan: 'free' }),
                                    })
                                    setToast(`Free → ${u.email}`)
                                    const d = await api<NonNullable<typeof adminData>>('/api/admin/overview')
                                    setAdminData(d)
                                  }}
                                >
                                  Free
                                </button>
                              </div>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <p className="muted" style={{ marginTop: '0.75rem' }}>Промокоды: {adminData.promo_codes || '—'}</p>
                </div>
              </>
            )}
          </section>
        )}

        {view === 'cabinet' && loggedIn && (
          <section className="section page-enter">
            <div className="hero" style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', gap: '1rem' }}>
              <div>
                <p className="eyebrow">Кабинет</p>
                <h2 style={{ margin: 0 }}>{user?.display_name}</h2>
                <p className="muted">{user?.email}</p>
                <p className="muted" style={{ marginTop: 6 }}>
                  Тариф: <strong>{user?.plan_label || 'Free'}</strong>
                  {user?.plan_expires_at
                    ? ` · до ${new Date(user.plan_expires_at).toLocaleDateString('ru-RU')}`
                    : user?.plan === 'pro' || user?.plan === 'unlimited'
                      ? ' · без срока'
                      : ''}
                  {' · '}
                  <button type="button" className="btn ghost sm" onClick={() => setView('plans')}>
                    Тарифы / Pro
                  </button>
                  {user?.is_admin && (
                    <button type="button" className="btn ghost sm" onClick={() => setView('admin')}>
                      Админка
                    </button>
                  )}
                </p>
              </div>
              {dashboard && (
                <div className="stats stagger">
                  <div className="stat"><b>{dashboard.searches_total}</b><span>поисков</span></div>
                  <div className="stat"><b>{dashboard.searches_this_week}</b><span>за 7 дней</span></div>
                  <div className="stat"><b>{dashboard.favorites_count}</b><span>избранное</span></div>
                  <div className="stat"><b>{dashboard.alerts_count}</b><span>на цели</span></div>
                </div>
              )}
            </div>
            {dashboard?.ctas?.map((c) => (
              <p key={c} className="muted" style={{ marginTop: 8 }}>💡 {c}</p>
            ))}

            <div className="panel section radar-panel">
              <h3 style={{ marginTop: 0 }}>📡 Радар / Telegram</h3>
              <p className="muted" style={{ marginBottom: '0.75rem' }}>
                {tgStatus?.linked
                  ? `Привязан${tgStatus.telegram_username ? ` (@${tgStatus.telegram_username})` : ''} · уведомления ${tgStatus.radar_enabled ? 'вкл' : 'выкл'}`
                  : 'Не привязан — не получишь алерты о скидках Steam.'}
                {tgStatus?.identity_linked ? ' · Telegram-аккаунт подтверждён' : ' · Telegram-аккаунт ещё не подтверждён'}
              </p>
              <button type="button" className="btn primary" onClick={() => setView('radar')}>
                Открыть радар
              </button>
            </div>

            <WatchlistAlerts
              favorites={watchlist}
              alerts={alertItems}
              onEdit={(favorite) => setAlertModal({ favorite, create: false })}
              onSearch={runSearch}
              onRearm={async (alert) => {
                await api(`/api/me/favorites/${alert.favorite.appid}/alert/rearm`, { method: 'POST' })
                await loadWatchlist()
                setToast('Алерт снова активен')
              }}
            />

            {dashboard?.price_hits && dashboard.price_hits.length > 0 && (
              <div className="panel section">
                <h3>На цели</h3>
                <div className="list-cards">
                  {dashboard.price_hits.map((f) => (
                    <article key={f.appid} className="list-card hit">
                      {f.header_image ? <img src={f.header_image} alt="" /> : <div className="ph" />}
                      <div>
                        <strong>{f.game_name}</strong>
                        <span className="offer-meta">Steam {rub(f.last_steam_price_rub)} · цель {rub(f.target_price_rub)}</span>
                        <div className="actions">
                          <button type="button" className="btn ghost sm" onClick={() => runSearch(f.game_name, f.appid)}>Цены</button>
                        </div>
                      </div>
                    </article>
                  ))}
                </div>
              </div>
            )}
            <div className="grid-2 section">
              <div className="panel">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <h3 style={{ margin: 0 }}>История</h3>
                  <button
                    type="button"
                    className="btn ghost sm"
                    onClick={async () => {
                      if (!confirm('Очистить историю?')) return
                      await api('/api/me/history', { method: 'DELETE' })
                      loadDashboard()
                    }}
                  >
                    Очистить
                  </button>
                </div>
                <div className="list-cards" style={{ marginTop: 12 }}>
                  {(dashboard?.recent_history || []).map((h) => (
                    <article key={h.id} className="list-card">
                      {h.header_image ? <img src={h.header_image} alt="" /> : <div className="ph" />}
                      <div>
                        <strong>{h.game_name || h.query}</strong>
                        <span className="offer-meta">Steam {rub(h.steam_price_rub)} · Plati {rub(h.plati_min_rub)} · GGsel {rub(h.ggsel_min_rub)}</span>
                        <button type="button" className="btn ghost sm" onClick={() => runSearch(h.query, h.appid)}>Открыть</button>
                      </div>
                    </article>
                  ))}
                  {!dashboard?.recent_history?.length && <p className="muted">История появится после поиска в аккаунте.</p>}
                </div>
              </div>
              <div className="panel">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <h3 style={{ margin: 0 }}>Избранное</h3>
                  <button
                    type="button"
                    className="btn ghost sm"
                    onClick={async () => {
                      try {
                        const res = await api<{ message: string }>('/api/me/favorites/refresh', { method: 'POST' })
                        alert(res.message)
                        loadDashboard()
                      } catch (e) {
                        alert(e instanceof Error ? e.message : 'Ошибка')
                      }
                    }}
                  >
                    Обновить
                  </button>
                </div>
                <div className="list-cards" style={{ marginTop: 12 }}>
                  {watchlist.map((f) => (
                    <article key={f.appid} className="list-card">
                      {f.header_image ? <img src={f.header_image} alt="" /> : <div className="ph" />}
                      <div>
                        <strong>{f.game_name} {f.alert?.status === 'triggered' ? <span className="badge hot">сработал</span> : null}</strong>
                        <span className="offer-meta">цель {rub(f.alert?.target_value)} · {f.alert?.scopes?.map((scope) => `${scope.source}/${scope.offer_kind}`).join(' · ')}</span>
                        {f.release_status === 'announced' ? <span className="offer-meta">⏳ Ожидаем релиз в Steam — маркетплейсы пока не запрашиваются.</span> : null}
                        {f.freshness?.map((source) => <span className="offer-meta" key={source.source}>{source.source}: {source.status}{source.last_error ? ` · ${source.last_error}` : ''}</span>)}
                        <div className="actions">
                          <button type="button" className="btn ghost sm" onClick={() => runSearch(f.game_name, f.appid)}>Цены</button>
                          <button type="button" className="btn ghost sm" onClick={() => setAlertModal({ favorite: f, create: false })}>Настроить</button>
                          <button
                            type="button"
                            className="btn ghost sm"
                            onClick={async () => {
                              await api(`/api/me/favorites/${f.appid}`, { method: 'DELETE' })
                              loadDashboard()
                            }}
                          >
                            Убрать
                          </button>
                        </div>
                      </div>
                    </article>
                  ))}
                  {!watchlist.length && <p className="muted">Добавляй ☆ на карточке Steam.</p>}
                </div>
              </div>
            </div>
          </section>
        )}
        {adByPlacement('footer') && (
          <AdSlot slot={adByPlacement('footer')!} label={ads?.label} />
        )}
      </main>

      {alertModal && (
        <AlertSettingsModal
          favorite={alertModal.favorite}
          onClose={() => setAlertModal(null)}
          onSave={async ({ target_value, scopes }: { target_value: number | null; scopes: AlertScope[] }) => {
            const favorite = alertModal.favorite
            if (alertModal.create) {
              await api('/api/me/favorites', {
                method: 'POST',
                body: JSON.stringify({ appid: favorite.appid, game_name: favorite.game_name, header_image: favorite.header_image, alert: { target_value, scopes } }),
              })
              if (result) setResult({ ...result, is_favorite: true })
            } else {
              await api(`/api/me/favorites/${favorite.appid}`, { method: 'PATCH', body: JSON.stringify({ alert: { target_value, scopes } }) })
            }
            setAlertModal(null)
            await loadWatchlist()
            loadDashboard().catch(() => {})
            setToast('Настройки алерта сохранены')
          }}
        />
      )}

      <footer className="shell footer has-tabbar">
        <p>
          {BRAND.name} — {BRAND.tagline}. Мы не продаём ключи напрямую — покупка на сторонних площадках.
          Перед оплатой проверяйте продавца и условия.
        </p>
        {showAds && ads?.note && <p className="muted footer-note">{ads.note}</p>}
        {isPro && <p className="muted footer-note">Pro: реклама отключена ✨</p>}
      </footer>

      <AnimatePresence>
        {toast && (
          <motion.div className="toast" initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}>
            {toast}
          </motion.div>
        )}
      </AnimatePresence>

      <nav className="m-tabbar m-only" aria-label="Основное меню">
        <button type="button" className={view === 'home' ? 'active' : ''} onClick={() => setView('home')}>
          <span className="m-tab-ico" aria-hidden>⌕</span>
          Поиск
        </button>
        <button
          type="button"
          className={view === 'radar' ? 'active' : ''}
          onClick={() => {
            if (loggedIn) setView('radar')
            else {
              setAuthTab('login')
              setAuthOpen(true)
            }
          }}
        >
          <span className="m-tab-ico" aria-hidden>📡</span>
          Радар
        </button>
        <button type="button" className={view === 'plans' ? 'active' : ''} onClick={() => setView('plans')}>
          <span className="m-tab-ico" aria-hidden>★</span>
          Pro
        </button>
        <button
          type="button"
          className={view === 'cabinet' || authOpen ? 'active' : ''}
          onClick={() => {
            if (loggedIn) setView('cabinet')
            else {
              setAuthTab('login')
              setAuthOpen(true)
            }
          }}
        >
          <span className="m-tab-ico" aria-hidden>◎</span>
          {loggedIn ? 'Кабинет' : 'Вход'}
        </button>
      </nav>

      <AnimatePresence>
        {authOpen && (
          <motion.div className="modal-backdrop" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => setAuthOpen(false)}>
            <motion.div className="modal" initial={{ y: 40, opacity: 0 }} animate={{ y: 0, opacity: 1 }} exit={{ y: 20, opacity: 0 }} onClick={(e) => e.stopPropagation()}>
              <button type="button" className="modal-close" onClick={() => setAuthOpen(false)} aria-label="Закрыть">×</button>
              <div className="tabs">
                <button type="button" className={`tab ${authTab === 'login' ? 'active' : ''}`} onClick={() => setAuthTab('login')}>Вход</button>
                <button type="button" className={`tab ${authTab === 'register' ? 'active' : ''}`} onClick={() => setAuthTab('register')}>Регистрация</button>
              </div>
              <form className="auth-form" onSubmit={onAuth}>
                {authTab === 'register' && (
                  <label>
                    Имя
                    <input name="display_name" maxLength={80} placeholder="Как к вам обращаться" />
                  </label>
                )}
                <label>
                  Email
                  <input name="email" type="email" required autoComplete="email" />
                </label>
                <label>
                  Пароль
                  <input name="password" type="password" required minLength={8} maxLength={72} autoComplete={authTab === 'login' ? 'current-password' : 'new-password'} />
                </label>
                <button className="btn primary" type="submit" style={{ width: '100%' }}>
                  {authTab === 'login' ? 'Войти' : 'Создать аккаунт'}
                </button>
              </form>
              {authError && <p className="auth-error">{authError}</p>}
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  )
}

function AdSlot({ slot, label }: { slot: AdSlotDef; label?: string }) {
  const href = slot.click_url || '#'
  const isMailto = href.startsWith('mailto:')
  return (
    <aside className={`ad-slot ad-slot--${slot.format}`} aria-label={slot.title} data-ad-id={slot.id}>
      <div className={`ad-billboard ad-billboard--${slot.format}`}>
        <span className="ad-billboard__badge">{label || 'Реклама'}</span>
        <div className="ad-billboard__body">
          <div className="ad-billboard__icon" aria-hidden>
            ▣
          </div>
          <div className="ad-billboard__copy">
            <div className="ad-billboard__title">{slot.title}</div>
            {slot.subtitle && <div className="ad-billboard__subtitle">{slot.subtitle}</div>}
            {slot.size_hint && (
              <div className="ad-billboard__meta">
                Формат: {slot.size_hint}
                {slot.provider ? ` · ${slot.provider}` : ''}
              </div>
            )}
          </div>
          <a
            className="ad-billboard__cta btn sm primary"
            href={href}
            {...(isMailto ? {} : { target: '_blank', rel: 'noopener noreferrer sponsored' })}
          >
            {slot.cta || 'Разместить'}
          </a>
        </div>
      </div>
    </aside>
  )
}

function MarketCard({
  market,
  steamPrice,
  onTrack,
}: {
  market: Market
  steamPrice?: number | null
  onTrack: (mp: string, url: string, price?: number) => void
}) {
  if (market.error) {
    return (
      <article className={`panel market ${market.marketplace}`}>
        <h3>{market.label}</h3>
        <p className="muted">Не удалось загрузить: {market.error}</p>
      </article>
    )
  }
  if (!market.by_kind?.length) {
    return (
      <article className={`panel market ${market.marketplace}`}>
        <div className="market-head">
          <h3>{market.label}</h3>
          <span className="muted">0 офферов</span>
        </div>
        <p className="muted">Подходящих предложений не найдено.</p>
      </article>
    )
  }
  return (
    <article className={`panel market ${market.marketplace}`}>
      <div className="market-head">
        <h3>{market.label}</h3>
        <span className="muted">просмотрено {market.scanned_offers}</span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Тип</th>
            <th>Мин</th>
            <th>Средняя</th>
            <th>Популярный</th>
            <th>Дешёвый</th>
          </tr>
        </thead>
        <tbody>
          {market.by_kind.map((k) => (
            <tr key={k.kind}>
              <td data-label="Тип">
                <strong>{k.label}</strong>
                <span className="offer-meta">{k.count} шт.</span>
              </td>
              <td className="min" data-label="Мин">{rub(k.min_price)}</td>
              <td data-label="Средняя">{rub(k.avg_price)}</td>
              <td data-label="Популярный">
                {k.popular ? (
                  <>
                    <a
                      className="offer-link"
                      href={k.popular.url}
                      target="_blank"
                      rel="noreferrer sponsored"
                      onClick={() => onTrack(market.marketplace, k.popular!.url, k.popular!.price_rub)}
                    >
                      {rub(k.popular.price_rub)}
                    </a>
                    <span className="offer-meta">{k.popular.sales || 0} продаж</span>
                  </>
                ) : (
                  '—'
                )}
              </td>
              <td data-label="Дешёвый">
                {k.cheapest ? (
                  <a
                    className="offer-link"
                    href={k.cheapest.url}
                    target="_blank"
                    rel="noreferrer sponsored"
                    onClick={() => onTrack(market.marketplace, k.cheapest!.url, k.cheapest!.price_rub)}
                  >
                    {rub(k.cheapest.price_rub)}
                  </a>
                ) : (
                  '—'
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {steamPrice != null && (
        <p className="muted" style={{ marginTop: 8 }}>
          Мин. на площадке: {rub(Math.min(...market.by_kind.map((k) => k.min_price || Infinity).filter((n) => n < Infinity)))}
        </p>
      )}
    </article>
  )
}
