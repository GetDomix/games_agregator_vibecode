import { act, cleanup, render, renderHook, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LocaleProvider } from '../../shared/i18n/LocaleProvider'
import { NotificationBell, NotificationCenter } from './NotificationCenter'
import { useNotifications } from './useNotifications'
import type { SiteNotification } from './types'

const gameNotification: SiteNotification = {
  id: 12,
  type: 'game_alert',
  title: 'Цель цены достигнута',
  body: 'Portal 2 · Steam: 99 ₽',
  data: { appid: 620, game_name: 'Portal 2' },
  published_at: '2026-08-20T12:00:00+03:00',
  read: false,
}

describe('NotificationCenter', () => {
  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('caps the visible badge at 9+ and exposes the exact unread count to assistive tech', () => {
    render(<LocaleProvider><NotificationBell unreadCount={17} open={false} onClick={vi.fn()} /></LocaleProvider>)

    expect(screen.getByText('9+')).toBeInTheDocument()
    expect(screen.getByRole('button')).toHaveAccessibleName('Уведомления, непрочитанных: 17')
  })

  it('opens a game from the ledger and keeps administration copy textual', async () => {
    const user = userEvent.setup()
    const openGame = vi.fn()
    const close = vi.fn()
    render(
      <LocaleProvider>
        <NotificationCenter
          open
          loading={false}
          loadingEarlier={false}
          hasEarlier={false}
          unreadCount={0}
          items={[gameNotification, { ...gameNotification, id: 13, type: 'admin_broadcast', data: { priority: 'important' }, title: 'Работы', body: 'Сервис обновится ночью.' }]}
          liveNotification={null}
          onClose={close}
          onDismissLive={vi.fn()}
          onOpenGame={openGame}
          onOpenLibrary={vi.fn()}
          onLoadEarlier={vi.fn()}
        />
      </LocaleProvider>,
    )

    expect(screen.getByText('ВАЖНОЕ ОТ АДМИНИСТРАЦИИ')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Открыть цены' }))
    expect(close).toHaveBeenCalled()
    expect(openGame).toHaveBeenCalledWith('Portal 2', 620)
  })

  it('uses notification language and sends settings to the favorites library', async () => {
    const user = userEvent.setup()
    const openLibrary = vi.fn()
    render(
      <LocaleProvider>
        <NotificationCenter
          open
          loading={false}
          loadingEarlier={false}
          hasEarlier={false}
          unreadCount={0}
          items={[]}
          liveNotification={null}
          onClose={vi.fn()}
          onDismissLive={vi.fn()}
          onOpenGame={vi.fn()}
          onOpenLibrary={openLibrary}
          onLoadEarlier={vi.fn()}
        />
      </LocaleProvider>,
    )

    expect(screen.getByText('Уведомлений пока нет')).toBeInTheDocument()
    expect(screen.queryByText(/сигнал|Telegram/i)).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Настроить уведомления' }))
    expect(openLibrary).toHaveBeenCalledOnce()
  })

  it('keeps older notifications behind an explicit in-ledger pagination action', async () => {
    const user = userEvent.setup()
    const loadEarlier = vi.fn()
    render(
      <LocaleProvider>
        <NotificationCenter
          open
          loading={false}
          loadingEarlier={false}
          hasEarlier
          unreadCount={12}
          items={Array.from({ length: 12 }, (_, index) => ({ ...gameNotification, id: 100 - index }))}
          liveNotification={null}
          onClose={vi.fn()}
          onDismissLive={vi.fn()}
          onOpenGame={vi.fn()}
          onOpenLibrary={vi.fn()}
          onLoadEarlier={loadEarlier}
        />
      </LocaleProvider>,
    )

    expect(screen.getByLabelText('Список уведомлений')).toHaveAttribute('tabindex', '0')
    await user.click(screen.getByRole('button', { name: 'Показать предыдущие' }))
    expect(loadEarlier).toHaveBeenCalledOnce()
  })

  it('marks the current feed read when the bell is opened', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      if (String(input).includes('read-through')) return new Response(JSON.stringify({ read_through_id: 12 }), { status: 200, headers: { 'Content-Type': 'application/json' } })
      return new Response(JSON.stringify({ items: [gameNotification], unread_count: 1, latest_id: 12 }), { status: 200, headers: { 'Content-Type': 'application/json' } })
    })
    vi.stubGlobal('fetch', fetchMock)
    const { result } = renderHook(() => useNotifications(true))

    await waitFor(() => expect(result.current.unreadCount).toBe(1))
    act(() => result.current.toggle())
    await waitFor(() => expect(result.current.unreadCount).toBe(0))
    expect(fetchMock).toHaveBeenCalledWith('/api/me/notifications/read-through', expect.objectContaining({ method: 'POST' }))
  })
})
