export type AlertPrefs = { mode: 'simple' | 'advanced'; kinds: ('key' | 'gift' | 'account' | 'rent')[] }
export type ConditionType = 'target_price' | 'discount_percent' | 'new_low'
export type AlertScope = { source: 'steam' | 'plati' | 'ggsel'; offer_kind: 'official' | 'key' | 'gift' | 'account' | 'rent' }
export type Freshness = { source: string; status: string; last_success_at?: string | null; next_refresh_at?: string | null; last_error?: string | null }
export type FavoriteItem = {
  id: number; appid: number; game_name: string; header_image?: string | null; target_price_rub?: number | null
  suggested_target?: { value_rub: number; reference_price_rub: number; reduction_percent: number; source: string; offer_kind: string; observed_at?: string | null; basis: string } | null
  observed_lows?: { source: AlertScope['source']; offer_kind: AlertScope['offer_kind']; price_rub: number }[]
  release_status?: string | null; freshness?: Freshness[]
  alert?: { condition_type: ConditionType; target_value?: number | null; status: 'active' | 'triggered'; scopes: AlertScope[] } | null
}
export type AlertEvent = { id: number; source: string; offer_kind: string; offer_price_rub: number; offer_title?: string | null; offer_url?: string | null; observed_at?: string | null; delivery?: { status: string; attempts: number; sent_at?: string | null; last_error?: string | null } | null }
export type AlertItem = { id: number; status: 'active' | 'triggered'; condition_type: ConditionType; target_value?: number | null; triggered_at?: string | null; favorite: { appid: number; game_name: string }; scopes: AlertScope[]; event?: AlertEvent | null }

export const scopeLabel = (scope: AlertScope, tr: (ru: string, en: string) => string = (ru) => ru) => {
  const source = scope.source === 'steam' ? 'Steam' : scope.source === 'plati' ? 'Plati' : 'GGsel'
  const kind: Record<string, string> = {
    official: tr('официально', 'official'),
    key: tr('ключ', 'key'),
    gift: tr('гифт', 'gift'),
    account: tr('аккаунт', 'account'),
    rent: tr('аренда', 'rent'),
  }
  return `${source}: ${kind[scope.offer_kind] || scope.offer_kind}`
}
