import { StrictMode, useState } from 'react'
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { User } from '../api'
import { AdminTeamTab } from './AdminTeamTab'

const currentUser: User = {
  id: 1,
  email: 'owner@example.com',
  display_name: 'Владелец',
  admin_role: 'owner',
  can_access_admin: true,
  can_manage_admin_team: true,
}

type TeamFixture = {
  id: number
  email: string | null
  display_name: string
  admin_role: 'user' | 'admin' | 'owner'
  can_access_admin: boolean
  can_manage_admin_team: boolean
  telegram_linked: boolean
  radar_enabled: boolean
  created_at: string | null
  last_login_at: string | null
  is_server_managed_owner: boolean
}

const owner: TeamFixture = {
  id: 1,
  email: 'owner@example.com',
  display_name: 'Владелец',
  admin_role: 'owner',
  can_access_admin: true,
  can_manage_admin_team: true,
  telegram_linked: false,
  radar_enabled: true,
  created_at: '2026-08-09T10:00:00+03:00',
  last_login_at: null,
  is_server_managed_owner: false,
}

const secondOwner: TeamFixture = { ...owner, id: 2, email: 'second@example.com', display_name: 'Второй владелец' }

function response(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } })
}

function TeamHarness() {
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  return (
    <>
      {error && <div role="alert">{error}</div>}
      {notice && <div role="status">{notice}</div>}
      <AdminTeamTab currentUser={currentUser} onError={setError} onNotice={setNotice} />
    </>
  )
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('AdminTeamTab security integration', () => {
  it('does not offer demotion of the sole effective owner and explains why', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response({ items: [owner] })))
    render(<StrictMode><TeamHarness /></StrictMode>)

    const role = await screen.findByLabelText('Новая роль для Владелец')

    expect(within(role).getByRole('option', { name: 'Пользователь' })).toBeDisabled()
    expect(within(role).getByRole('option', { name: 'Администратор' })).toBeDisabled()
    expect(role).toHaveAccessibleDescription('Нельзя понизить единственного эффективного владельца')
  })

  it('does not offer demotion of a server-managed owner and renders nullable email safely', async () => {
    const serverOwner = { ...owner, id: 3, email: null, display_name: 'Root', is_server_managed_owner: true }
    vi.stubGlobal('fetch', vi.fn(async () => response({ items: [owner, serverOwner] })))
    render(<TeamHarness />)

    const role = await screen.findByLabelText('Новая роль для Root')

    expect(within(role).getByRole('option', { name: 'Пользователь' })).toBeDisabled()
    expect(role).toHaveAccessibleDescription('Этот владелец управляется серверной конфигурацией')
    expect(screen.getByText('#3 · Без email')).toBeInTheDocument()
  })

  it('keeps the password dialog mounted through PATCH and refresh, then clears and closes it', async () => {
    let teamLoads = 0
    let finishRefresh: ((value: Response) => void) | undefined
    const refresh = new Promise<Response>((resolve) => { finishRefresh = resolve })
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = String(input)
      if (path === '/api/admin/team' && teamLoads++ === 0) return response({ items: [owner, secondOwner] })
      if (path === '/api/admin/team/2' && init?.method === 'PATCH') {
        const body = JSON.parse(String(init.body)) as Record<string, unknown>
        return body.role === 'admin' && body.current_password === 'owner-secret'
          ? response({ ok: true, user: { ...secondOwner, admin_role: 'admin' } })
          : response({ message: 'Некорректное тело смены роли' }, 422)
      }
      if (path === '/api/admin/team') return refresh
      return response({ message: 'Неожиданный запрос' }, 500)
    }))
    const user = userEvent.setup()
    render(<TeamHarness />)
    const role = await screen.findByLabelText('Новая роль для Второй владелец')
    await user.selectOptions(role, 'admin')
    await user.click(screen.getAllByRole('button', { name: 'Изменить' })[1])
    const password = screen.getByLabelText('Текущий пароль')
    await user.type(password, 'owner-secret')
    await user.click(screen.getByRole('button', { name: 'Подтвердить' }))

    expect(screen.getByRole('dialog')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Изменяем…' })).toBeDisabled()

    finishRefresh?.(response({ items: [owner, { ...secondOwner, admin_role: 'admin', can_manage_admin_team: false }] }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(password).toHaveValue('')
    expect(screen.getByRole('status')).toHaveTextContent('роль «Администратор» применена')
  })

  it.each([
    [403, 'Доступ только для владельца'],
    [422, 'Текущий пароль неверен'],
    [429, 'Слишком много попыток'],
  ])('surfaces a %s role-change response inside the dialog', async (status, message) => {
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      if (String(input) === '/api/admin/team') return response({ items: [owner, secondOwner] })
      return response({ message }, status)
    }))
    const user = userEvent.setup()
    render(<TeamHarness />)
    const role = await screen.findByLabelText('Новая роль для Второй владелец')
    await user.selectOptions(role, 'admin')
    await user.click(screen.getAllByRole('button', { name: 'Изменить' })[1])
    await user.type(screen.getByLabelText('Текущий пароль'), 'owner-secret')
    await user.click(screen.getByRole('button', { name: 'Подтвердить' }))

    expect(await screen.findAllByRole('alert')).toEqual(expect.arrayContaining([
      expect.objectContaining({ textContent: message }),
    ]))
  })

  it('reports a refresh failure separately after a committed role change', async () => {
    let teamLoads = 0
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = String(input)
      if (path === '/api/admin/team' && teamLoads++ === 0) return response({ items: [owner, { ...secondOwner, admin_role: 'admin', can_manage_admin_team: false }] })
      if (path === '/api/admin/team/2' && init?.method === 'PATCH') return response({ ok: true, user: { ...secondOwner, admin_role: 'user' } })
      if (path === '/api/admin/team') return response({ message: 'Не удалось обновить состав команды' }, 500)
      return response({ message: 'Неожиданный запрос' }, 500)
    }))
    const user = userEvent.setup()
    render(<TeamHarness />)
    const role = await screen.findByLabelText('Новая роль для Второй владелец')
    await user.selectOptions(role, 'user')
    await user.click(screen.getAllByRole('button', { name: 'Изменить' })[1])
    await user.click(screen.getByRole('button', { name: 'Подтвердить' }))

    expect(await screen.findByRole('status')).toHaveTextContent('роль «Пользователь» применена')
    expect(screen.getByRole('alert')).toHaveTextContent('Не удалось обновить состав команды')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
