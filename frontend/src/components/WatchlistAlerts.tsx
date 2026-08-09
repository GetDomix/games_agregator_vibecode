import type { AlertItem, FavoriteItem } from '../watchlist'
import { scopeLabel } from '../watchlist'
import { useLocale } from '../locale'

type Props = {
  favorites: FavoriteItem[]
  alerts: AlertItem[]
  onEdit: (favorite: FavoriteItem) => void
  onRearm: (alert: AlertItem) => Promise<void>
  onSearch: (name: string, appid: number) => void
  onRemove?: (alert: AlertItem) => void | Promise<void>
}

export function WatchlistAlerts({ favorites, alerts, onEdit, onRearm, onSearch, onRemove }: Props) {
  const { formatPrice: money, tr } = useLocale()
  const active = alerts.filter((alert) => alert.status === 'active')
  const triggered = alerts.filter((alert) => alert.status === 'triggered')
  const favoriteByAppid = new Map(favorites.map((favorite) => [favorite.appid, favorite]))

  const renderAlerts = (items: AlertItem[], isTriggered: boolean) => {
    if (!items.length) {
      return (
        <div className="cabinet-alert-empty">
          <span aria-hidden="true">{isTriggered ? '✓' : '⌁'}</span>
          <p>{isTriggered ? tr('Ни один сигнал ещё не сработал.', 'No alerts have triggered yet.') : tr('Настрой цель у игры из избранного.', 'Set a target for a game in your watchlist.')}</p>
        </div>
      )
    }

    return (
      <div className="list-cards cabinet-alert-list">
        {items.map((alert) => {
          const favorite = favoriteByAppid.get(alert.favorite.appid)

          return (
            <article className={`list-card cabinet-alert-card ${isTriggered ? 'is-triggered' : 'is-active'}`} key={alert.id}>
              <div className="cabinet-alert-top">
                <strong>{alert.favorite.game_name}</strong>
                <span className={`cabinet-alert-state ${isTriggered ? 'triggered' : 'active'}`}>
                  {isTriggered ? tr('Сработал', 'Triggered') : tr('Активен', 'Active')}
                </span>
              </div>
              <div className="cabinet-alert-target">
                <span>{tr('Сигнал при цене', 'Alert at')}</span>
                <b>{money(alert.target_value)}</b>
              </div>
              <p className="cabinet-alert-scopes">{alert.scopes.map((scope) => scopeLabel(scope, tr)).join(' · ')}</p>
              {alert.event && (
                <div className="cabinet-alert-event">
                  <span>{alert.event.source} · {alert.event.offer_kind}</span>
                  <b>{money(alert.event.offer_price_rub)}</b>
                  <small>{tr('Доставка:', 'Delivery:')} {alert.event.delivery?.status || tr('ожидает', 'pending')}</small>
                </div>
              )}
              <div className="actions cabinet-card-actions">
                <button className="btn ghost sm" type="button" onClick={() => onSearch(alert.favorite.game_name, alert.favorite.appid)}>{tr('Цены', 'Prices')}</button>
                {favorite && <button className="btn ghost sm" type="button" onClick={() => onEdit(favorite)}>{tr('Изменить', 'Edit')}</button>}
                {onRemove && <button className="btn ghost sm" type="button" onClick={() => onRemove(alert)}>{tr('Убрать', 'Remove')}</button>}
                {isTriggered && <button className="btn primary sm" type="button" onClick={() => onRearm(alert)}>{tr('Активировать снова', 'Reactivate')}</button>}
              </div>
            </article>
          )
        })}
      </div>
    )
  }

  return (
    <section className="panel section cabinet-alerts">
      <div className="panel-head cabinet-panel-head">
        <div>
          <p className="panel-kicker">{tr('Ценовые сигналы', 'Price signals')}</p>
          <h3>{tr('Алерты', 'Alerts')}</h3>
        </div>
        <span className="cabinet-count">{alerts.length}</span>
      </div>
      <div className="cabinet-scroll cabinet-scroll--alerts" tabIndex={0} aria-label={tr('Ценовые алерты', 'Price alerts')}>
        <div className="watchlist-columns">
          <section className="cabinet-alert-group">
            <header>
              <span className="cabinet-alert-dot active" aria-hidden="true" />
              <h4>{tr('Активные', 'Active')}</h4>
              <b>{active.length}</b>
            </header>
            {renderAlerts(active, false)}
          </section>
          <section className="cabinet-alert-group">
            <header>
              <span className="cabinet-alert-dot triggered" aria-hidden="true" />
              <h4>{tr('Сработавшие', 'Triggered')}</h4>
              <b>{triggered.length}</b>
            </header>
            {renderAlerts(triggered, true)}
          </section>
        </div>
      </div>
    </section>
  )
}
