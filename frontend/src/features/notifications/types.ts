export type SiteNotificationType = 'game_alert' | 'admin_broadcast'

export type SiteNotification = {
  id: number
  type: SiteNotificationType
  title: string
  body: string
  data: {
    appid?: number | null
    game_name?: string
    source?: string
    offer_kind?: string
    offer_price_rub?: number
    offer_url?: string | null
    priority?: 'info' | 'important' | 'update'
  }
  published_at: string | null
  read: boolean
}

export type NotificationFeed = {
  items: SiteNotification[]
  unread_count: number
  latest_id: number
  has_more: boolean
  next_before_id: number | null
}
