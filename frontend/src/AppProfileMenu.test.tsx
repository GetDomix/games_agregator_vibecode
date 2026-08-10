import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import type { User } from './api'
import { LocaleProvider } from './locale'

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

function json(data: unknown) {
  return new Response(JSON.stringify(data), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

function renderApp(user: User) {
  localStorage.setItem('gpa_token', 'test-token')
  localStorage.setItem('gpa_user', JSON.stringify(user))
  localStorage.setItem('igroscan_locale_v1', 'ru')
  localStorage.setItem('igroscan_currency_v1', 'RUB')
  localStorage.setItem('igroscan_currency_rates_v1', JSON.stringify({ RUB: 1 }))

  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const path = String(input)
    if (path === '/api/auth/me') return json(user)
    if (path === '/api/currencies') return json({ rates: { RUB: 1 } })
    if (path === '/api/admin/overview') return json(overview)
    return json({ items: [] })
  }))

  return render(<LocaleProvider><App /></LocaleProvider>)
}

describe('profile admin navigation', () => {
  beforeEach(() => {
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
})
