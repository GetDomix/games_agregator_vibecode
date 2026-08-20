import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AdminBroadcastTab } from './AdminBroadcastTab'

describe('AdminBroadcastTab', () => {
  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('requires explicit confirmation and clears the form after publication', async () => {
    const user = userEvent.setup()
    const notice = vi.fn()
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ id: 7, audience_count: 42 }), {
      status: 201,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)
    render(<AdminBroadcastTab onError={vi.fn()} onNotice={notice} />)

    await user.type(screen.getByLabelText('Заголовок'), 'Обновление каталога')
    await user.type(screen.getByLabelText('Текст уведомления'), 'Добавили новые источники цен.')
    const submit = screen.getByRole('button', { name: 'Отправить всем пользователям' })
    expect(submit).toBeDisabled()
    await user.click(screen.getByRole('checkbox'))
    await user.click(submit)

    expect(fetchMock).toHaveBeenCalledOnce()
    expect(notice).toHaveBeenCalledWith('Сообщение опубликовано для 42 пользователей.')
    expect(screen.getByLabelText('Заголовок')).toHaveValue('')
  })
})
