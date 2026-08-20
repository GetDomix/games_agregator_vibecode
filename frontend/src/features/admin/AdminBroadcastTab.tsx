import { useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../../shared/api/client'
import type { AdminTabProps } from './types'

type Priority = 'info' | 'important' | 'update'

export function AdminBroadcastTab({ onError, onNotice }: AdminTabProps) {
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [priority, setPriority] = useState<Priority>('info')
  const [confirmed, setConfirmed] = useState(false)
  const [sending, setSending] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!confirmed || sending) return
    setSending(true)
    onError('')
    onNotice('')
    try {
      const result = await api<{ audience_count: number }>('/api/admin/notifications/broadcast', {
        method: 'POST',
        body: JSON.stringify({ title: title.trim(), body: body.trim(), priority }),
      })
      onNotice(`Сообщение опубликовано для ${result.audience_count} пользователей.`)
      setTitle('')
      setBody('')
      setPriority('info')
      setConfirmed(false)
    } catch (error) {
      onError(error instanceof Error ? error.message : 'Не удалось отправить сообщение')
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="admin-tab-stack admin-broadcast-layout">
      <div className="admin-tab-heading">
        <div>
          <p className="eyebrow">Связь с пользователями</p>
          <h3>Массовое уведомление</h3>
          <p className="muted">Сообщение сразу сохраняется в центре уведомлений всех уже зарегистрированных аккаунтов.</p>
        </div>
      </div>

      <form className="admin-broadcast-form" onSubmit={submit}>
        <label className="field-label" htmlFor="broadcast-title">Заголовок</label>
        <input
          id="broadcast-title"
          type="text"
          minLength={3}
          maxLength={120}
          required
          value={title}
          onChange={(event) => { setTitle(event.target.value); setConfirmed(false) }}
        />

        <fieldset className="admin-broadcast-priority">
          <legend>Тип сообщения</legend>
          {([
            ['info', 'Сообщение'],
            ['update', 'Обновление'],
            ['important', 'Важно'],
          ] as const).map(([value, label]) => (
            <label key={value}>
              <input type="radio" name="broadcast-priority" value={value} checked={priority === value} onChange={() => { setPriority(value); setConfirmed(false) }} />
              <span>{label}</span>
            </label>
          ))}
        </fieldset>

        <label className="field-label" htmlFor="broadcast-body">Текст уведомления</label>
        <textarea
          id="broadcast-body"
          minLength={1}
          maxLength={1000}
          required
          rows={7}
          value={body}
          onChange={(event) => { setBody(event.target.value); setConfirmed(false) }}
        />
        <div className="admin-broadcast-counter" aria-live="polite">{body.length} / 1000</div>

        <section className={`admin-broadcast-preview priority-${priority}`} aria-labelledby="broadcast-preview-title">
          <p className="eyebrow" id="broadcast-preview-title">Предпросмотр</p>
          <strong>{title.trim() || 'Заголовок сообщения'}</strong>
          <p>{body.trim() || 'Здесь пользователь увидит текст уведомления.'}</p>
        </section>

        <label className="admin-broadcast-confirm">
          <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
          <span>Я проверил текст. Сообщение увидят все текущие пользователи, отменить публикацию после отправки нельзя.</span>
        </label>

        <div className="actions">
          <button type="submit" className="btn primary" disabled={!confirmed || !title.trim() || !body.trim() || sending}>
            {sending ? 'Публикуем…' : 'Отправить всем пользователям'}
          </button>
        </div>
      </form>
    </div>
  )
}
