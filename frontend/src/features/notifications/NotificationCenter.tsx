import { useEffect, useRef } from 'react'
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion'
import { IconBell, IconClose, IconRadar } from '../../shared/ui/icons'
import { useLocale } from '../../shared/i18n/LocaleProvider'
import type { SiteNotification } from './types'

type BellProps = {
  unreadCount: number
  open: boolean
  onClick: () => void
  size?: number
  className?: string
}

export function NotificationBell({ unreadCount, open, onClick, size = 17, className = '' }: BellProps) {
  const { tr } = useLocale()
  const badge = unreadCount > 9 ? '9+' : String(unreadCount)
  const label = unreadCount
    ? tr(`Уведомления, непрочитанных: ${unreadCount}`, `Notifications, ${unreadCount} unread`)
    : tr('Уведомления', 'Notifications')

  return (
    <button
      type="button"
      className={`btn ghost sm icon-btn notification-btn compact notification-trigger ${open ? 'is-active' : ''} ${className}`.trim()}
      onClick={onClick}
      aria-label={label}
      aria-haspopup="dialog"
      aria-expanded={open}
      aria-controls="site-notification-center"
    >
      <IconBell size={size} />
      {unreadCount > 0 && <span className="notification-badge" aria-hidden="true">{badge}</span>}
    </button>
  )
}

type CenterProps = {
  open: boolean
  loading: boolean
  loadingEarlier: boolean
  hasEarlier: boolean
  unreadCount: number
  items: SiteNotification[]
  liveNotification: SiteNotification | null
  onClose: () => void
  onDismissLive: () => void
  onOpenGame: (name: string, appid: number) => void
  onOpenLibrary: () => void
  onLoadEarlier: () => void
}

function timeLabel(value: string | null, locale: string) {
  if (!value) return ''
  const date = new Date(value)
  return new Intl.DateTimeFormat(locale === 'en' ? 'en-US' : 'ru-RU', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
  }).format(date)
}

function kindLabel(item: SiteNotification, tr: (ru: string, en: string) => string) {
  if (item.type === 'game_alert') return tr('ИГРОВОЕ УВЕДОМЛЕНИЕ', 'GAME NOTIFICATION')
  if (item.data.priority === 'important') return tr('ВАЖНОЕ ОТ АДМИНИСТРАЦИИ', 'IMPORTANT ADMIN MESSAGE')
  if (item.data.priority === 'update') return tr('ОБНОВЛЕНИЕ ИГРОСКАНА', 'IGROSCAN UPDATE')
  return tr('СООБЩЕНИЕ ИГРОСКАНА', 'IGROSCAN MESSAGE')
}

export function NotificationCenter({
  open, loading, loadingEarlier, hasEarlier, unreadCount, items, liveNotification,
  onClose, onDismissLive, onOpenGame, onOpenLibrary, onLoadEarlier,
}: CenterProps) {
  const { locale, tr } = useLocale()
  const panelRef = useRef<HTMLElement>(null)
  const reduceMotion = useReducedMotion()

  useEffect(() => {
    if (!open) return
    panelRef.current?.focus()
    const onPointerDown = (event: PointerEvent) => {
      const target = event.target
      if (!(target instanceof Element) || target.closest('#site-notification-center') || target.closest('.notification-trigger')) return
      onClose()
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('pointerdown', onPointerDown)
    window.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      window.removeEventListener('keydown', onKeyDown)
    }
  }, [open, onClose])

  const openGame = (item: SiteNotification) => {
    if (!item.data.appid || !item.data.game_name) return
    onClose()
    onOpenGame(item.data.game_name, item.data.appid)
  }

  return (
    <>
      <AnimatePresence>
        {open && (
        <motion.section
          ref={panelRef}
          id="site-notification-center"
          className="notification-center"
          role="dialog"
          aria-modal="false"
          aria-labelledby="notification-center-title"
          tabIndex={-1}
          initial={{ opacity: 0, y: reduceMotion ? 0 : -6, scale: reduceMotion ? 1 : 0.97 }}
          animate={{ opacity: 1, y: 0, scale: 1 }}
          exit={{ opacity: 0, y: reduceMotion ? 0 : -4, scale: reduceMotion ? 1 : 0.98 }}
          transition={{ duration: reduceMotion ? 0.1 : 0.18, ease: [0.22, 0.61, 0.2, 1] }}
        >
          <header className="notification-center-head">
            <div>
              <p className="eyebrow">{tr('Центр событий', 'Event center')}</p>
              <h2 id="notification-center-title">{tr('Уведомления', 'Notifications')}</h2>
            </div>
            <button type="button" className="modal-close" onClick={onClose} aria-label={tr('Закрыть уведомления', 'Close notifications')}>
              <IconClose size={16} />
            </button>
          </header>
          <div className="notification-center-status" role="status">
            <span className="notification-status-mark" aria-hidden="true" />
            {unreadCount > 0
              ? tr(`${unreadCount} непрочитанных`, `${unreadCount} unread`)
              : tr('Всё просмотрено', 'All caught up')}
          </div>
          <div
            className="notification-ledger"
            aria-label={tr('Список уведомлений', 'Notification list')}
            aria-busy={loading || loadingEarlier}
            tabIndex={items.length ? 0 : undefined}
          >
            {loading && !items.length && <p className="notification-empty">{tr('Синхронизируем события…', 'Syncing events…')}</p>}
            {!loading && !items.length && (
              <div className="notification-empty">
                <IconRadar size={24} />
                <strong>{tr('Уведомлений пока нет', 'No notifications yet')}</strong>
                <span>{tr('Здесь появятся уведомления об играх и сообщения Игроскана.', 'Game notifications and Igroscan messages will appear here.')}</span>
              </div>
            )}
            {items.map((item) => (
              <article className={`notification-row ${item.type} ${item.read ? 'is-read' : 'is-unread'}`} key={item.id}>
                <div className="notification-row-meta">
                  <span>{kindLabel(item, tr)}</span>
                  <time dateTime={item.published_at || undefined}>{timeLabel(item.published_at, locale)}</time>
                </div>
                <strong>{item.title}</strong>
                <p>{item.body}</p>
                {item.type === 'game_alert' && item.data.appid && item.data.game_name && (
                  <button type="button" className="btn link sm" onClick={() => openGame(item)}>
                    {tr('Открыть цены', 'Open prices')}
                  </button>
                )}
              </article>
            ))}
            {hasEarlier && (
              <div className="notification-history-more">
                <button type="button" className="btn link sm" onClick={onLoadEarlier} disabled={loadingEarlier}>
                  {loadingEarlier
                    ? tr('Загружаем…', 'Loading…')
                    : tr('Показать предыдущие', 'Show earlier')}
                </button>
              </div>
            )}
          </div>
          <footer className="notification-center-foot">
            <button type="button" className="btn ghost sm" onClick={() => { onClose(); onOpenLibrary() }}>
              {tr('Настроить уведомления', 'Configure notifications')}
            </button>
          </footer>
        </motion.section>
        )}
      </AnimatePresence>

      <AnimatePresence>
        {liveNotification && (
        <motion.aside
          className={`notification-live ${liveNotification.type}`}
          aria-live="polite"
          aria-atomic="true"
          initial={{ opacity: 0, x: reduceMotion ? 0 : 18 }}
          animate={{ opacity: 1, x: 0 }}
          exit={{ opacity: 0, x: reduceMotion ? 0 : 12 }}
          transition={{ duration: reduceMotion ? 0.1 : 0.22, ease: [0.22, 0.61, 0.2, 1] }}
        >
          <span className="notification-live-icon" aria-hidden="true"><IconBell size={17} /></span>
          <div>
            <small>{kindLabel(liveNotification, tr)}</small>
            <strong>{liveNotification.title}</strong>
            <p>{liveNotification.body}</p>
            {liveNotification.type === 'game_alert' && liveNotification.data.appid && liveNotification.data.game_name && (
              <button type="button" onClick={() => openGame(liveNotification)}>{tr('Показать цены', 'Show prices')}</button>
            )}
          </div>
          <button type="button" className="notification-live-close" onClick={onDismissLive} aria-label={tr('Скрыть уведомление', 'Dismiss notification')}>
            <IconClose size={14} />
          </button>
        </motion.aside>
        )}
      </AnimatePresence>
    </>
  )
}
