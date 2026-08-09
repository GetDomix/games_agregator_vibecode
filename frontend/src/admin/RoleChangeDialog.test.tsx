import { useState } from 'react'
import { act, cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RoleChangeDialog } from './RoleChangeDialog'
import type { SafeAdminUser } from './types'

const target: SafeAdminUser = {
  id: 3,
  email: 'target@example.com',
  display_name: 'Целевой пользователь',
  admin_role: 'admin',
  can_access_admin: true,
  can_manage_admin_team: false,
  telegram_linked: false,
  radar_enabled: true,
  created_at: '2026-08-09T10:00:00+03:00',
  last_login_at: null,
}

function DialogHarness({ targetRole = 'admin', nextRole = 'owner', onConfirm = async () => undefined }: {
  targetRole?: SafeAdminUser['admin_role']
  nextRole?: SafeAdminUser['admin_role']
  onConfirm?: (password?: string) => Promise<void>
}) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <main data-testid="dialog-background"><button type="button" onClick={() => setOpen(true)}>Изменить роль</button></main>
      {open && (
        <RoleChangeDialog
          target={{ ...target, admin_role: targetRole }}
          nextRole={nextRole}
          onCancel={() => setOpen(false)}
          onConfirm={onConfirm}
          onSuccess={() => setOpen(false)}
        />
      )}
    </>
  )
}

afterEach(cleanup)

describe('RoleChangeDialog', () => {
  it('traps focus for a password transition and restores its trigger on Escape', async () => {
    const user = userEvent.setup()
    render(<DialogHarness />)
    const trigger = screen.getByRole('button', { name: 'Изменить роль' })

    await user.click(trigger)
    const password = screen.getByLabelText('Текущий пароль')
    expect(password).toHaveFocus()
    const backgroundRoot = screen.getByTestId('dialog-background').parentElement
    expect(backgroundRoot).toHaveAttribute('inert')

    const close = screen.getByRole('button', { name: 'Закрыть' })
    close.focus()
    await user.tab({ shift: true })
    expect(screen.getByRole('button', { name: 'Подтвердить' })).toHaveFocus()

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
    expect(backgroundRoot).not.toHaveAttribute('inert')
  })

  it('focuses the safe cancel action for a transition without password', async () => {
    const user = userEvent.setup()
    render(<DialogHarness targetRole="user" nextRole="admin" />)

    await user.click(screen.getByRole('button', { name: 'Изменить роль' }))

    expect(screen.getByRole('button', { name: 'Отмена' })).toHaveFocus()
  })

  it('requires password when promoting a user to owner', async () => {
    const user = userEvent.setup()
    render(<RoleChangeDialog target={target} nextRole="owner" onCancel={vi.fn()} onConfirm={vi.fn()} />)

    expect(screen.getByLabelText('Текущий пароль')).toBeRequired()
    await user.click(screen.getByRole('button', { name: 'Подтвердить' }))

    expect(screen.getByRole('alert')).toHaveTextContent('Введите текущий пароль')
  })

  it('requires password when demoting an owner', () => {
    render(<RoleChangeDialog target={{ ...target, admin_role: 'owner', can_manage_admin_team: true }} nextRole="admin" onCancel={vi.fn()} onConfirm={vi.fn()} />)

    expect(screen.getByLabelText('Текущий пароль')).toBeRequired()
  })

  it('clears password from component memory when cancelled', async () => {
    const user = userEvent.setup()
    render(<RoleChangeDialog target={target} nextRole="owner" onCancel={vi.fn()} onConfirm={vi.fn()} />)
    const password = screen.getByLabelText('Текущий пароль')

    await user.type(password, 'only-in-dialog')
    await user.click(screen.getByRole('button', { name: 'Отмена' }))

    expect(password).toHaveValue('')
  })

  it('disables submission while the role change is pending', async () => {
    let resolve: (() => void) | undefined
    const pending = new Promise<void>((done) => { resolve = done })
    const user = userEvent.setup()
    render(<RoleChangeDialog target={{ ...target, admin_role: 'user' }} nextRole="admin" onCancel={vi.fn()} onConfirm={() => pending} />)

    await user.click(screen.getByRole('button', { name: 'Подтвердить' }))

    expect(screen.getByRole('button', { name: 'Изменяем…' })).toBeDisabled()
    resolve?.()
  })

  it('does not run success cleanup after the dialog unmounts mid-request', async () => {
    let resolve: (() => void) | undefined
    const pending = new Promise<void>((done) => { resolve = done })
    const onSuccess = vi.fn()
    const user = userEvent.setup()
    const view = render(
      <RoleChangeDialog
        target={{ ...target, admin_role: 'user' }}
        nextRole="admin"
        onCancel={vi.fn()}
        onConfirm={() => pending}
        onSuccess={onSuccess}
      />,
    )
    await user.click(screen.getByRole('button', { name: 'Подтвердить' }))

    view.unmount()
    await act(async () => {
      resolve?.()
      await pending
    })

    expect(onSuccess).not.toHaveBeenCalled()
  })

  it('renders a backend denial message in an alert', async () => {
    const user = userEvent.setup()
    render(<RoleChangeDialog target={{ ...target, admin_role: 'user' }} nextRole="admin" onCancel={vi.fn()} onConfirm={() => Promise.reject(new Error('Слишком много попыток. Повторите позже.'))} />)

    await user.click(screen.getByRole('button', { name: 'Подтвердить' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Слишком много попыток. Повторите позже.')
  })
})
