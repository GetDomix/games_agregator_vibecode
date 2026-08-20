import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import type { User } from '../shared/api/client'
import { LocaleProvider } from '../shared/i18n/LocaleProvider'

const owner: User = {
  id: 1,
  email: 'kirill@mail.ru',
  display_name: 'Кирилл',
  admin_role: 'owner',
  can_access_admin: true,
  can_manage_admin_team: true,
}

const regularUser: User = {
  ...owner,
  id: 2,
  email: 'user@example.com',
  display_name: 'Пользователь',
  admin_role: 'user',
  can_access_admin: false,
  can_manage_admin_team: false,
}

const overview = {
  generated_at: '2026-08-10T12:00:00+03:00',
  stats: {
    users_total: 1,
    users_7d: 1,
    games_total: 0,
    searches_24h: 0,
    partner_clicks_7d: 0,
    alert_events_24h: 0,
  },
  operations: {
    queue: { pending: 0, failed: 0 },
    sources: [],
    deliveries_24h: { pending: 0, sent: 0, failed: 0, skipped: 0 },
  },
  recent_source_failures: [],
  popular_searches_7d: [],
  problem_searches: [],
  recent_audit: [],
}

const dashboard = {
  recent_history: [],
  favorites_preview: [],
  favorites_count: 1,
  searches_total: 2,
  searches_this_week: 1,
  alerts_count: 1,
  price_hits: [{
    id: 10,
    appid: 777,
    game_name: 'Shared Game',
    target_price_rub: 500,
    hit_price_rub: 450,
    hit_source: 'plati',
    hit_offer_kind: 'gift',
  }],
  ctas: [],
}

function json(data: unknown) {
  return new Response(JSON.stringify(data), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

function renderApp(user: User, responseFor?: (path: string) => Response | Promise<Response> | undefined) {
  localStorage.setItem('gpa_token', 'test-token')
  localStorage.setItem('gpa_user', JSON.stringify(user))
  localStorage.setItem('igroscan_locale_v1', 'ru')
  localStorage.setItem('igroscan_currency_v1', 'RUB')
  localStorage.setItem('igroscan_currency_rates_v1', JSON.stringify({ RUB: 1 }))

  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const path = String(input)
    const response = responseFor?.(path)
    if (response) return await response
    if (path === '/api/auth/me') return json(user)
    if (path === '/api/currencies') return json({ rates: { RUB: 1 } })
    if (path === '/api/admin/overview') return json(overview)
    if (path === '/api/me/dashboard') return json(dashboard)
    return json({ items: [] })
  }))

  return render(<LocaleProvider><App /></LocaleProvider>)
}

describe('profile admin navigation', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/')
    vi.stubGlobal('matchMedia', vi.fn(() => ({
      matches: true,
      media: '',
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })))
  })

  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('gives the main game search an accessible name', () => {
    renderApp(regularUser)

    expect(screen.getByRole('search', { name: 'Поиск цены игры' })).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Название игры' })).toBeInTheDocument()
  })

  it('shows appid recents on empty focus without a search request', async () => {
    const user = userEvent.setup()
    localStorage.setItem('gpa_recent_v1', JSON.stringify([{ q: 'Hades', appid: 1145360, at: 1 }]))
    const rendered = renderApp(regularUser)
    await user.click(screen.getByRole('textbox', { name: 'Название игры' }))
    expect(screen.getAllByRole('button', { name: /Hades/ }).length).toBeGreaterThan(1)
    expect((fetch as ReturnType<typeof vi.fn>).mock.calls.some(([url]) => String(url).includes('/api/search'))).toBe(false)
    rendered.unmount()
  })

  it('clears old suggestions as soon as the search text changes', async () => {
    const user = userEvent.setup()
    let resolveSecondSearch: ((response: Response) => void) | undefined
    const secondSearch = new Promise<Response>((resolve) => { resolveSecondSearch = resolve })
    renderApp(regularUser, (path) => {
      if (path === '/api/search?q=outpost&discover=1') return json({ candidates: [{ appid: 1, name: 'Outpost Old', candidate_kind: 'game' }] })
      if (path === '/api/search?q=out&discover=1') return secondSearch
      return undefined
    })

    const input = screen.getByRole('textbox', { name: 'Название игры' })
    await user.type(input, 'outpost')
    expect(await screen.findByRole('button', { name: /Outpost Old/ })).toBeInTheDocument()

    await user.keyboard('{Backspace}{Backspace}{Backspace}{Backspace}')
    expect(screen.queryByRole('button', { name: /Outpost Old/ })).not.toBeInTheDocument()

    resolveSecondSearch?.(json({ candidates: [{ appid: 2, name: 'Out There', candidate_kind: 'game' }] }))
    expect(await screen.findByRole('button', { name: /Out There/ })).toBeInTheDocument()
  })

  it('renders every returned suggestion in a bounded scroll list', async () => {
    const user = userEvent.setup()
    const candidates = Array.from({ length: 12 }, (_, index) => ({
      appid: 100 + index,
      name: `Scrollable Game ${index + 1}`,
      candidate_kind: 'game',
    }))
    renderApp(regularUser, (path) => path.includes('/api/search?q=scroll') ? json({ candidates }) : undefined)

    await user.type(screen.getByRole('textbox', { name: 'Название игры' }), 'scroll')
    await screen.findByRole('button', { name: /Scrollable Game 12/ })
    const list = screen.getByRole('list', { name: 'Подходящие игры' })
    expect(within(list).getAllByRole('button')).toHaveLength(12)
    expect(within(list).getByRole('button', { name: /Scrollable Game 12/ })).toBeInTheDocument()
  })

  it('submits an exact autocomplete title with its appid', async () => {
    const user = userEvent.setup()
    const priceRequests: string[] = []
    renderApp(regularUser, (path) => {
      if (path.includes('/api/search?q=Cyberpunk%202077')) return json({ candidates: [
        { appid: 1091500, name: 'Cyberpunk 2077', candidate_kind: 'game', available_in_ru: false },
        { appid: 2138330, name: 'Cyberpunk 2077: Phantom Liberty', candidate_kind: 'game', available_in_ru: false },
      ] })
      if (path.startsWith('/api/prices?')) {
        priceRequests.push(path)
        return json({
          query: 'Cyberpunk 2077',
          steam: { appid: 1091500, name: 'Cyberpunk 2077', price_rub: null, available_in_ru: false, regional_prices: [] },
          candidates: [], plati: { by_kind: [] }, ggsel: { by_kind: [] }, warnings: [], refreshing: false,
        })
      }
      return undefined
    })

    await user.type(screen.getByRole('textbox', { name: 'Название игры' }), 'Cyberpunk 2077')
    await screen.findByRole('button', { name: /^Cyberpunk 2077Игра/ })
    await user.click(screen.getByRole('button', { name: 'Сравнить' }))

    await waitFor(() => expect(priceRequests).toHaveLength(1))
    expect(priceRequests[0]).toContain('appid=1091500')
  })

  it('refreshes the visible suggestion price from the selected game result', async () => {
    const user = userEvent.setup()
    renderApp(regularUser, (path) => {
      if (path.startsWith('/api/games/1972320/price-history')) return json({
        period_days: 90, available_periods: [90], current: { price_rub: 132, source: 'steam', offer_kind: 'official' },
        statistics: { minimum_price_rub: 132, median_price_rub: 132 },
        coverage: { observations: 1, checks: 1, observed_days: 1, sufficient: false }, verdict: 'insufficient', changes: [],
      })
      if (path.includes('/api/search?q=outpost4')) return json({
        candidates: [{ appid: 1972320, name: 'outpost4', candidate_kind: 'game', price_rub: null, available_in_ru: false }],
      })
      if (path.startsWith('/api/prices?')) return json({
        query: 'outpost4',
        steam: { appid: 1972320, name: 'outpost4', price_rub: 132, available_in_ru: true, regional_prices: [] },
        candidates: [], plati: { by_kind: [] }, ggsel: { by_kind: [] }, warnings: [], refreshing: false,
      })
      return undefined
    })

    const input = screen.getByRole('textbox', { name: 'Название игры' })
    await user.type(input, 'outpost4')
    await user.click(await screen.findByRole('button', { name: /outpost4.*Недоступно в регионе RU/ }))
    expect(await screen.findByRole('heading', { name: 'outpost4' })).toBeInTheDocument()

    await user.click(input)
    expect(await screen.findByRole('button', { name: /outpost4.*132/ })).toBeInTheDocument()
  })

  it('moves through search suggestions with arrows and closes them with Escape', async () => {
    const user = userEvent.setup()
    renderApp(regularUser, (path) => path.includes('/api/search?q=arrow') ? json({ candidates: [
      { appid: 31, name: 'Arrow One', candidate_kind: 'game' },
      { appid: 32, name: 'Arrow Two', candidate_kind: 'game' },
    ] }) : undefined)

    const input = screen.getByRole('textbox', { name: 'Название игры' })
    await user.type(input, 'arrow')
    const first = await screen.findByRole('button', { name: /Arrow One/ })
    const second = screen.getByRole('button', { name: /Arrow Two/ })

    await user.keyboard('{ArrowDown}')
    expect(first).toHaveFocus()
    await user.keyboard('{ArrowDown}')
    expect(second).toHaveFocus()
    await user.keyboard('{ArrowUp}')
    expect(first).toHaveFocus()
    await user.keyboard('{Escape}')
    expect(input).toHaveFocus()
    expect(screen.queryByRole('list', { name: 'Подходящие игры' })).not.toBeInTheDocument()
  })

  it('opens the notification ledger from the control beside the profile', async () => {
    const user = userEvent.setup()
    renderApp(regularUser, (path) => path === '/api/me/favorites' ? json({ items: [{ appid: 8, game_name: 'Plain game', alert: null }] }) : undefined)

    const profileButton = screen.getByRole('button', { name: /Пользователь/ })
    const profileCluster = profileButton.closest('.profile-cluster') as HTMLElement
    const notificationButton = within(profileCluster).getByRole('button', { name: 'Уведомления' })

    expect(notificationButton).toBeInTheDocument()
    await user.click(notificationButton)
    expect(screen.getByRole('heading', { name: 'Уведомления' })).toBeInTheDocument()
    expect(notificationButton).toHaveAttribute('aria-expanded', 'true')
    expect(screen.queryByText('Ценовых сигналов')).not.toBeInTheDocument()
  })

  it('guides notification settings to the favorites library instead of Telegram', async () => {
    const user = userEvent.setup()
    Element.prototype.scrollIntoView = vi.fn()
    renderApp(regularUser, (path) => path === '/api/me/favorites' ? json({ items: [{ appid: 8, game_name: 'Plain game', alert: null }] }) : undefined)

    const profileButton = screen.getByRole('button', { name: /Пользователь/ })
    const profileCluster = profileButton.closest('.profile-cluster') as HTMLElement
    await user.click(within(profileCluster).getByRole('button', { name: 'Уведомления' }))
    await user.click(screen.getByRole('button', { name: 'Настроить уведомления' }))

    expect(await screen.findByRole('heading', { name: 'Избранное' })).toBeInTheDocument()
    expect(await screen.findByText('Добавляйте игры через поиск, а уведомления настраивайте здесь.')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Радар цен' })).not.toBeInTheDocument()

    await user.click(profileButton)
    await user.click(within(profileButton.closest('.profile-wrap') as HTMLElement).getByRole('menuitem', { name: 'Настройки' }))
    await waitFor(() => expect(screen.queryByText('Добавляйте игры через поиск, а уведомления настраивайте здесь.')).not.toBeInTheDocument())

    await user.click(profileButton)
    await user.click(within(profileButton.closest('.profile-wrap') as HTMLElement).getByRole('menuitem', { name: 'Кабинет' }))
    expect(await screen.findByRole('heading', { name: 'Избранное' })).toBeInTheDocument()
    expect(screen.queryByText('Добавляйте игры через поиск, а уведомления настраивайте здесь.')).not.toBeInTheDocument()
  })

  it('shows favorite confirmation in the notification area', async () => {
    const user = userEvent.setup()
    renderApp(regularUser, (path) => {
      if (path.startsWith('/api/games/620/price-history')) return json({
        period_days: 90, available_periods: [90], current: { price_rub: 99, source: 'steam', offer_kind: 'official' },
        statistics: { minimum_price_rub: 99, median_price_rub: 99 },
        coverage: { observations: 1, checks: 1, observed_days: 1, sufficient: false }, verdict: 'insufficient', changes: [],
      })
      if (path.startsWith('/api/prices?')) return json({
        query: 'Portal 2',
        steam: { appid: 620, name: 'Portal 2', price_rub: 99, regional_prices: [] },
        candidates: [], plati: { by_kind: [] }, ggsel: { by_kind: [] }, warnings: [], refreshing: false, is_favorite: false,
      })
      return undefined
    })

    await user.type(screen.getByRole('textbox', { name: 'Название игры' }), 'Portal 2')
    await user.click(screen.getByRole('button', { name: 'Сравнить' }))
    await user.click(await screen.findByRole('button', { name: '☆ В избранное' }))

    const feedback = await screen.findByRole('status', { name: '' })
    expect(feedback).toHaveTextContent('Добавлено в избранное')
    expect(feedback).toHaveClass('notification-area-feedback')
  })

  it('does not guide the favorites library on a regular cabinet visit', async () => {
    const user = userEvent.setup()
    renderApp(regularUser)

    const profileButton = screen.getByRole('button', { name: /Пользователь/ })
    await user.click(profileButton)
    await user.click(within(profileButton.closest('.profile-wrap') as HTMLElement).getByRole('menuitem', { name: 'Кабинет' }))

    const library = (await screen.findByRole('heading', { name: 'Избранное' })).closest('#cabinet-favorites-library')
    expect(library).not.toHaveClass('is-guided')
    expect(screen.queryByText('Добавляйте игры через поиск, а уведомления настраивайте здесь.')).not.toBeInTheDocument()
  })

  it('shows a converted USD price in rubles when Steam RU is unavailable', async () => {
    const user = userEvent.setup()
    renderApp(regularUser, (path) => path.startsWith('/api/prices?') ? json({
      query: 'Unavailable Game',
      steam: {
        appid: 404,
        name: 'Unavailable Game',
        store_url: 'https://store.steampowered.com/app/404/',
        price_rub: null,
        available_in_ru: false,
        is_free: false,
        regional_prices: [
          { region: 'US', label: 'США', currency: 'USD', amount: 59.99, price_rub: 4955.53 },
        ],
      },
      candidates: [],
      plati: { marketplace: 'plati', label: 'Plati.Market', total_offers: 0, scanned_offers: 0, by_kind: [] },
      ggsel: { marketplace: 'ggsel', label: 'GGsel', total_offers: 0, scanned_offers: 0, by_kind: [] },
      warnings: [],
      refreshing: false,
    }) : undefined)

    await user.type(screen.getByRole('textbox', { name: 'Название игры' }), 'Unavailable Game')
    await user.click(screen.getByRole('button', { name: 'Сравнить' }))

    expect(await screen.findByText('Недоступно в регионе RU')).toBeInTheDocument()
    expect(screen.getByText(/4\s956\s₽/)).toBeInTheDocument()
    expect(screen.getByText('Цена Steam США · пересчёт из $ в ₽')).toBeInTheDocument()
  })

  it('hides alert configuration in MVP', async () => {
    renderApp(regularUser, (path) => path === '/api/me/favorites' ? json({ items: [{
      id: 8,
      appid: 8,
      game_name: 'Suggested game',
      alert: null,
      suggested_target: {
        value_rub: 900,
        reference_price_rub: 1000,
        reduction_percent: 10,
        source: 'steam',
        offer_kind: 'official',
        basis: 'current_price_minus_10_percent',
      },
    }] }) : undefined)

    expect(screen.queryByRole('button', { name: 'Настроить' })).not.toBeInTheDocument()
    expect(screen.queryByText(/10% ниже текущей сохранённой цены: 900 RUB/)).not.toBeInTheDocument()
  })

  it('requires an explicit candidate click before an ambiguous price response uses an appid', async () => {
    const user = userEvent.setup()
    const priceRequests: string[] = []
    const rendered = renderApp(regularUser, (path) => {
      if (path.startsWith('/api/games/222/price-history')) return json({
        period_days: 90, available_periods: [90], current: { price_rub: 500, source: 'steam', offer_kind: 'official' },
        statistics: { minimum_price_rub: 500, median_price_rub: 500 },
        coverage: { observations: 1, checks: 1, observed_days: 1, sufficient: false }, verdict: 'insufficient', changes: [],
      })
      if (!path.startsWith('/api/prices?')) return undefined
      priceRequests.push(path)
      if (!path.includes('appid=')) return json({
        query: 'Control', steam: null,
        candidates: [
          { appid: 111, name: 'Control', candidate_kind: 'game' },
          { appid: 222, name: 'Control Ultimate Edition', candidate_kind: 'edition' },
        ],
        plati: { by_kind: [] }, ggsel: { by_kind: [] }, warnings: [], refreshing: false,
      })
      return json({
        query: 'Control Ultimate Edition',
        steam: { appid: 222, name: 'Control Ultimate Edition', price_rub: 500, regional_prices: [] },
        candidates: [], plati: { by_kind: [] }, ggsel: { by_kind: [] }, warnings: [], refreshing: false,
      })
    })

    await user.type(screen.getByRole('textbox', { name: 'Название игры' }), 'Control')
    await user.click(screen.getByRole('button', { name: 'Сравнить' }))
    await screen.findByRole('button', { name: /Control Ultimate Edition.*Издание/ })
    expect(priceRequests).toHaveLength(1)
    expect(priceRequests[0]).not.toContain('appid=')

    await user.click(screen.getByRole('button', { name: /Control Ultimate Edition.*Издание/ }))
    await waitFor(() => expect(priceRequests.some((path) => path.includes('appid=222'))).toBe(true))
    expect(priceRequests.filter((path) => path.includes('appid=')).length).toBe(1)
    rendered.unmount()
  })

  it('moves admin navigation into the owner profile menu and keeps it in the cabinet', async () => {
    const user = userEvent.setup()
    renderApp(owner)

    expect(screen.queryByRole('button', { name: 'Admin' })).not.toBeInTheDocument()

    const profileButton = screen.getByRole('button', { name: /Кирилл/ })
    await user.click(profileButton)
    const profileMenu = profileButton.closest('.profile-wrap')
    expect(profileMenu).not.toBeNull()
    await user.click(within(profileMenu as HTMLElement).getByRole('menuitem', { name: 'Админка' }))

    expect(screen.getByRole('heading', { name: 'Пульт Игроскана' })).toBeInTheDocument()

    await user.click(profileButton)
    await user.click(within(profileMenu as HTMLElement).getByRole('menuitem', { name: 'Кабинет' }))
    expect(screen.getByRole('button', { name: 'Админка' })).toBeInTheDocument()
  })

  it('does not show admin navigation to a user without permission', async () => {
    const user = userEvent.setup()
    renderApp(regularUser)

    const profileButton = screen.getByRole('button', { name: /Пользователь/ })
    await user.click(profileButton)
    const profileMenu = profileButton.closest('.profile-wrap')

    expect(within(profileMenu as HTMLElement).queryByRole('menuitem', { name: 'Админка' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Admin' })).not.toBeInTheDocument()
  })

  it('hides triggered price signals in MVP', async () => {
    const user = userEvent.setup()
    renderApp(regularUser)

    const profileButton = screen.getByRole('button', { name: /Пользователь/ })
    await user.click(profileButton)
    await user.click(within(profileButton.closest('.profile-wrap') as HTMLElement).getByRole('menuitem', { name: 'Кабинет' }))

    expect(screen.queryByText(/Plati: гифт/)).not.toBeInTheDocument()
    expect(screen.queryByText('Ценовых сигналов')).not.toBeInTheDocument()
  })
})
