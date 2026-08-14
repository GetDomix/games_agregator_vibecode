import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, it, vi } from 'vitest'
import { AdminUsersTab } from './AdminUsersTab'

function response(name: string, page = 1, total = 1) {
  return new Response(JSON.stringify({
    data: [{
      id: page,
      email: `${name.toLowerCase()}@example.com`,
      display_name: name,
      admin_role: 'user',
      can_access_admin: false,
      can_manage_admin_team: false,
      telegram_linked: false,
      radar_enabled: true,
      favorites_count: 0,
      searches_count: 0,
      created_at: null,
      last_login_at: null,
    }],
    meta: { page, per_page: 30, total },
  }), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

it('loads and renders the next directory page', async () => {
  vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
    return Promise.resolve(String(input).includes('page=2')
      ? response('Страница два', 2, 31)
      : response('Страница один', 1, 31))
  }))
  const user = userEvent.setup()
  render(<AdminUsersTab onError={() => undefined} onNotice={() => undefined} />)

  expect(await screen.findByText('Страница один')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Дальше' }))

  expect(await screen.findByText('Страница два')).toBeInTheDocument()
  expect(screen.getByText('2 / 2')).toBeInTheDocument()
})
