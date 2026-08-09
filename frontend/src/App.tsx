import { AnimatePresence, motion } from 'framer-motion'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { api, authHeaders, getStoredUser, getToken, setSession } from './api'
import type { User } from './api'
import { BrandMark } from './brand'
import { IconClose, IconGift, IconMoon, IconRadar, IconSearch, IconStar, IconSun, IconUser } from './icons'
import { useLocale } from './locale'
import { AlertSettingsModal } from './components/AlertSettingsModal'
import { AdminPanel } from './components/AdminPanel'
import { GameRail } from './components/GameRail'
import { useRevealOnScroll } from './useRevealOnScroll'
import { WatchlistAlerts } from './components/WatchlistAlerts'
import type { AlertItem, AlertPrefs, AlertScope, FavoriteItem } from './watchlist'
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

type GiftGame = { name: string; appid?: number | null; image?: string | null; received_at: number }
const GIFT_KEY = 'gpa_gift_v1'
const GIFT_COOLDOWN_MS = 3 * 24 * 60 * 60 * 1000
function loadGift(): GiftGame | null {
  try {
    return JSON.parse(localStorage.getItem(GIFT_KEY) || 'null') as GiftGame | null
  } catch {
    return null
  }
}

type Offer = { title: string; url: string; price_rub: number; sales?: number; seller_name?: string | null; kind?: string }
type KindStats = { kind: string; label: string; count: number; min_price: number | null; avg_price: number | null; popular?: Offer | null; cheapest?: Offer | null }
type Market = { marketplace: string; label: string; total_offers: number; scanned_offers: number; by_kind: KindStats[]; error?: string | null }
type Steam = { appid: number; name: string; header_image?: string | null; store_url: string; price_rub?: number | null; price_initial_rub?: number | null; discount_percent?: number; is_free?: boolean; available_in_ru?: boolean; note?: string | null; regional_prices?: { region: string; label: string; currency: 'RUB' | 'USD' | 'EUR' | 'KZT' | 'TRY'; amount: number; price_rub?: number | null }[] }
type Deal = { score: number; label: string; is_better: boolean; market_min_rub?: number | null; market_source?: string | null; savings_rub?: number | null; savings_percent?: number | null }
type Freshness = { source: string; status: string; last_success_at?: string | null; next_refresh_at?: string | null }
type PriceResponse = { query: string; steam: Steam | null; candidates: { appid: number; name: string; tiny_image?: string; price_rub?: number | null }[]; plati: Market; ggsel: Market; warnings: string[]; saved_to_history?: boolean; is_favorite?: boolean; deal?: Deal | null; refreshing?: boolean; freshness?: Freshness[] }
type PopularItem = { query: string; game_name?: string | null; appid?: number | null; header_image?: string | null; count?: number }
type Fav = { id: number; appid: number; game_name: string; header_image?: string | null; target_price_rub?: number | null; last_steam_price_rub?: number | null; price_below_target?: boolean }
type Hist = { id: number; query: string; appid?: number | null; game_name?: string | null; header_image?: string | null; steam_price_rub?: number | null; plati_min_rub?: number | null; ggsel_min_rub?: number | null; created_at?: string }
type WeeklyDeal = {
  appid: number
  name: string
  header_image?: string | null
  price_initial_rub?: number | null
  price_final_rub?: number | null
  discount_percent?: number | null
  savings_rub?: number | null
}
type WeeklyDeals = {
  generated_at?: string
  refresh_interval_minutes?: number
  source?: string
  items: WeeklyDeal[]
}
type PlatiRoulettePick = { appid: number; name: string; header_image?: string | null; offer_kind?: string | null; price_rub?: number | null; url?: string | null; source?: 'saved_plati' | 'steam_showcase' }
const ROULETTE_HISTORY_KEY = 'igroscan_roulette_history_v1'

function loadRouletteHistory(): number[] {
  try {
    const stored = JSON.parse(sessionStorage.getItem(ROULETTE_HISTORY_KEY) || '[]') as unknown
    return Array.isArray(stored) ? stored.map(Number).filter(Number.isFinite).slice(0, 40) : []
  } catch {
    return []
  }
}

type SuggestItem = { appid: number; name: string; tiny_image?: string; price_rub?: number | null }
const suggestCache = new Map<string, SuggestItem[]>()

// Живой поиск в Steam (storesearch) — актуальные цены/картинки для списка кандидатов.
async function discover(q: string): Promise<SuggestItem[]> {
  const d = await api<{ candidates: SuggestItem[] }>(`/api/search?q=${encodeURIComponent(q)}`)
  return (d.candidates || []).slice(0, 8)
}

function mergeCandidates(live: { appid: number; name: string; tiny_image?: string | null; price_rub?: number | null }[], stored: typeof live): SuggestItem[] {
  const seen = new Map<number, (typeof live)[number]>()
  for (const c of live) seen.set(c.appid, c)
  for (const c of stored) if (!seen.has(c.appid)) seen.set(c.appid, c)
  return [...seen.values()].slice(0, 8).map((c) => ({ ...c, tiny_image: c.tiny_image ?? undefined }))
}

// Битая картинка (например, 404 на CDN Steam) — прячем её, чтобы не было разбитых иконок.
const hideBrokenImg = (e: React.SyntheticEvent<HTMLImageElement>) => {
  const el = e.currentTarget
  el.onerror = null
  el.style.display = 'none'
}

function useTheme() {
  const [theme, setTheme] = useState(() => localStorage.getItem('gpa_theme') || 'dark')
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme)
    localStorage.setItem('gpa_theme', theme)
    const meta = document.querySelector('meta[name="theme-color"]')
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#0a0e13' : '#eef1f5')
  }, [theme])
  return { theme, toggle: () => setTheme((t) => (t === 'dark' ? 'light' : 'dark')) }
}

export default function App() {
  const { theme, toggle } = useTheme()
  const { locale, currency, currencyReady, formatPrice: money, formatAmount, tr } = useLocale()
  const [user, setUser] = useState<User | null>(getStoredUser())
  const [token, setToken] = useState<string | null>(getToken())
  const [view, setView] = useState<'home' | 'cabinet' | 'guide' | 'admin' | 'radar' | 'favorites' | 'account'>('home')
  const [linkCode, setLinkCode] = useState<string | null>(null)
  const [linkDeep, setLinkDeep] = useState<string | null>(null)
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
  const [deals, setDeals] = useState<WeeklyDeal[]>([])
  const [releases, setReleases] = useState<WeeklyDeal[]>([])
  const [popularLoading, setPopularLoading] = useState(true)
  const [dealsLoading, setDealsLoading] = useState(true)
  const [releasesLoading, setReleasesLoading] = useState(true)
  const [releaseRefreshMinutes, setReleaseRefreshMinutes] = useState(30)
  const [rouletteBusy, setRouletteBusy] = useState(false)
  const [marketTab, setMarketTab] = useState<'plati' | 'ggsel'>('plati')
  const [aboutOpen, setAboutOpen] = useState(false)
  const [suggests, setSuggests] = useState<{ appid: number; name: string; tiny_image?: string; price_rub?: number | null }[]>([])
  const [suggestOpen, setSuggestOpen] = useState(false)
  const [suggestStatus, setSuggestStatus] = useState<'idle' | 'searching' | 'empty'>('idle')
  const [tgStatus, setTgStatus] = useState<{
    linked: boolean
    identity_linked?: boolean
    oidc_available?: boolean
    telegram_username?: string | null
    radar_enabled?: boolean
    bot_username?: string | null
  } | null>(null)
  const [tgBusy, setTgBusy] = useState(false)
  const [watchlist, setWatchlist] = useState<FavoriteItem[]>([])
  const [alertItems, setAlertItems] = useState<AlertItem[]>([])
  const [alertModal, setAlertModal] = useState<{ favorite: FavoriteItem; create: boolean } | null>(null)
  const [gift, setGift] = useState<GiftGame | null>(() => loadGift())
  const lastSearchRef = useRef<{ q: string; appid: number | null } | null>(null)
  const rouletteHistoryRef = useRef<number[]>(loadRouletteHistory())
  const livePollRef = useRef(0)
  const [marketPollingTimedOut, setMarketPollingTimedOut] = useState(false)
  const [profileOpen, setProfileOpen] = useState(false)
  const [pwdCurrent, setPwdCurrent] = useState('')
  const [pwdNew, setPwdNew] = useState('')
  const [pwdConfirm, setPwdConfirm] = useState('')
  const [pwdError, setPwdError] = useState('')
  const [pwdSaving, setPwdSaving] = useState(false)

  const loggedIn = Boolean(token && user)
  useRevealOnScroll(view === 'home', `${popular.length}:${releases.length}:${deals.length}:${result ? 1 : 0}`)

  const giftReady = !gift || Date.now() - gift.received_at >= GIFT_COOLDOWN_MS
  function receiveGift() {
    if (!tgStatus?.linked) {
      setToast(tr('Сначала привяжи Telegram в Радаре', 'Connect Telegram in Radar first'))
      setView('radar')
      return
    }
    if (gift && !giftReady) {
      const left = Math.ceil((GIFT_COOLDOWN_MS - (Date.now() - gift.received_at)) / (24 * 60 * 60 * 1000))
      setToast(`${tr('Следующий подарок через', 'Next gift in')} ${left} ${tr('дн.', 'days')}`)
      return
    }
    const pool: PopularItem[] = popular.length
      ? popular
      : [
          { query: 'Hollow Knight', game_name: 'Hollow Knight', appid: 367520 },
          { query: 'Hades', game_name: 'Hades', appid: 1145360 },
          { query: 'Disco Elysium', game_name: 'Disco Elysium', appid: 632470 },
          { query: 'Terraria', game_name: 'Terraria', appid: 105600 },
          { query: 'Stardew Valley', game_name: 'Stardew Valley', appid: 413150 },
        ]
    const pick = pool[Math.floor(Math.random() * pool.length)]
    const next: GiftGame = { name: pick.game_name || pick.query, appid: pick.appid ?? null, image: pick.header_image ?? null, received_at: Date.now() }
    setGift(next)
    localStorage.setItem(GIFT_KEY, JSON.stringify(next))
    setToast(tr('Подарок получен! Игра в кабинете', 'Gift claimed! The game is in your dashboard'))
  }

  useEffect(() => {
    const onTelegramOidc = (event: MessageEvent) => {
      if (event.origin !== window.location.origin) return
      const payload = event.data as { type?: string; ok?: boolean; detail?: string; access_token?: string; user?: User }
      if (payload.type !== 'igroscan:telegram-oidc') return
      if (!payload.ok || !payload.access_token || !payload.user) {
        setToast(payload.detail || tr('Не удалось подтвердить Telegram', 'Could not confirm Telegram'))
        return
      }
      setSession(payload.access_token, payload.user)
      setToken(payload.access_token)
      setUser(payload.user)
      setTgStatus((current) => (current ? { ...current, identity_linked: true } : current))
      setToast(tr('Telegram подтверждён — аккаунты объединены', 'Telegram confirmed—accounts merged'))
    }
    window.addEventListener('message', onTelegramOidc)
    return () => window.removeEventListener('message', onTelegramOidc)
  }, [tr])

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
      .then((d) => setPopular(d.items ?? []))
      .catch(() => {})
      .finally(() => setPopularLoading(false))
    api<WeeklyDeals>('/api/deals/steam')
      .then((d) => setDeals(d.items ?? []))
      .catch(() => {})
      .finally(() => setDealsLoading(false))
    api<WeeklyDeals>('/api/releases/steam')
      .then((d) => {
        setReleases(d.items ?? [])
        setReleaseRefreshMinutes(d.refresh_interval_minutes ?? 30)
      })
      .catch(() => {})
      .finally(() => setReleasesLoading(false))
  }, [refreshMe])

  const spinRoulette = async () => {
    if (rouletteBusy || loading) return
    setRouletteBusy(true)
    try {
      const [pick] = await Promise.all([
        api<PlatiRoulettePick>(`/api/roulette/plati?exclude=${rouletteHistoryRef.current.join(',')}`),
        new Promise((resolve) => window.setTimeout(resolve, 850)),
      ])
      rouletteHistoryRef.current = [pick.appid, ...rouletteHistoryRef.current.filter((appid) => appid !== pick.appid)].slice(0, 40)
      sessionStorage.setItem(ROULETTE_HISTORY_KEY, JSON.stringify(rouletteHistoryRef.current))
      setToast(tr('Игра выбрана — сравниваем цены.', 'Game picked—comparing prices.'))
      await runSearch(pick.name, pick.appid)
    } catch {
      const combined = [...releases, ...deals].filter((item, index, items) => items.findIndex((candidate) => candidate.appid === item.appid) === index)
      const fresh = combined.filter((item) => !rouletteHistoryRef.current.includes(item.appid))
      const pool = fresh.length ? fresh : combined
      const pick = pool[Math.floor(Math.random() * pool.length)]
      if (pick) {
        rouletteHistoryRef.current = [pick.appid, ...rouletteHistoryRef.current.filter((appid) => appid !== pick.appid)].slice(0, 40)
        sessionStorage.setItem(ROULETTE_HISTORY_KEY, JSON.stringify(rouletteHistoryRef.current))
        setToast(tr('Игра выбрана — сравниваем цены.', 'Game picked—comparing prices.'))
        await runSearch(pick.name, pick.appid)
      } else {
        setToast(tr('Рулетка пока пуста — попробуйте чуть позже.', 'The roulette is empty—try again shortly.'))
      }
    } finally {
      setRouletteBusy(false)
    }
  }

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
        identity_linked?: boolean
        oidc_available?: boolean
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
    if (loggedIn && (view === 'cabinet' || view === 'favorites')) {
      loadDashboard().catch(() => {})
      loadWatchlist().catch(() => {})
    }
    if (loggedIn && (view === 'cabinet' || view === 'radar')) loadTgStatus()
  }, [loggedIn, view, loadDashboard, loadWatchlist, loadTgStatus])

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
      setSuggestStatus('idle')
      return
    }
    const key = q.toLowerCase()
    const cached = suggestCache.get(key)
    if (cached) {
      setSuggests(cached)
      setSuggestStatus(cached.length ? 'idle' : 'empty')
      return
    }
    let cancelled = false
    const t = window.setTimeout(() => {
      setSuggestStatus('searching')
      api<{ candidates: SuggestItem[] }>(`/api/search?q=${encodeURIComponent(q)}`)
        .then((d) => {
          if (cancelled) return
          const items = (d.candidates || []).slice(0, 8)
          suggestCache.set(key, items)
          setSuggests(items)
          setSuggestStatus(items.length === 0 ? 'empty' : 'idle')
        })
        .catch(() => {
          // 429/Too Many Attempts и прочие ошибки — молча не показываем список
          if (!cancelled) {
            setSuggests([])
            setSuggestStatus('idle')
          }
        })
    }, 250)
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

  async function runSearch(q: string, appid?: number | null, opts?: { force?: boolean; silent?: boolean }) {
    const term = q.trim()
    if (!term) return
    const force = Boolean(opts?.force)
    const silent = Boolean(opts?.silent)
    if (!silent) {
      livePollRef.current = 0
      setMarketPollingTimedOut(false)
    }
    setView('home')
    setQuery(term)
    if (!silent) setLoading(true)
    setError('')
    try {
      const params = new URLSearchParams({ q: term })
      if (appid) params.set('appid', String(appid))
      if (force) params.set('force', '1')
      if (silent) params.set('background', '1')
      const data = await api<PriceResponse>(`/api/prices?${params}`, { headers: authHeaders() })
      let candidates = data.candidates ?? []
      // Для известного appid ответ уже однозначен: живой поиск кандидатов здесь
      // только добавлял лишнюю сетевую задержку карточкам скидок и релизов.
      if (!data.steam && !appid) {
        try {
          const cached = suggestCache.get(term.toLowerCase())
          const live = cached && cached.length ? cached : await discover(term)
          candidates = mergeCandidates(live, data.candidates ?? [])
        } catch { /* оставляем сохранённых кандидатов */ }
      }
      // Первый поиск неизвестной игры: сразу берём лучшего кандидата
      // и запускаем парсинг всех источников (Steam + Plati + GGsel) без лишних кликов.
      if (!data.steam && candidates.length > 0) {
        const top = candidates[0]
        setSuggestOpen(false)
        setQuery(top.name)
        await runSearch(top.name, top.appid, { force: true })
        return
      }
      lastSearchRef.current = { q: term, appid: appid ?? data.steam?.appid ?? null }
      setResult((current) => ({
        ...data,
        candidates,
        saved_to_history: silent && current ? current.saved_to_history : data.saved_to_history,
      }))
      pushRecent(term, appid ?? data.steam?.appid)
      setRecents(loadRecents())
      const url = new URL(window.location.href)
      url.searchParams.set('q', term)
      if (data.steam?.appid) url.searchParams.set('appid', String(data.steam.appid))
      else url.searchParams.delete('appid')
      window.history.replaceState({}, '', url.toString())
      if (loggedIn) loadDashboard().catch(() => {})
    } catch (e) {
      const msg = e instanceof Error ? e.message : tr('Ошибка поиска', 'Search error')
      setError(
        msg.includes('Too Many Attempts') || msg.includes('429')
          ? tr('Слишком много запросов. Подожди 5–10 секунд и попробуй снова.', 'Too many requests. Wait 5–10 seconds and try again.')
          : msg,
      )
      setResult(null)
    } finally {
      setLoading(false)
    }
  }

  // Пока маркетплейсы обновляются — тихо повторяем поиск каждые 4 секунды (максимум ~8 попыток).
  useEffect(() => {
    if (!result?.refreshing) {
      livePollRef.current = 0
      setMarketPollingTimedOut(false)
      return
    }
    if (livePollRef.current >= 8) {
      setMarketPollingTimedOut(true)
      return
    }
    const t = window.setInterval(() => {
      livePollRef.current += 1
      if (livePollRef.current > 8) {
        window.clearInterval(t)
        setMarketPollingTimedOut(true)
        return
      }
      const last = lastSearchRef.current
      if (last) runSearch(last.q, last.appid, { silent: true })
    }, 4000)
    return () => window.clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [result?.refreshing, result?.steam?.appid])

  function shareResult() {
    if (!result) return
    const url = window.location.href
    const title = result.steam?.name || result.query
    if (navigator.share) {
      navigator.share({ title: `${title} — ${tr('Игроскан', 'Igroscan')}`, url }).catch(() => {})
    } else {
      navigator.clipboard.writeText(url).then(() => setToast(tr('Ссылка скопирована', 'Link copied'))).catch(() => setToast(url))
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
    setProfileOpen(false)
    setView('home')
  }

  async function submitPassword(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setPwdError('')
    setPwdSaving(true)
    try {
      await api('/api/auth/password', {
        method: 'POST',
        body: JSON.stringify({ current_password: pwdCurrent, password: pwdNew, password_confirmation: pwdConfirm }),
      })
      setPwdCurrent('')
      setPwdNew('')
      setPwdConfirm('')
      setToast(tr('Пароль обновлён', 'Password updated'))
    } catch (err) {
      setPwdError(err instanceof Error ? err.message : tr('Не удалось сменить пароль', 'Could not change password'))
    } finally {
      setPwdSaving(false)
    }
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
        await api('/api/me/favorites', {
          method: 'POST',
          body: JSON.stringify({ appid: steam.appid, game_name: steam.name, header_image: steam.header_image }),
        })
        setResult({ ...result, is_favorite: true })
        setToast(tr('Добавлено в избранное', 'Added to watchlist'))
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
  const steamRegionalPrice = useMemo(
    () => result?.steam?.regional_prices?.find((price) => price.currency === currency) ?? null,
    [currency, result],
  )

  useEffect(() => {
    if (!profileOpen) return
    const onDown = (e: MouseEvent) => {
      if (e.target instanceof Element && !e.target.closest('.profile-wrap')) setProfileOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setProfileOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    window.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      window.removeEventListener('keydown', onKey)
    }
  }, [profileOpen])

  const popularChips = popular.filter((p) => p.header_image || p.game_name || p.query)

  const goHome = () => {
    if (view === 'home') {
      window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
      return
    }
    setView('home')
    window.requestAnimationFrame(() => window.scrollTo({ top: 0 }))
  }

  return (
    <>
      <BootSplash ready={currencyReady && !popularLoading && !dealsLoading && !releasesLoading} />
      <div className="app-bg" aria-hidden />
      <header className="header">
        <div className="header-inner">
          <button type="button" className="brand" onClick={goHome} aria-label={tr('На главную', 'Home')}>
            <span className="brand-mark-wrap">
              <BrandMark size={30} />
            </span>
            <span className="brand-text">
              <svg className="brand-decor" aria-hidden="true" viewBox="0 0 140 36" fill="none">
                <circle cx="8" cy="30" r="9" />
                <circle cx="8" cy="30" r="20" />
                <circle cx="8" cy="30" r="32" />
                <circle cx="8" cy="30" r="2.2" fill="currentColor" />
              </svg>
              <span className="brand-name">{tr('Игроскан', 'Igroscan')}</span>
            </span>
          </button>
          {loggedIn ? (
            <div className="profile-cluster m-only">
              <LanguageCurrencyControls compact />
              <button type="button" className="btn ghost sm icon-btn theme-toggle" onClick={toggle} aria-label={tr('Тема', 'Theme')}>
                {theme === 'dark' ? <IconSun size={18} /> : <IconMoon size={18} />}
              </button>
              <div className="profile-wrap">
                <button type="button" className="profile-btn" onClick={() => setProfileOpen((v) => !v)} aria-haspopup="menu" aria-expanded={profileOpen}>
                  <span className="avatar">{(user?.display_name || user?.email || '?').charAt(0).toUpperCase()}</span>
                </button>
                {profileOpen && (
                  <div className="profile-menu" role="menu">
                    <button type="button" role="menuitem" onClick={() => { setProfileOpen(false); setView('cabinet') }}>{tr('Кабинет', 'Dashboard')}</button>
                    <button type="button" role="menuitem" onClick={() => { setProfileOpen(false); setView('account') }}>{tr('Настройки', 'Settings')}</button>
                    <div className="profile-menu-sep" />
                    <button type="button" role="menuitem" className="danger" onClick={() => { setProfileOpen(false); logout() }}>{tr('Выйти', 'Sign out')}</button>
                  </div>
                )}
              </div>
            </div>
          ) : (
            <div className="profile-cluster m-only">
              <LanguageCurrencyControls compact />
              <button type="button" className="btn ghost sm icon-btn theme-toggle" onClick={toggle} aria-label={tr('Тема', 'Theme')}>
                {theme === 'dark' ? <IconSun size={18} /> : <IconMoon size={18} />}
              </button>
            </div>
          )}
          <div className="header-actions desk-only" data-auth={loggedIn ? 'user' : 'guest'}>
            <button type="button" className="btn ghost sm" onClick={() => setView('guide')}>
              {tr('Как пользоваться', 'How it works')}
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
              <IconRadar size={16} /> {tr('Радар', 'Radar')}
            </button>
            <LanguageCurrencyControls />
            {user?.can_access_admin && (
              <button type="button" className="btn ghost sm" onClick={() => setView('admin')}>
                Admin
              </button>
            )}
            {loggedIn ? (
              <div className="profile-cluster">
                <button type="button" className="btn ghost sm icon-btn theme-toggle" onClick={toggle} aria-label={tr('Тема', 'Theme')}>
                  {theme === 'dark' ? <IconSun size={18} /> : <IconMoon size={18} />}
                </button>
                <div className="profile-wrap">
                  <button type="button" className="profile-btn" onClick={() => setProfileOpen((v) => !v)} aria-haspopup="menu" aria-expanded={profileOpen}>
                    <span className="avatar">{(user?.display_name || user?.email || '?').charAt(0).toUpperCase()}</span>
                    <span className="profile-name">{user?.display_name || user?.email}</span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="m6 9 6 6 6-6"/></svg>
                  </button>
                  {profileOpen && (
                    <div className="profile-menu" role="menu">
                      <button type="button" role="menuitem" onClick={() => { setProfileOpen(false); setView('cabinet') }}>{tr('Кабинет', 'Dashboard')}</button>
                      <button type="button" role="menuitem" onClick={() => { setProfileOpen(false); setView('account') }}>{tr('Настройки', 'Settings')}</button>
                      <div className="profile-menu-sep" />
                      <button type="button" role="menuitem" className="danger" onClick={() => { setProfileOpen(false); logout() }}>{tr('Выйти', 'Sign out')}</button>
                    </div>
                  )}
                </div>
              </div>
            ) : (
              <>
                <button type="button" className="btn ghost sm icon-btn theme-toggle" onClick={toggle} aria-label={tr('Тема', 'Theme')}>
                  {theme === 'dark' ? <IconSun size={18} /> : <IconMoon size={18} />}
                </button>
                <button type="button" className="btn ghost" onClick={() => { setAuthTab('login'); setAuthOpen(true) }}>{tr('Войти', 'Sign in')}</button>
                <button type="button" className="btn primary" onClick={() => { setAuthTab('register'); setAuthOpen(true) }}>
                  {tr('Регистрация', 'Create account')}
                </button>
              </>
            )}
          </div>
        </div>
      </header>

      <main className="shell has-tabbar">
        {view === 'home' && (
          <>
            <section className="hero hero-search" data-reveal="home-search">
              <p className="eyebrow desk-only">{tr('Сравнение цен · Steam и маркетплейсы', 'Price comparison · Steam and marketplaces')}</p>
              <h2 className="search-title">{tr('Найти цену', 'Find the best price')}</h2>
              <p className="lead desk-only">
                {tr(
                  'Игроскан сравнивает Steam RU, Plati.Market и GGsel. Мы не продаём ключи — собираем цены и ссылки, чтобы быстрее решить, где выгоднее.',
                  'Igroscan compares Steam RU, Plati.Market, and GGsel. We do not sell keys—we collect prices and links so you can quickly find the better deal.',
                )}
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
                    aria-expanded={suggestOpen && query.trim().length >= 2 && (suggests.length > 0 || suggestStatus === 'empty')}
                  />
                  {suggestOpen && query.trim().length >= 2 && (suggests.length > 0 || suggestStatus === 'empty') && (
                    <ul className="suggest-list" role="listbox">
                      {suggests.length === 0 ? (
                        <li className="suggest-empty">{tr('Ничего не найдено — попробуй другое название.', 'Nothing found—try another title.')}</li>
                      ) : (
                        suggests.map((s) => (
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
                              {s.tiny_image ? <img src={s.tiny_image} alt="" onError={hideBrokenImg} /> : <span className="ph" />}
                              <span className="suggest-name">{s.name}</span>
                              {s.price_rub != null ? <span className="suggest-price">{money(s.price_rub)}</span> : null}
                            </button>
                          </li>
                        ))
                      )}
                    </ul>
                  )}
                </div>
                <button className="btn primary" type="submit" disabled={loading}>
                  {loading ? tr('Ищем…', 'Searching…') : tr('Сравнить', 'Compare')}
                </button>
              </form>
              <div className="hero-utility-row">
                <p className="price-refresh-note">
                  <span aria-hidden="true" />
                  {tr(
                    'Цены хранятся по каждому источнику и обновляются примерно раз в 3 часа. Новую игру добавляем в очередь при первом поиске.',
                    'Prices are cached per source and refresh about every 3 hours. A newly searched game is queued on its first search.',
                  )}
                </p>
                <button type="button" className={`roulette-btn ${rouletteBusy ? 'spinning' : ''}`} onClick={spinRoulette} disabled={rouletteBusy || loading}>
                  <span className="roulette-orb" aria-hidden="true"><i /><i /><i /></span>
                  <span><strong>{rouletteBusy || loading ? tr('Подбираем…', 'Picking…') : tr('Во что поиграть?', 'What should I play?')}</strong><small>{tr('Случайная игра', 'Random game')}</small></span>
                </button>
              </div>

              {loading && <div className="search-progress"><span className="spinner" aria-hidden="true" />{tr('Ищем игру в каталоге', 'Searching the game catalog')}</div>}
              {error && <div className="status error">{error}</div>}

              {result && (
                <section className="section">
                  {result.warnings?.filter((w) => !w.includes('локальном каталоге')).length > 0 && (
                    <div className="status" style={{ marginBottom: 12 }}>
                      {result.warnings
                        .filter((w) => !w.includes('локальном каталоге'))
                        .map((w) => (
                          <div key={w}>{w}</div>
                        ))}
                    </div>
                  )}
                  {!result.steam && (!result.candidates || result.candidates.length === 0) && (
                    <div className="empty-state">
                      <h3>{tr('Ничего не найдено', 'Nothing found')}</h3>
                      <p className="muted">{tr('Попробуй другое название или выбери игру из подсказок.', 'Try another title or select a game from the suggestions.')}</p>
                    </div>
                  )}
                  {result.refreshing && !marketPollingTimedOut && result.steam && (
                    <div className="search-progress search-progress-background">
                      <span className="spinner" aria-hidden="true" />
                      {result.plati.total_offers + result.ggsel.total_offers > 0
                        ? tr('Уточняем актуальность предложений в фоне', 'Refreshing offer freshness in the background')
                        : tr('Собираем предложения с площадок — они появятся автоматически', 'Collecting marketplace offers—they will appear automatically')}
                    </div>
                  )}
                  {result.refreshing && !marketPollingTimedOut && !result.steam && (
                    <div className="search-progress search-progress-background">
                      <span className="spinner" aria-hidden="true" />
                      {tr('Добавляем игру в каталог', 'Adding the game to the catalog')}
                    </div>
                  )}

                  {!result.steam && result.candidates?.length > 0 && (
                    <div className="panel suggest-hint">
                      {tr('Точного совпадения нет. Выбери игру из подсказок, чтобы сравнить цены.', 'No exact match. Select a game from the suggestions to compare prices.')}
                    </div>
                  )}

                  {result.steam && (
                    <article className="hero steam-card" style={{ marginTop: 0 }}>
                      <div className="steam-card-media">{result.steam.header_image ? <img src={result.steam.header_image} alt="" onError={hideBrokenImg} /> : null}</div>
                      <div>
                        <div className="steam-badge-row">
                          <span className={`badge ${result.steam.available_in_ru ? 'ok' : 'warn'}`}>
                            {result.steam.available_in_ru ? 'Steam RU' : tr('не в RU', 'not in RU')}
                          </span>
                          {(result.steam.discount_percent || 0) > 0 && (
                            <span className="badge hot">−{result.steam.discount_percent}%</span>
                          )}
                          {result.saved_to_history && <span className="badge ok">{tr('в истории', 'in history')}</span>}
                        </div>
                        <h2 style={{ margin: '0.4rem 0' }}>{result.steam.name}</h2>
                        <div className="price-xl">
                          {result.steam.is_free
                            ? tr('Бесплатно', 'Free')
                            : steamRegionalPrice
                              ? formatAmount(steamRegionalPrice.amount, steamRegionalPrice.currency)
                              : money(result.steam.price_rub)}
                          {result.steam.price_initial_rub &&
                            !steamRegionalPrice &&
                            result.steam.price_rub != null &&
                            result.steam.price_initial_rub > result.steam.price_rub && (
                              <span className="old">{money(result.steam.price_initial_rub)}</span>
                            )}
                        </div>
                        {result.steam.note && <p className="muted">{result.steam.note}</p>}
                        <div className="actions">
                          <a className="btn ghost" href={result.steam.store_url} target="_blank" rel="noreferrer">
                            Steam
                          </a>
                          <button type="button" className={`btn ${result.is_favorite ? 'primary' : 'ghost'}`} onClick={toggleFavorite}>
                            {result.is_favorite ? tr('★ В избранном', '★ Watching') : tr('☆ В избранное', '☆ Watch')}
                          </button>
                          {result.is_favorite && (
                            <button
                              type="button"
                              className="btn ghost"
                              onClick={() => {
                                const steam = result.steam
                                if (!steam) return
                                setAlertModal({
                                  favorite: { id: 0, appid: steam.appid, game_name: steam.name, header_image: steam.header_image },
                                  create: false,
                                })
                              }}
                            >
                              {tr('Алерт', 'Alert')}
                            </button>
                          )}
                          <button type="button" className="btn ghost" onClick={shareResult}>
                            {tr('Поделиться', 'Share')}
                          </button>
                        </div>
                      </div>
                      {result.deal && (
                        <div className={`deal-card ${result.deal.is_better ? 'hot' : ''}`}>
                          <div className="deal-score" aria-label={`${tr('Оценка выгоды', 'Value score')}: ${result.deal.score} ${tr('из 100', 'out of 100')}`}>
                            <span>{tr('выгода', 'value')}</span>
                            <b>{result.deal.score}</b>
                            <i aria-hidden="true"><em style={{ width: `${Math.min(100, Math.max(0, result.deal.score))}%` }} /></i>
                          </div>
                          <div className="deal-copy">
                            <strong>{result.deal.label}</strong>
                            <span className="offer-meta">
                              {tr('рынок от', 'market from')} {money(result.deal.market_min_rub)}
                              {result.deal.savings_percent != null
                                ? ` · ${result.deal.savings_percent > 0 ? '−' : ''}${Math.abs(result.deal.savings_percent)}% vs Steam`
                                : ''}
                            </span>
                          </div>
                        </div>
                      )}
                    </article>
                  )}

                  {(result.steam || (result.candidates?.length ?? 0) > 0) && (
                    <MarketplaceBrowser
                      markets={[result.plati, result.ggsel]}
                      active={marketTab}
                      onChange={(marketplace) => setMarketTab(marketplace as 'plati' | 'ggsel')}
                      steamPrice={steamPrice}
                      onTrack={trackClick}
                    />
                  )}
                </section>
              )}

              <div className="history-under-search">
                <div className="history-under-head">
                  <span className="history-label">{tr('Недавние', 'Recent')}</span>
                  {recents.length > 0 && (
                    <button
                      type="button"
                      className="btn ghost sm"
                      onClick={() => {
                        localStorage.removeItem(RECENT_KEY)
                        setRecents([])
                      }}
                    >
                      {tr('Очистить', 'Clear')}
                    </button>
                  )}
                </div>
                {recents.length > 0 ? (
                  <div className="recent-row" aria-label={tr('Недавние поиски', 'Recent searches')}>
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
                    {tr('Пока пусто — последние запросы появятся после первого поиска.', 'Empty for now—your recent queries will appear after the first search.')}
                  </p>
                )}
              </div>

              <div className="pills desk-only">
                <span className="pill steam">Steam RU</span>
                <span className="pill plati">Plati.Market</span>
                <span className="pill ggsel">GGsel</span>
              </div>
            </section>

            <section className="section panel about-panel">
              <button type="button" className="about-toggle" onClick={() => setAboutOpen((v) => !v)} aria-expanded={aboutOpen}>
                <h3 style={{ margin: 0 }}>{tr('Зачем это нужно', 'Why use it')}</h3>
                <span className="muted">{aboutOpen ? tr('свернуть', 'collapse') : tr('подробнее', 'learn more')}</span>
              </button>
              <div className={`about-body ${aboutOpen ? 'open' : ''}`}>
                <p className="lead" style={{ marginBottom: '1rem' }}>
                  {tr(
                    'Цена игры зависит от региона, скидки и типа товара: ключ, гифт, аккаунт или аренда. Игроскан собирает варианты на одном экране и показывает разницу со Steam.',
                    'A game price depends on region, discount, and offer type: key, gift, account, or rental. Igroscan puts the options on one screen and shows the difference versus Steam.',
                  )}
                </p>
                <div className="steps">
                  <div className="step">
                    <h4>{tr('Экономия времени', 'Save time')}</h4>
                    <p>{tr('Не нужно открывать три вкладки и вручную сравнивать предложения.', 'No need to open three tabs and compare offers manually.')}</p>
                  </div>
                  <div className="step">
                    <h4>{tr('Понятная выгода', 'Clear value')}</h4>
                    <p>{tr('Оценка относительно Steam, минимальная и средняя цена, прямые ссылки.', 'Steam comparison, minimum and average prices, and direct links.')}</p>
                  </div>
                  <div className="step">
                    <h4>{tr('Следи за ценой', 'Track prices')}</h4>
                    <p>{tr('В аккаунте доступны история, избранное и целевые цены.', 'Your account keeps history, watchlist, and target prices.')}</p>
                  </div>
                </div>
              </div>
            </section>

            <section className="section radar-cta" data-reveal="home-radar">
              <div className="radar-cta-copy">
                <p className="eyebrow">{tr('Радар цен', 'Price radar')}</p>
                <h3>{tr('Не пропусти выгодную цену', 'Never miss a good price')}</h3>
                <p className="muted">
                  {tr('Радар и бот', 'Radar and bot')} <strong>@igroscan_bot</strong>: {tr('избранное, целевая цена и уведомления в Telegram.', 'watchlist, target prices, and Telegram notifications.')}
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
                  {loggedIn ? tr('Настроить радар', 'Set up radar') : tr('Войти и включить радар', 'Sign in and enable radar')}
                </button>
              </div>
              <div className="radar-cta-visual" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
                <i></i>
              </div>
            </section>

            {(popularLoading || popularChips.length > 0) && (
              <section className="section panel home-rail-section" data-reveal="home-popular">
                <div className="section-heading"><div><p className="eyebrow">{tr('В фокусе', 'In focus')}</p><h3>{tr('Популярные игры', 'Popular games')}</h3></div><span className="muted">{tr('Лента листается автоматически', 'Auto-rotating')}</span></div>
                {popularLoading ? <RailSkeleton /> : <GameRail
                  previousLabel={tr('Предыдущие игры', 'Previous games')}
                  nextLabel={tr('Следующие игры', 'Next games')}
                  items={popularChips.map((item) => ({
                    id: item.appid ?? item.query,
                    name: item.game_name || item.query,
                    image: item.header_image,
                    meta: item.count ? tr(`${item.count} поисков`, `${item.count} searches`) : null,
                    onClick: () => runSearch(item.query, item.appid),
                  }))}
                />}
              </section>
            )}

            <section className="section panel home-rail-section" data-reveal="home-releases">
                <div className="section-heading"><div><p className="eyebrow">Steam</p><h3>{tr('Свежие релизы', 'New releases')}</h3></div><span className="muted">{tr(`Витрина обновляется каждые ${releaseRefreshMinutes} мин.`, `Showcase refreshes every ${releaseRefreshMinutes} min.`)}</span></div>
                {releasesLoading ? <RailSkeleton /> : releases.length > 0 ? <GameRail
                  previousLabel={tr('Предыдущие новинки', 'Previous releases')}
                  nextLabel={tr('Следующие новинки', 'Next releases')}
                  items={releases.map((item) => ({
                    id: item.appid,
                    name: item.name,
                    image: item.header_image,
                    meta: item.price_final_rub != null && item.price_final_rub > 0 ? money(item.price_final_rub) : tr('Цена уточняется', 'Price pending'),
                    badge: item.discount_percent ? `−${item.discount_percent}%` : tr('Новинка', 'New'),
                    onClick: () => runSearch(item.name, item.appid),
                  }))}
                /> : <p className="rail-placeholder">{tr('Витрина Steam обновляется — новинки появятся здесь автоматически.', 'The Steam showcase is refreshing—new releases will appear here automatically.')}</p>}
              </section>

            {(dealsLoading || deals.length > 0) && (
              <section className="section panel" data-reveal="home-deals">
                <h3>{tr('Скидки недели', 'Deals of the week')}</h3>
                {dealsLoading ? <RailSkeleton compact /> : <div className="sale-grid">
                  {deals.slice(0, 8).map((d) => (
                    <button key={d.appid} type="button" className="sale-card" onClick={() => runSearch(d.name, d.appid)}>
                      {d.header_image ? <img src={d.header_image} alt="" loading="lazy" onError={hideBrokenImg} /> : null}
                      <span className="sale-name">{d.name}</span>
                      <span className="sale-price">
                        {d.discount_percent != null && <b className="sale-discount">−{d.discount_percent}%</b>}
                        {d.price_initial_rub != null && <s>{money(d.price_initial_rub)}</s>}
                        <em>{money(d.price_final_rub)}</em>
                      </span>
                    </button>
                  ))}
                </div>}
              </section>
            )}
          </>
        )}

        {view === 'guide' && (
          <section className="section hero">
            <p className="eyebrow">{tr('Инструкция', 'Guide')}</p>
            <h2>{tr('Как пользоваться Игроскан', 'How to use Igroscan')}</h2>
            <p className="lead">
              {tr('За 30–60 секунд можно понять, покупать игру в Steam или сравнить ключи, гифты, аккаунты и аренду на маркетплейсах.', 'In 30–60 seconds, see whether to buy on Steam or compare keys, gifts, accounts, and rentals on marketplaces.')}
            </p>

            <h3 style={{ marginTop: '1.5rem' }}>{tr('Быстрый старт', 'Quick start')}</h3>
            <div className="steps" style={{ marginTop: '0.75rem' }}>
              <div className="step">
                <h4>{tr('1. Введите игру', '1. Enter a game')}</h4>
                <p>{tr('Используйте название из Steam и выберите нужную игру из подсказок.', 'Use the Steam title and select the correct game from the suggestions.')}</p>
              </div>
              <div className="step">
                <h4>{tr('2. Сравните цены', '2. Compare prices')}</h4>
                <p>
                  {tr('Сравните Steam, Plati и GGsel: минимальную, среднюю, популярную и самую дешёвую цену.', 'Compare Steam, Plati, and GGsel: minimum, average, popular, and cheapest prices.')}
                </p>
              </div>
              <div className="step">
                <h4>{tr('3. Сохраните интересное', '3. Save what matters')}</h4>
                <p>
                  {tr('Добавьте игру в избранное и задайте целевую цену. Кабинет покажет достигнутые цели.', 'Add a game to your watchlist and set a target price. The dashboard will show reached targets.')}
                </p>
              </div>
            </div>

            <h3 style={{ marginTop: '1.75rem' }}>{tr('Что означают типы товаров', 'Offer types')}</h3>
            <ul className="guide-list">
              <li><strong>{tr('Ключ', 'Key')}</strong> — {tr('код активации; проверьте регион и платформу.', 'an activation code; check its region and platform.')}</li>
              <li><strong>{tr('Гифт', 'Gift')}</strong> — {tr('подарок в Steam или другом магазине.', 'a gift delivered through Steam or another store.')}</li>
              <li><strong>{tr('Аккаунт', 'Account')}</strong> — {tr('доступ к игре на стороннем аккаунте; риски выше.', 'access to a game on a third-party account; higher risk.')}</li>
              <li><strong>{tr('Аренда', 'Rental')}</strong> — {tr('временный доступ, не полноценная покупка.', 'temporary access, not a full purchase.')}</li>
            </ul>

            <h3 style={{ marginTop: '1.5rem' }}>{tr('Ограничения и честные ожидания', 'Limitations')}</h3>
            <ul className="guide-list">
              <li>{tr('Цены ориентировочные: предложение может закончиться или подорожать.', 'Prices are indicative: an offer may sell out or change price.')}</li>
              <li>{tr('Некоторые игры недоступны в Steam RU — тогда сравниваются маркетплейсы.', 'Some games are unavailable on Steam RU; marketplaces are still compared.')}</li>
              <li>
                {tr('Поиск бесплатен. Цены обновляются примерно раз в 3 часа для каждой игры и источника.', 'Search is free. Each game and source refreshes about every 3 hours.')}
              </li>
              <li>{tr('Оплата проходит только на стороне Steam, Plati или GGsel.', 'Payment always takes place on Steam, Plati, or GGsel.')}</li>
            </ul>

            <h3 style={{ marginTop: '1.5rem' }}>{tr('Безопасность покупки', 'Buying safely')}</h3>
            <p className="lead">
              {tr('Перед оплатой проверьте описание, рейтинг продавца, регион активации и условия возврата.', 'Before paying, check the description, seller rating, activation region, and refund terms.')}
            </p>

            <div className="actions" style={{ marginTop: '1.25rem' }}>
              <button type="button" className="btn primary" onClick={() => setView('home')}>
                {tr('К поиску', 'Go to search')}
              </button>
              {!loggedIn && (
                <button type="button" className="btn ghost" onClick={() => { setAuthTab('register'); setAuthOpen(true) }}>
                  {tr('Создать аккаунт', 'Create account')}
                </button>
              )}
            </div>
          </section>
        )}

        {view === 'radar' && (
          <section className="section page-enter radar-page">
            <div className="hero">
              <p className="eyebrow">{tr('Уведомления', 'Notifications')}</p>
              <h2>{tr('Радар цен', 'Price radar')}</h2>
              <p className="lead">
                {tr('Бот', 'The bot')} <strong>@igroscan_bot</strong> {tr('пишет в Telegram, когда цена достигает цели на выбранной площадке.', 'sends a Telegram message when a price reaches your target on a selected marketplace.')}
              </p>
            </div>

            {!loggedIn ? (
              <div className="panel section">
                <h3>{tr('Сначала войди', 'Sign in first')}</h3>
                <p className="muted">{tr('Избранное и цели хранятся в аккаунте.', 'Your watchlist and targets are stored in your account.')}</p>
                <div className="actions" style={{ marginTop: '0.85rem' }}>
                  <button type="button" className="btn primary" onClick={() => { setAuthTab('login'); setAuthOpen(true) }}>
                    {tr('Войти', 'Sign in')}
                  </button>
                  <button type="button" className="btn ghost" onClick={() => { setAuthTab('register'); setAuthOpen(true) }}>
                    {tr('Регистрация', 'Create account')}
                  </button>
                </div>
              </div>
            ) : (
              <>
                <div className="radar-steps section">
                  <article className="panel radar-step">
                    <span className="radar-step-n">1</span>
                    <h3>{tr('Избранное + цель', 'Watchlist + target')}</h3>
                    <p className="muted">
                      {tr('Найди игру → добавь в избранное → выбери источники и целевую цену.', 'Find a game → add it to your watchlist → choose sources and a target price.')}
                    </p>
                    <button type="button" className="btn ghost sm" onClick={() => setView('home')}>
                      {tr('К поиску', 'Go to search')}
                    </button>
                  </article>
                  <article className="panel radar-step">
                    <span className="radar-step-n">2</span>
                    <h3>{tr('Привяжи Telegram', 'Connect Telegram')}</h3>
                    <p className="muted">
                      {tr('Подтверди Telegram и открой бота', 'Confirm Telegram and open the bot')} <strong>@igroscan_bot</strong>.
                    </p>
                  </article>
                  <article className="panel radar-step">
                    <span className="radar-step-n">3</span>
                    <h3>{tr('Жди уведомления', 'Wait for an alert')}</h3>
                    <p className="muted">
                      {tr('Сервер обновляет цены по расписанию и отправляет уведомление при достижении цели.', 'The server refreshes prices on schedule and notifies you when a target is reached.')}
                    </p>
                  </article>
                </div>

                <div className="panel section radar-panel">
                  <h3 style={{ marginTop: 0 }}>{tr('Статус', 'Status')}</h3>
                  <p className={tgStatus?.identity_linked ? 'radar-status ok' : 'radar-status warn'}>
                    {tgStatus?.identity_linked ? tr('Telegram-аккаунт подтверждён', 'Telegram account confirmed') : tr('Telegram-аккаунт ещё не подтверждён', 'Telegram account not confirmed yet')}
                  </p>
                  {!tgStatus?.identity_linked && tgStatus?.oidc_available && (
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
                      {tr('Подтвердить Telegram', 'Confirm Telegram')}
                    </button>
                  )}
                  {!tgStatus?.identity_linked && !tgStatus?.oidc_available && (
                    <p className="muted">{tr('Официальный вход Telegram временно настраивается.', 'Official Telegram sign-in is temporarily being configured.')}</p>
                  )}
                  {tgStatus?.linked ? (
                    <>
                      <p className="radar-status ok">
                        {tr('Telegram привязан', 'Telegram connected')}
                        {tgStatus.telegram_username ? ` (@${tgStatus.telegram_username})` : ''}
                      </p>
                      <p>
                        {tr('Уведомления:', 'Notifications:')}{' '}
                        <strong className={tgStatus.radar_enabled ? 'text-ok' : 'text-warn'}>
                          {tgStatus.radar_enabled ? tr('включены', 'on') : tr('выключены', 'off')}
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
                          {tgStatus.radar_enabled ? tr('Выключить уведомления', 'Disable notifications') : tr('Включить уведомления', 'Enable notifications')}
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
                                identity_linked: false,
                                oidc_available: tgStatus.oidc_available,
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
                          {tr('Отвязать бота', 'Disconnect bot')}
                        </button>
                        <a
                          className="btn ghost"
                          href="https://t.me/igroscan_bot"
                          target="_blank"
                          rel="noreferrer"
                        >
                          {tr('Открыть', 'Open')} @igroscan_bot
                        </a>
                      </div>
                    </>
                  ) : (
                    <>
                      <p className="radar-status warn">{tr('Telegram ещё не привязан', 'Telegram is not connected yet')}</p>
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
                          {tr('Привязать Telegram', 'Connect Telegram')}
                        </button>
                        <a className="btn ghost" href="https://t.me/igroscan_bot" target="_blank" rel="noreferrer">
                          {tr('Открыть бота', 'Open bot')}
                        </a>
                      </div>
                      {linkCode && (
                        <div className="link-code-box">
                          <p className="muted" style={{ margin: '0 0 0.35rem' }}>{tr('Твой код (20 минут):', 'Your code (20 minutes):')}</p>
                          <code className="link-code">{linkCode}</code>
                          <p className="muted" style={{ marginTop: '0.65rem' }}>
                            {tr('В боте:', 'In the bot:')} <code>/start {linkCode}</code>
                            {linkDeep && (
                              <>
                                {' · '}
                                <a href={linkDeep} target="_blank" rel="noreferrer">
                                  {tr('открыть ссылку', 'open link')}
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
                            {tr('Копировать', 'Copy')} /start {linkCode}
                          </button>
                          <button
                            type="button"
                            className="btn ghost sm"
                            style={{ marginTop: '0.5rem', marginLeft: '0.35rem' }}
                            onClick={() => loadTgStatus().then(() => setToast('Статус обновлён'))}
                          >
                            {tr('Я привязал — проверить', 'I connected it—check')}
                          </button>
                        </div>
                      )}
                    </>
                  )}
                </div>

                <div className="panel section">
                  <h3>{tr('Когда придёт сообщение', 'When an alert is sent')}</h3>
                  <ul className="guide-list">
                    <li>
                      <strong>{tr('Цель', 'Target')}</strong> — {tr('цена выбранного предложения стала не выше заданной.', 'the selected offer price reaches or falls below your target.')}
                    </li>
                    <li>
                      <strong>{tr('Источники', 'Sources')}</strong> — Steam, Plati, GGsel.
                    </li>
                    <li>{tr('Выпущенные игры обновляются примерно раз в 3 часа.', 'Released games refresh about every 3 hours.')}</li>
                    <li>{tr('Без привязки Telegram уведомлений не будет.', 'Telegram must be connected to receive notifications.')}</li>
                  </ul>
                  <button type="button" className="btn ghost" style={{ marginTop: '0.75rem' }} onClick={() => setView('cabinet')}>
                    {tr('К избранному в кабинете', 'Open dashboard watchlist')}
                  </button>
                </div>
              </>
            )}
          </section>
        )}

        {view === 'admin' && loggedIn && user?.can_access_admin && <AdminPanel currentUserId={user.id} />}

        {view === 'cabinet' && loggedIn && (
          <section className="section page-enter cabinet">
            <header className="cabinet-overview">
              <div className="cabinet-profile">
                <div className="cab-avatar" aria-hidden="true">
                  {(user?.display_name || user?.email || 'И').trim().charAt(0).toUpperCase()}
                </div>
                <div className="cabinet-id-text">
                  <p className="eyebrow">{tr('Личный кабинет', 'Dashboard')}</p>
                  <h1>{user?.display_name || user?.email}</h1>
                  <p className="cabinet-email">{user?.email}</p>
                  <div className="cabinet-badges">
                    {user?.can_access_admin && <span className="badge">{user.admin_role === 'owner' ? tr('владелец', 'owner') : tr('админ', 'admin')}</span>}
                    {!tgStatus?.linked
                      ? <span className="badge warn">{tr('Telegram не привязан', 'Telegram not linked')}</span>
                      : tgStatus?.identity_linked
                        ? <span className="badge ok">{tr('Telegram подключён', 'Telegram connected')}</span>
                        : <span className="badge warn">{tr('Telegram: подтвердить', 'Telegram: confirm')}</span>}
                  </div>
                </div>
                <div className="cabinet-actions">
                  <button type="button" className="btn ghost sm" onClick={() => setView('account')}>
                    <IconUser size={15} /> {tr('Настройки', 'Settings')}
                  </button>
                  {user?.can_access_admin && (
                    <button type="button" className="btn ghost sm" onClick={() => setView('admin')}>
                      Админка
                    </button>
                  )}
                </div>
              </div>
              {dashboard && (
                <div className="cabinet-stats stagger" aria-label="Статистика аккаунта">
                  <div className="cabinet-stat"><b>{dashboard.searches_total}</b><span>{tr('Всего поисков', 'Total searches')}</span></div>
                  <div className="cabinet-stat"><b>{dashboard.searches_this_week}</b><span>{tr('За 7 дней', 'Last 7 days')}</span></div>
                  <div className="cabinet-stat"><b>{dashboard.favorites_count}</b><span>{tr('В избранном', 'Watching')}</span></div>
                  <div className="cabinet-stat"><b>{dashboard.alerts_count}</b><span>{tr('Ценовых целей', 'Price targets')}</span></div>
                </div>
              )}
              {dashboard?.ctas?.length ? (
                <div className="cabinet-notes">
                  {dashboard.ctas.map((c) => <p key={c}>{c}</p>)}
                </div>
              ) : null}
            </header>

            <div className="cabinet-workspace">
              <main className="cabinet-main">
                <div className="panel cabinet-favorites">
                  <div className="panel-head cabinet-panel-head">
                    <div>
                      <p className="panel-kicker"><IconStar size={14} /> {tr('Библиотека', 'Library')}</p>
                      <h3>{tr('Избранное', 'Watchlist')}</h3>
                    </div>
                    <div className="cabinet-panel-tools">
                      <span className="cabinet-count">{watchlist.length}</span>
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
                        {tr('Обновить цены', 'Refresh prices')}
                      </button>
                    </div>
                  </div>
                  <div className="cabinet-scroll cabinet-scroll--favorites" tabIndex={0} aria-label={tr('Список избранных игр', 'Watchlist games')}>
                    <div className="list-cards cabinet-card-list">
                    {watchlist.map((f) => (
                      <article key={f.appid} className="list-card cabinet-game-card">
                        {f.header_image ? <img src={f.header_image} alt="" onError={hideBrokenImg} /> : <div className="ph" />}
                        <div className="cabinet-game-content">
                          <div className="cabinet-card-top">
                            <strong>{f.game_name}</strong>
                            {f.alert?.status === 'triggered' ? <span className="badge hot">{tr('сработал', 'triggered')}</span> : null}
                          </div>
                          <div className="cabinet-target-row">
                            <span>{tr('Целевая цена', 'Target price')}</span>
                            <b>{money(f.alert?.target_value)}</b>
                          </div>
                          {f.alert?.scopes?.length ? <span className="offer-meta">{f.alert.scopes.map((scope) => `${scope.source}/${scope.offer_kind}`).join(' · ')}</span> : null}
                          {f.release_status === 'announced' ? <span className="offer-meta">Ожидаем релиз в Steam — маркетплейсы пока не запрашиваются.</span> : null}
                          {f.freshness?.map((source) => <span className="offer-meta" key={source.source}>{source.source}: {source.status}{source.last_error ? ` · ${source.last_error}` : ''}</span>)}
                          <div className="actions cabinet-card-actions">
                            <button type="button" className="btn ghost sm" onClick={() => runSearch(f.game_name, f.appid)}>{tr('Цены', 'Prices')}</button>
                            <button type="button" className="btn ghost sm" onClick={() => setAlertModal({ favorite: f, create: false })}>{tr('Настроить', 'Configure')}</button>
                            <button
                              type="button"
                              className="btn ghost sm"
                              onClick={async () => {
                                await api(`/api/me/favorites/${f.appid}`, { method: 'DELETE' })
                                loadWatchlist().catch(() => {})
                                loadDashboard()
                                setToast('Убрано из избранного')
                              }}
                            >
                              {tr('Убрать', 'Remove')}
                            </button>
                          </div>
                        </div>
                      </article>
                    ))}
                    {!watchlist.length && <p className="cabinet-empty">{tr('Добавляй игры через ☆ на карточке Steam — они появятся здесь.', 'Use ☆ on a Steam card to add games here.')}</p>}
                    </div>
                  </div>
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
                  onRemove={async (alert) => {
                    await api(`/api/me/favorites/${alert.favorite.appid}/alert`, { method: 'DELETE' })
                    await loadWatchlist()
                    setToast('Алерт удалён')
                  }}
                />

                <div className="panel cabinet-history">
                  <div className="panel-head">
                    <div>
                      <p className="panel-kicker"><IconSearch size={14} /> {tr('Активность', 'Activity')}</p>
                      <h3>{tr('История поиска', 'Search history')}</h3>
                    </div>
                    <div className="cabinet-panel-tools">
                      <span className="cabinet-count">{dashboard?.recent_history?.length || 0}</span>
                      <button
                        type="button"
                        className="btn ghost sm"
                        onClick={async () => {
                          if (!confirm('Очистить историю?')) return
                          await api('/api/me/history', { method: 'DELETE' })
                          loadDashboard()
                        }}
                      >
                        {tr('Очистить', 'Clear')}
                      </button>
                    </div>
                  </div>
                  <div className="cabinet-scroll cabinet-scroll--history" tabIndex={0} aria-label={tr('История поиска', 'Search history')}>
                    <div className="list-cards cabinet-card-list">
                      {(dashboard?.recent_history || []).map((h) => (
                        <article key={h.id} className="list-card cabinet-history-card">
                          {h.header_image ? <img src={h.header_image} alt="" onError={hideBrokenImg} /> : <div className="ph" />}
                          <div className="cabinet-history-content">
                            <strong>{h.game_name || h.query}</strong>
                            <div className="cabinet-source-prices" aria-label="Цены по источникам">
                              <span className="steam"><small>Steam</small><b>{money(h.steam_price_rub)}</b></span>
                              <span className="plati"><small>Plati</small><b>{money(h.plati_min_rub)}</b></span>
                              <span className="ggsel"><small>GGsel</small><b>{money(h.ggsel_min_rub)}</b></span>
                            </div>
                          </div>
                          <button type="button" className="btn ghost sm cabinet-history-open" onClick={() => runSearch(h.query, h.appid)}>{tr('Открыть', 'Open')}</button>
                        </article>
                      ))}
                      {!dashboard?.recent_history?.length && <p className="cabinet-empty">{tr('Найди игру — последние запросы и цены появятся здесь.', 'Search for a game—recent queries and prices will appear here.')}</p>}
                    </div>
                  </div>
                </div>
              </main>

              <aside className="cabinet-rail">
                <div className={`panel cabinet-radar ${tgStatus?.linked ? 'is-online' : ''}`}>
                  <div className="cabinet-rail-icon" aria-hidden="true"><IconRadar size={20} /></div>
                  <div className="cabinet-rail-title">
                    <p className="panel-kicker">{tr('Уведомления', 'Notifications')}</p>
                    <h3>{tr('Радар цен', 'Price radar')}</h3>
                  </div>
                  <span className={`cabinet-status-dot ${tgStatus?.linked ? 'ok' : 'warn'}`} aria-hidden="true" />
                  <p className="muted">
                    {!tgStatus?.linked
                      ? tr('Подключи Telegram, чтобы получать сигналы о скидках.', 'Connect Telegram to receive discount alerts.')
                      : `Telegram${tgStatus.telegram_username ? ` @${tgStatus.telegram_username}` : ''} · ${tr('уведомления', 'notifications')} ${tgStatus.radar_enabled ? tr('включены', 'on') : tr('выключены', 'off')}`}
                  </p>
                  <button type="button" className="btn primary" onClick={() => setView('radar')}>
                    {tr('Настроить радар', 'Set up radar')}
                  </button>
                </div>

                <div className="panel cabinet-gift">
                  <div className="cabinet-rail-icon" aria-hidden="true"><IconGift size={20} /></div>
                  <div className="cabinet-rail-title">
                    <p className="panel-kicker">{tr('Дроп раз в 3 дня', 'Drop every 3 days')}</p>
                    <h3>{tr('Подарок', 'Gift')}</h3>
                  </div>
                  {gift && !giftReady ? (
                    <>
                      <div className="gift-drop"><span>{tr('Твой дроп', 'Your drop')}</span><strong>{gift.name}</strong></div>
                      <div className="actions">
                        {gift.appid ? <button type="button" className="btn ghost sm" onClick={() => runSearch(gift.name, gift.appid)}>{tr('Посмотреть цены', 'View prices')}</button> : null}
                      </div>
                      <p className="cabinet-fineprint">{tr('Следующий через', 'Next in')} {Math.ceil((GIFT_COOLDOWN_MS - (Date.now() - gift.received_at)) / (24 * 60 * 60 * 1000))} {tr('дн.', 'days')}</p>
                    </>
                  ) : (
                    <>
                      <p className="muted">{tr('Случайная игра для пользователей с подключённым Telegram.', 'A random game for users with connected Telegram.')}</p>
                      <button type="button" className="btn ghost" onClick={receiveGift}>
                        {tr('Получить подарок', 'Claim gift')}
                      </button>
                    </>
                  )}
                </div>

                {dashboard?.price_hits && dashboard.price_hits.length > 0 && (
                  <div className="panel cabinet-hits">
                    <div className="panel-head">
                      <div>
                        <p className="panel-kicker">{tr('Цена достигнута', 'Target reached')}</p>
                        <h3>{tr('На цели', 'On target')}</h3>
                      </div>
                      <span className="badge ok">{dashboard.price_hits.length}</span>
                    </div>
                    <div className="list-cards panel-list">
                      {dashboard.price_hits.map((f) => (
                        <article key={f.appid} className="list-card hit">
                          {f.header_image ? <img src={f.header_image} alt="" onError={hideBrokenImg} /> : <div className="ph" />}
                          <div>
                            <strong>{f.game_name}</strong>
                            <span className="offer-meta">Steam {money(f.last_steam_price_rub)} · цель {money(f.target_price_rub)}</span>
                            <button type="button" className="btn ghost sm" onClick={() => runSearch(f.game_name, f.appid)}>Цены</button>
                          </div>
                        </article>
                      ))}
                    </div>
                  </div>
                )}
              </aside>
            </div>
          </section>
        )}

        {view === 'favorites' && (
          loggedIn ? (
            <section className="section page-enter">
              <p className="eyebrow">{tr('Избранное', 'Watchlist')}</p>
              <h2>{tr('Игры под наблюдением', 'Watched games')}</h2>
              <p className="muted">{tr('Целевые цены и уведомления настраиваются отдельно для каждой игры.', 'Target prices and notifications are configured per game.')}</p>
              <div className="list-cards panel-list">
                {watchlist.map((f) => (
                  <article key={f.appid} className="list-card">
                    {f.header_image ? <img src={f.header_image} alt="" onError={hideBrokenImg} /> : <div className="ph" />}
                    <div>
                      <strong>{f.game_name} {f.alert?.status === 'triggered' ? <span className="badge hot">{tr('сработал', 'triggered')}</span> : null}</strong>
                      <span className="offer-meta">{tr('цель', 'target')} {money(f.alert?.target_value)} · {f.alert?.scopes?.map((scope) => `${scope.source}/${scope.offer_kind}`).join(' · ')}</span>
                      {f.release_status === 'announced' ? <span className="offer-meta">Ожидаем релиз в Steam — маркетплейсы пока не запрашиваются.</span> : null}
                      <div className="actions">
                        <button type="button" className="btn ghost sm" onClick={() => runSearch(f.game_name, f.appid)}>{tr('Цены', 'Prices')}</button>
                        <button type="button" className="btn ghost sm" onClick={() => setAlertModal({ favorite: f, create: false })}>{tr('Настроить', 'Configure')}</button>
                        <button
                          type="button"
                          className="btn ghost sm"
                          onClick={async () => {
                            await api(`/api/me/favorites/${f.appid}`, { method: 'DELETE' })
                            loadWatchlist().catch(() => {})
                            loadDashboard().catch(() => {})
                          }}
                        >
                          {tr('Убрать', 'Remove')}
                        </button>
                      </div>
                    </div>
                  </article>
                ))}
                {!watchlist.length && <p className="muted">{tr('Добавляй игры через ☆ на карточке Steam.', 'Use ☆ on a Steam card to add games here.')}</p>}
              </div>
            </section>
          ) : (
            <section className="section hero page-enter">
              <p className="eyebrow">{tr('Избранное', 'Watchlist')}</p>
              <h2>{tr('Войди, чтобы следить за ценами', 'Sign in to track prices')}</h2>
              <p className="lead">{tr('Избранное, целевые цены и Telegram-уведомления доступны после входа.', 'Watchlist, target prices, and Telegram notifications are available after sign-in.')}</p>
              <button type="button" className="btn primary" onClick={() => { setAuthTab('login'); setAuthOpen(true) }}>
                {tr('Войти', 'Sign in')}
              </button>
            </section>
          )
        )}

        {view === 'account' && (
          loggedIn ? (
            <section className="section page-enter">
              <p className="eyebrow">{tr('Аккаунт', 'Account')}</p>
              <h2>{tr('Настройки аккаунта', 'Account settings')}</h2>
              <div className="grid-2 section" style={{ alignItems: 'start' }}>
                <div className="panel">
                  <h3 style={{ marginTop: 0 }}>{tr('Аккаунт', 'Account')}</h3>
                  <div className="account-grid">
                    <div className="account-row">
                      <span>Email</span>
                      <strong>{user?.email}</strong>
                    </div>
                    <div className="account-row">
                      <span>{tr('Имя', 'Name')}</span>
                      <strong>{user?.display_name || '—'}</strong>
                    </div>
                    <div className="account-row">
                      <span>{tr('Дата регистрации', 'Joined')}</span>
                      <strong>{user?.created_at ? new Date(user.created_at).toLocaleDateString(locale === 'ru' ? 'ru-RU' : 'en-US') : '—'}</strong>
                    </div>
                    <div className="account-row">
                      <span>Telegram</span>
                      <strong>{user?.telegram_linked ? tr('привязан', 'connected') : tr('не привязан', 'not connected')}</strong>
                    </div>
                  </div>
                </div>
                <div className="panel">
                  <h3 style={{ marginTop: 0 }}>{tr('Смена пароля', 'Change password')}</h3>
                  <form className="auth-form" onSubmit={submitPassword}>
                    <label>
                      {tr('Текущий пароль', 'Current password')}
                      <input type="password" required autoComplete="current-password" value={pwdCurrent} onChange={(e) => setPwdCurrent(e.target.value)} />
                    </label>
                    <label>
                      {tr('Новый пароль', 'New password')}
                      <input type="password" required minLength={8} maxLength={72} autoComplete="new-password" value={pwdNew} onChange={(e) => setPwdNew(e.target.value)} />
                    </label>
                    <label>
                      {tr('Повторите новый пароль', 'Confirm new password')}
                      <input type="password" required minLength={8} maxLength={72} autoComplete="new-password" value={pwdConfirm} onChange={(e) => setPwdConfirm(e.target.value)} />
                    </label>
                    <button className="btn primary" type="submit" disabled={pwdSaving}>
                      {pwdSaving ? tr('Сохраняем…', 'Saving…') : tr('Обновить пароль', 'Update password')}
                    </button>
                  </form>
                  {pwdError && <p className="auth-error">{pwdError}</p>}
                </div>
              </div>
            </section>
          ) : (
            <section className="section hero page-enter">
              <p className="eyebrow">{tr('Аккаунт', 'Account')}</p>
              <h2>{tr('Настройки доступны после входа', 'Settings are available after sign-in')}</h2>
              <button type="button" className="btn primary" onClick={() => { setAuthTab('login'); setAuthOpen(true) }}>
                {tr('Войти', 'Sign in')}
              </button>
            </section>
          )
        )}
      </main>

      {alertModal && (
        <AlertSettingsModal
          favorite={alertModal.favorite}
          initialPrefs={(user as (User & { alert_prefs?: AlertPrefs | null }) | null)?.alert_prefs ?? null}
          onClose={() => setAlertModal(null)}
          onSavedPrefs={(p) => {
            if (user) {
              const updated = { ...user, alert_prefs: p }
              setUser(updated)
              setSession(getToken(), updated)
            }
          }}
          onSave={async ({ target_value, scopes }: { target_value: number | null; scopes: AlertScope[] }) => {
            const favorite = alertModal.favorite
            if (alertModal.create) {
              const body: Record<string, unknown> = {
                appid: favorite.appid,
                game_name: favorite.game_name,
                header_image: favorite.header_image,
              }
              if (target_value != null) body.alert = { target_value, scopes }
              await api('/api/me/favorites', { method: 'POST', body: JSON.stringify(body) })
              if (result) setResult({ ...result, is_favorite: true })
            } else {
              await api(`/api/me/favorites/${favorite.appid}`, { method: 'PATCH', body: JSON.stringify({ alert: { target_value, scopes } }) })
            }
            await loadWatchlist()
            loadDashboard().catch(() => {})
            setToast(tr('Настройки алерта сохранены', 'Alert settings saved'))
          }}
          onRemoveAlert={async () => {
            await api(`/api/me/favorites/${alertModal.favorite.appid}/alert`, { method: 'DELETE' })
            setAlertModal(null)
            loadWatchlist().catch(() => {})
            setToast(tr('Алерт удалён', 'Alert deleted'))
          }}
        />
      )}

      <footer className="shell footer has-tabbar">
        <p>
          {tr('Игроскан — агрегатор цен на игры. Мы не продаём ключи напрямую: покупка проходит на сторонней площадке. Перед оплатой проверьте продавца и условия.', 'Igroscan is a game price aggregator. We do not sell keys directly: purchases take place on third-party marketplaces. Check the seller and terms before paying.')}
        </p>
        <p className="footer-note">{tr('Цены берутся из открытых источников и могут отличаться от фактических.', 'Prices come from public sources and may differ from the final price.')}</p>
        <a className="donation-link" href={import.meta.env.VITE_DONATION_ALERTS_URL || 'https://www.donationalerts.com/r/igroscan'} target="_blank" rel="noreferrer">♡ {tr('Поддержать Игроскан', 'Support Igroscan')}</a>
      </footer>


      <AnimatePresence>
        {toast && (
          <motion.div className="toast" initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}>
            {toast}
          </motion.div>
        )}
      </AnimatePresence>
      <nav className="m-tabbar m-only" aria-label={tr('Основное меню', 'Main menu')}>
        <button type="button" className={view === 'home' ? 'active' : ''} onClick={() => setView('home')}>
          <span className="m-tab-ico" aria-hidden><IconSearch size={20} /></span>
          {tr('Поиск', 'Search')}
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
          <span className="m-tab-ico" aria-hidden><IconRadar size={20} /></span>
          {tr('Радар', 'Radar')}
        </button>
        <button
          type="button"
          className={view === 'favorites' ? 'active' : ''}
          onClick={() => {
            if (loggedIn) setView('favorites')
            else {
              setAuthTab('login')
              setAuthOpen(true)
            }
          }}
        >
          <span className="m-tab-ico" aria-hidden><IconStar size={20} /></span>
          {tr('Избранное', 'Watchlist')}
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
          <span className="m-tab-ico" aria-hidden><IconUser size={20} /></span>
          {loggedIn ? tr('Кабинет', 'Dashboard') : tr('Вход', 'Sign in')}
        </button>
      </nav>

      <AnimatePresence>
        {authOpen && (
          <motion.div className="modal-backdrop" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => setAuthOpen(false)}>
            <motion.div className="modal" initial={{ y: 40, opacity: 0 }} animate={{ y: 0, opacity: 1 }} exit={{ y: 20, opacity: 0 }} onClick={(e) => e.stopPropagation()}>
              <div className="modal-head">
                <h2>{authTab === 'login' ? tr('Вход в Игроскан', 'Sign in to Igroscan') : tr('Регистрация', 'Create account')}</h2>
                <button type="button" className="modal-close" onClick={() => setAuthOpen(false)} aria-label={tr('Закрыть', 'Close')}><IconClose size={18} /></button>
              </div>
              <div className="tabs">
                <button type="button" className={`tab ${authTab === 'login' ? 'active' : ''}`} onClick={() => setAuthTab('login')}>{tr('Вход', 'Sign in')}</button>
                <button type="button" className={`tab ${authTab === 'register' ? 'active' : ''}`} onClick={() => setAuthTab('register')}>{tr('Регистрация', 'Create account')}</button>
              </div>
              <form className="auth-form" onSubmit={onAuth}>
                {authTab === 'register' && (
                  <label>
                    {tr('Имя', 'Name')}
                    <input name="display_name" maxLength={80} placeholder={tr('Как к вам обращаться', 'How should we address you?')} />
                  </label>
                )}
                <label>
                  Email
                  <input name="email" type="email" required autoComplete="email" />
                </label>
                <label>
                  {tr('Пароль', 'Password')}
                  <input name="password" type="password" required minLength={8} maxLength={72} autoComplete={authTab === 'login' ? 'current-password' : 'new-password'} />
                </label>
                <button className="btn primary" type="submit" style={{ width: '100%' }}>
                  {authTab === 'login' ? tr('Войти', 'Sign in') : tr('Создать аккаунт', 'Create account')}
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

function LanguageCurrencyControls({ compact = false }: { compact?: boolean }) {
  const { locale, setLocale, currency, setCurrency, tr } = useLocale()

  return (
    <div className={`region-controls ${compact ? 'compact' : ''}`} aria-label={tr('Язык и валюта', 'Language and currency')}>
      <label>
        <span className="sr-only">{tr('Язык', 'Language')}</span>
        <select value={locale} onChange={(event) => setLocale(event.target.value as 'ru' | 'en')} aria-label={tr('Язык сайта', 'Site language')}>
          <option value="ru">RU</option>
          <option value="en">EN</option>
        </select>
      </label>
      <span className="region-controls-separator" aria-hidden="true" />
      <label>
        <span className="sr-only">{tr('Валюта', 'Currency')}</span>
        <select value={currency} onChange={(event) => setCurrency(event.target.value as 'RUB' | 'USD' | 'EUR' | 'KZT' | 'TRY')} aria-label={tr('Валюта цен', 'Price currency')}>
          {(['RUB', 'USD', 'EUR', 'KZT', 'TRY'] as const).map((item) => (
            <option key={item} value={item}>{item}</option>
          ))}
        </select>
      </label>
    </div>
  )
}

function RailSkeleton({ compact = false }: { compact?: boolean }) {
  return (
    <div className={`rail-skeleton ${compact ? 'compact' : ''}`} aria-label="Загрузка" aria-busy="true">
      {Array.from({ length: 4 }, (_, index) => <span key={index}><i /><b /><em /></span>)}
    </div>
  )
}

function BootSplash({ ready }: { ready: boolean }) {
  const { tr } = useLocale()
  const startedAt = useRef(Date.now())
  const [assetsReady, setAssetsReady] = useState(false)
  const [closing, setClosing] = useState(false)
  const [visible, setVisible] = useState(true)

  useEffect(() => {
    let active = true
    const finish = () => {
      const fonts = document.fonts?.ready ?? Promise.resolve()
      void fonts.finally(() => { if (active) setAssetsReady(true) })
    }
    if (document.readyState === 'complete') finish()
    else window.addEventListener('load', finish, { once: true })
    return () => {
      active = false
      window.removeEventListener('load', finish)
    }
  }, [])

  useEffect(() => {
    const minimumDelay = Math.max(0, 520 - (Date.now() - startedAt.current))
    const timer = window.setTimeout(() => setClosing(true), ready && assetsReady ? minimumDelay : 2400)
    return () => window.clearTimeout(timer)
  }, [assetsReady, ready])

  useEffect(() => {
    if (!closing) return
    const timer = window.setTimeout(() => setVisible(false), 520)
    return () => window.clearTimeout(timer)
  }, [closing])

  if (!visible) return null
  return (
    <div className={`boot-splash ${closing ? 'is-closing' : ''}`} role="status" aria-live="polite">
      <div className="boot-console">
        <BrandMark size={44} />
        <div className="boot-wordmark">ИГРОСКАН</div>
        <div className="boot-sources" aria-hidden="true"><i /><i /><i /><b /></div>
        <p>{tr('Собираем витрины и цены', 'Preparing stores and prices')}</p>
        <span className="boot-progress" aria-hidden="true"><i /></span>
      </div>
    </div>
  )
}

function MarketplaceBrowser({ markets, active, onChange, onTrack, steamPrice }: {
  markets: Market[]
  active: string
  onChange: (marketplace: string) => void
  onTrack: (marketplace: string, url: string, price?: number) => void
  steamPrice?: number | null
}) {
  const { formatPrice: money, tr } = useLocale()
  const selected = markets.find((market) => market.marketplace === active) ?? markets[0]
  return (
    <section className="panel marketplace-browser">
      <div className="marketplace-browser-head">
        <div><p className="eyebrow">MARKET SCAN</p><h3>{tr('Предложения площадок', 'Marketplace offers')}</h3></div>
        <span>{tr('Выберите источник', 'Choose a source')}</span>
      </div>
      <div className="market-source-tabs" role="tablist" aria-label={tr('Площадки', 'Marketplaces')}>
        {markets.map((market) => {
          const minimum = Math.min(...market.by_kind.map((kind) => kind.min_price ?? Infinity))
          return (
            <button
              key={market.marketplace}
              type="button"
              role="tab"
              aria-selected={selected.marketplace === market.marketplace}
              className={`market-source-tab ${market.marketplace} ${selected.marketplace === market.marketplace ? 'active' : ''}`}
              onClick={() => onChange(market.marketplace)}
            >
              <span><i />{market.label}</span>
              <small>{Number.isFinite(minimum) ? `${tr('от', 'from')} ${money(minimum)}` : tr('нет цен', 'no prices')}</small>
              <b>{market.total_offers}</b>
            </button>
          )
        })}
      </div>
      <MarketCard market={selected} steamPrice={steamPrice} onTrack={onTrack} />
    </section>
  )
}

function MarketCard({ market, onTrack, steamPrice }: { market: Market; onTrack: (marketplace: string, url: string, price?: number) => void; steamPrice?: number | null }) {
  const { currency, currencyReady, formatPrice: money, tr } = useLocale()
  if (market.error) {
    return (
      <article className={`market market-body ${market.marketplace}`}>
        <div className="market-head">
          <h3 style={{ margin: 0 }}>{market.label}</h3>
        </div>
        <p className="status error" style={{ marginTop: 0.4 }}>{market.error}</p>
      </article>
    )
  }
  const visibleKinds = market.by_kind.filter((k) => k.popular || k.cheapest || k.count > 0)
  const marketMinimum = Math.min(...market.by_kind.map((kind) => kind.min_price ?? Infinity))
  if (visibleKinds.length === 0 && market.total_offers === 0) {
    return (
      <article className={`market market-body ${market.marketplace} market-empty`}>
        <div className="market-head">
          <h3 style={{ margin: 0 }}>{market.label}</h3>
          <span className="badge">{tr('0 офферов', '0 offers')}</span>
        </div>
        <p className="market-empty-note">
          {tr('Предложений по этому запросу пока нет на', 'No offers were found for this query on')} {market.label}. {tr('Таблица появится автоматически после обновления данных.', 'The table will appear automatically after the data refreshes.')}
        </p>
      </article>
    )
  }
  return (
    <article className={`market market-body ${market.marketplace}`}>
      <div className="market-head">
        <h3>{market.label}</h3>
        <span className="market-scan-badge">{tr('проверено', 'scanned')} {market.scanned_offers}</span>
      </div>
      <div className="market-table-wrap">
        <table className="market-table">
          <thead><tr><th>{tr('Тип', 'Type')}</th><th>{tr('Минимум', 'Minimum')}</th><th>{tr('Средняя', 'Average')}</th><th>{tr('Популярный', 'Popular')}</th><th>{tr('Дешёвый лот', 'Cheapest offer')}</th></tr></thead>
          <tbody>
        {visibleKinds.map((k) => {
            const popular = k.popular
            const cheapest = k.cheapest
            return (
            <tr key={k.kind}>
              <td>
                <strong>{({ official: tr('Официальная версия', 'Official'), key: tr('Ключ', 'Key'), gift: tr('Гифт', 'Gift'), account: tr('Аккаунт', 'Account'), rent: tr('Аренда', 'Rental') } as Record<string, string>)[k.kind] || k.label}</strong>
                <span className="offer-meta">{k.count} {tr('шт.', 'items')}</span>
              </td>
              <td className="market-price-min" data-label={tr('Минимум', 'Minimum')}>{money(k.min_price)}</td>
              <td data-label={tr('Средняя', 'Average')}>{money(k.avg_price)}</td>
              <td data-label={tr('Популярный', 'Popular')}>
                {popular ? (
                  <a
                      className="offer-link"
                      href={popular.url}
                      target="_blank"
                      rel="noreferrer sponsored"
                      onClick={() => onTrack(market.marketplace, popular.url, popular.price_rub)}
                    >
                      {money(popular.price_rub)}
                      <small>{popular.sales || 0} {tr('продаж', 'sales')}</small>
                    </a>
                ) : (
                  '—'
                )}
              </td>
              <td data-label={tr('Дешёвый лот', 'Cheapest offer')}>
                {cheapest ? (
                  <a
                    className="offer-link"
                    href={cheapest.url}
                    target="_blank"
                    rel="noreferrer sponsored"
                    onClick={() => onTrack(market.marketplace, cheapest.url, cheapest.price_rub)}
                  >
                    {money(cheapest.price_rub)}
                  </a>
                ) : (
                  '—'
                )}
              </td>
            </tr>
            )
          })}
          </tbody>
        </table>
      </div>
      {steamPrice != null && Number.isFinite(marketMinimum) && (
        <p className="muted" style={{ marginTop: 8 }}>
          {tr('Минимум на площадке:', 'Marketplace minimum:')} {money(marketMinimum)}
        </p>
      )}
      {currency !== 'RUB' && <p className="market-rate-note">{currencyReady
        ? tr(`Пересчитано из ₽ по курсу ЦБ в ${currency}`, `Converted from RUB using the CBR rate into ${currency}`)
        : tr('Загружаем курс — цены появятся автоматически', 'Loading the exchange rate—prices will appear automatically')}</p>}
    </article>
  )
}
