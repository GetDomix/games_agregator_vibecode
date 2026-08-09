export type AdminRole = 'user' | 'admin' | 'owner'

export type SourceHealth = {
  source: string
  counts: Record<'pending' | 'fresh' | 'stale' | 'failed', number>
  last_success_at: string | null
}

export type AdminAuditEntry = {
  id: number
  request_id: string
  actor: string | null
  action: string
  target_type: string | null
  target_id: string | null
  context: Record<string, unknown>
  created_at: string | null
}

export type AdminOverview = {
  generated_at: string
  stats: {
    users_total: number
    users_7d: number
    games_total: number
    searches_24h: number
    partner_clicks_7d: number
    alert_events_24h: number
  }
  operations: {
    queue: { pending: number; failed: number }
    sources: SourceHealth[]
    deliveries_24h: Record<'pending' | 'sent' | 'failed' | 'skipped', number>
  }
  recent_source_failures: Array<{
    appid: number | null
    game_name: string | null
    source: string
    last_attempt_at: string | null
    consecutive_failures: number
    error: string
  }>
  popular_searches_7d: Array<{ query: string; searches: number }>
  problem_searches: Array<{ query: string; searches: number; last_seen_at: string | null }>
  recent_audit: AdminAuditEntry[]
}

export type SafeAdminUser = {
  id: number
  email: string
  display_name: string
  admin_role: AdminRole
  can_access_admin: boolean
  can_manage_admin_team: boolean
  telegram_linked: boolean
  radar_enabled: boolean
  alert_prefs?: Record<string, unknown> | null
  favorites_count?: number
  searches_count?: number
  created_at: string | null
  last_login_at: string | null
}

export type UserDirectoryResponse = {
  data: SafeAdminUser[]
  meta: { page: number; per_page: number; total: number }
}

export type TeamResponse = { items: SafeAdminUser[] }

export type AuditPage = {
  data: AdminAuditEntry[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export type AdminTabProps = {
  onError: (message: string) => void
  onNotice: (message: string) => void
}
