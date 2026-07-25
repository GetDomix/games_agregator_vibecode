import type { AlertItem, FavoriteItem } from '../watchlist'
import { scopeLabel } from '../watchlist'

type Props = { favorites: FavoriteItem[]; alerts: AlertItem[]; onEdit: (favorite: FavoriteItem) => void; onRearm: (alert: AlertItem) => Promise<void>; onSearch: (name: string, appid: number) => void }
export function WatchlistAlerts({ favorites, alerts, onEdit, onRearm, onSearch }: Props) {
  const active = alerts.filter((alert) => alert.status === 'active')
  const triggered = alerts.filter((alert) => alert.status === 'triggered')
  const favoriteByAppid = new Map(favorites.map((favorite) => [favorite.appid, favorite]))
  const render = (items: AlertItem[], isTriggered: boolean) => items.length ? <div className="list-cards">{items.map((alert) => <article className="list-card alert-card" key={alert.id}><div><strong>{alert.favorite.game_name}</strong><span className="offer-meta">цель: {alert.target_value ?? 'не задана'} ₽ · {alert.scopes.map(scopeLabel).join(' · ')}</span>{alert.event && <span className="offer-meta">{alert.event.source} · {alert.event.offer_kind}: {alert.event.offer_price_rub} ₽ · доставка: {alert.event.delivery?.status || 'ожидает'}</span>}<div className="actions"><button className="btn ghost sm" type="button" onClick={() => onSearch(alert.favorite.game_name, alert.favorite.appid)}>Цены</button>{favoriteByAppid.get(alert.favorite.appid) && <button className="btn ghost sm" type="button" onClick={() => onEdit(favoriteByAppid.get(alert.favorite.appid)!)}>Изменить</button>}{isTriggered && <button className="btn primary sm" type="button" onClick={() => onRearm(alert)}>Активировать снова</button>}</div></div></article>)}</div> : <p className="muted">Пока пусто.</p>
  return <section className="panel section"><h3>Алерты</h3><div className="watchlist-columns"><div><h4>Активные · {active.length}</h4>{render(active, false)}</div><div><h4>Сработавшие · {triggered.length}</h4>{render(triggered, true)}</div></div></section>
}
