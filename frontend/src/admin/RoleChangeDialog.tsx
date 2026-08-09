import { useEffect, useId, useState } from 'react'
import type { FormEvent } from 'react'
import type { AdminRole, SafeAdminUser } from './types'

const roleLabels: Record<AdminRole, string> = {
  user: 'Пользователь',
  admin: 'Администратор',
  owner: 'Владелец',
}

export type RoleChangeDialogProps = {
  target: SafeAdminUser
  nextRole: AdminRole
  onCancel: () => void
  onConfirm: (currentPassword?: string) => Promise<void>
}

export function RoleChangeDialog({ target, nextRole, onCancel, onConfirm }: RoleChangeDialogProps) {
  const titleId = useId()
  const descriptionId = useId()
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [pending, setPending] = useState(false)
  const requiresPassword = target.admin_role === 'owner' || nextRole === 'owner'

  const close = () => {
    if (pending) return
    setPassword('')
    setError('')
    onCancel()
  }

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') close()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  })

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (requiresPassword && !password) {
      setError('Введите текущий пароль')
      return
    }
    setPending(true)
    setError('')
    try {
      await onConfirm(requiresPassword ? password : undefined)
      setPassword('')
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : 'Не удалось изменить роль')
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="admin-dialog-backdrop" role="presentation">
      <div className="admin-role-dialog" role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={descriptionId}>
        <form noValidate onSubmit={submit}>
          <div className="admin-dialog-head">
            <div><p className="eyebrow">Подтверждение доступа</p><h3 id={titleId}>Изменить роль</h3></div>
            <button type="button" className="admin-dialog-close" aria-label="Закрыть" disabled={pending} onClick={close}>×</button>
          </div>

          <p id={descriptionId} className="admin-role-transition">
            <b>{target.display_name || target.email}</b>
            <span>{roleLabels[target.admin_role]} → {roleLabels[nextRole]}</span>
          </p>
          <p className="muted admin-dialog-consequence">
            Действующие сессии пользователя будут завершены. Новый доступ появится после повторного входа.
          </p>

          {requiresPassword && (
            <div className="admin-dialog-field">
              <label htmlFor="admin-current-password">Текущий пароль</label>
              <input
                id="admin-current-password"
                type="password"
                autoComplete="current-password"
                value={password}
                required
                autoFocus
                disabled={pending}
                onChange={(event) => setPassword(event.target.value)}
              />
              <small>Нужен пароль владельца, который подтверждает это действие.</small>
            </div>
          )}

          {error && <div className="admin-message danger" role="alert">{error}</div>}

          <div className="admin-dialog-actions">
            <button type="button" className="btn ghost" disabled={pending} onClick={close}>Отмена</button>
            <button type="submit" className="btn primary" disabled={pending}>{pending ? 'Изменяем…' : 'Подтвердить'}</button>
          </div>
        </form>
      </div>
    </div>
  )
}
