import { useEffect, useId, useRef, useState } from 'react'
import type { FormEvent, KeyboardEvent as ReactKeyboardEvent, RefObject } from 'react'
import { createPortal } from 'react-dom'
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
  onSuccess?: () => void
  returnFocusFallbackRef?: RefObject<HTMLElement | null>
}

type BackgroundState = {
  element: Element
  hadInert: boolean
  ariaHidden: string | null
}

export function RoleChangeDialog({ target, nextRole, onCancel, onConfirm, onSuccess, returnFocusFallbackRef }: RoleChangeDialogProps) {
  const titleId = useId()
  const descriptionId = useId()
  const backdropRef = useRef<HTMLDivElement>(null)
  const passwordRef = useRef<HTMLInputElement>(null)
  const cancelRef = useRef<HTMLButtonElement>(null)
  const previousFocusRef = useRef<HTMLElement | null>(document.activeElement as HTMLElement | null)
  const mounted = useRef(false)
  const [error, setError] = useState('')
  const [pending, setPending] = useState(false)
  const requiresPassword = target.admin_role === 'owner' || nextRole === 'owner'

  const clearSecret = () => {
    if (passwordRef.current) passwordRef.current.value = ''
  }

  const close = () => {
    if (pending) return
    clearSecret()
    setError('')
    onCancel()
  }

  useEffect(() => {
    mounted.current = true
    return () => { mounted.current = false }
  }, [])

  useEffect(() => {
    const backdrop = backdropRef.current
    if (!backdrop) return
    const previousFocus = previousFocusRef.current
    const returnFocusFallback = returnFocusFallbackRef?.current

    const background: BackgroundState[] = Array.from(document.body.children)
      .filter((element) => element !== backdrop)
      .map((element) => ({
        element,
        hadInert: element.hasAttribute('inert'),
        ariaHidden: element.getAttribute('aria-hidden'),
      }))

    const initialFocus = requiresPassword ? passwordRef.current : cancelRef.current
    initialFocus?.focus()

    for (const state of background) {
      state.element.setAttribute('inert', '')
      state.element.setAttribute('aria-hidden', 'true')
    }

    return () => {
      for (const state of background) {
        if (!state.hadInert) state.element.removeAttribute('inert')
        if (state.ariaHidden === null) state.element.removeAttribute('aria-hidden')
        else state.element.setAttribute('aria-hidden', state.ariaHidden)
      }
      const returnTarget = previousFocus?.isConnected ? previousFocus : returnFocusFallback
      if (returnTarget?.isConnected) returnTarget.focus()
    }
  }, [requiresPassword, returnFocusFallbackRef])

  const trapFocus = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.preventDefault()
      close()
      return
    }
    if (event.key !== 'Tab') return

    const focusable = Array.from(backdropRef.current?.querySelectorAll<HTMLElement>(
      'button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ) ?? [])
    if (focusable.length === 0) {
      event.preventDefault()
      return
    }
    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const password = passwordRef.current?.value ?? ''
    if (requiresPassword && !password) {
      setError('Введите текущий пароль')
      passwordRef.current?.focus()
      return
    }
    setPending(true)
    setError('')
    try {
      await onConfirm(requiresPassword ? password : undefined)
      if (!mounted.current) return
      clearSecret()
      setPending(false)
      onSuccess?.()
    } catch (submitError) {
      if (!mounted.current) return
      setError(submitError instanceof Error ? submitError.message : 'Не удалось изменить роль')
      setPending(false)
    }
  }

  return createPortal(
    <div ref={backdropRef} className="admin-dialog-backdrop" role="presentation" onKeyDown={trapFocus}>
      <div className="admin-role-dialog" role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={descriptionId}>
        <form noValidate onSubmit={submit}>
          <div className="admin-dialog-head">
            <div><p className="eyebrow">Подтверждение доступа</p><h3 id={titleId}>Изменить роль</h3></div>
            <button type="button" className="admin-dialog-close" aria-label="Закрыть" disabled={pending} onClick={close}>×</button>
          </div>

          <p id={descriptionId} className="admin-role-transition">
            <b>{target.display_name || target.email || 'Без имени'}</b>
            <span>{roleLabels[target.admin_role]} → {roleLabels[nextRole]}</span>
          </p>
          <p className="muted admin-dialog-consequence">
            Действующие сессии пользователя будут завершены. Новый доступ появится после повторного входа.
          </p>

          {requiresPassword && (
            <div className="admin-dialog-field">
              <label htmlFor="admin-current-password">Текущий пароль</label>
              <input
                ref={passwordRef}
                id="admin-current-password"
                type="password"
                autoComplete="current-password"
                required
                disabled={pending}
              />
              <small>Нужен пароль владельца, который подтверждает это действие.</small>
            </div>
          )}

          {error && <div className="admin-message danger" role="alert">{error}</div>}

          <div className="admin-dialog-actions">
            <button ref={cancelRef} type="button" className="btn ghost" disabled={pending} onClick={close}>Отмена</button>
            <button type="submit" className="btn primary" disabled={pending}>{pending ? 'Изменяем…' : 'Подтвердить'}</button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  )
}
