import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { User } from '../api'
import { AdminShell } from './AdminShell'

const overview = {
  generated_at: '2026-08-09T12:00:00+03:00',
  stats: {
    users_total: 10,
    users_7d: 2,
    games_total: 50,
    searches_24h: 20,
    partner_clicks_7d: 4,
    alert_events_24h: 3,
  },
  operations: {
    queue: { pending: 1, failed: 0 },
    sources: [
      { source: 'steam', counts: { pending: 0, fresh: 40, stale: 2, failed: 1 }, last_success_at: '2026-08-09T11:59:00+03:00' },
      { source: 'plati', counts: { pending: 1, fresh: 30, stale: 3, failed: 0 }, last_success_at: null },
      { source: 'ggsel', counts: { pending: 0, fresh: 25, stale: 4, failed: 1 }, last_success_at: null },
    ],
    deliveries_24h: { pending: 1, sent: 8, failed: 0, skipped: 2 },
  },
  recent_source_failures: [],
  popular_searches_7d: [{ query: 'Portal 2', searches: 5 }],
  problem_searches: [],
  recent_audit: [],
}

const owner: User = {
  id: 1,
  email: 'owner@example.com',
  display_name: 'Владелец',
  admin_role: 'owner',
  can_access_admin: true,
  can_manage_admin_team: true,
}

const admin: User = {
  ...owner,
  id: 2,
  email: 'admin@example.com',
  display_name: 'Администратор',
  admin_role: 'admin',
  can_manage_admin_team: false,
}

describe('AdminShell role navigation', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(overview), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })))
  })

  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('does not render Team for an admin', () => {
    render(<AdminShell currentUser={admin} />)

    expect(screen.queryByRole('tab', { name: 'Команда' })).not.toBeInTheDocument()
  })

  it('renders Team for an owner', () => {
    render(<AdminShell currentUser={owner} />)

    expect(screen.getByRole('tab', { name: 'Команда' })).toBeInTheDocument()
    expect(screen.getByText('Владелец сайта')).toBeInTheDocument()
  })

  it('supports arrow-key navigation between real tabs', async () => {
    const user = userEvent.setup()
    render(<AdminShell currentUser={admin} />)
    const overviewTab = screen.getByRole('tab', { name: 'Обзор' })

    overviewTab.focus()
    await user.keyboard('{ArrowRight}')

    expect(screen.getByRole('tab', { name: 'Каталог' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tabpanel')).toHaveAccessibleName('Каталог')
  })
})
