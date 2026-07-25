import { useEffect, useState } from 'react'
import type { AlertScope, FavoriteItem } from '../watchlist'

type Props = { favorite: Pick<FavoriteItem, 'appid' | 'game_name' | 'header_image' | 'alert'>; onClose: () => void; onSave: (value: { target_value: number | null; scopes: AlertScope[] }) => Promise<void> }
const MARKET_KINDS: AlertScope['offer_kind'][] = ['key', 'gift', 'account', 'rent']
const KIND_LABEL: Record<AlertScope['offer_kind'], string> = { official: 'Официальная версия', key: 'Ключ', gift: 'Гифт', account: 'Аккаунт', rent: 'Аренда' }

export function AlertSettingsModal({ favorite, onClose, onSave }: Props) {
  const [target, setTarget] = useState(favorite.alert?.target_value?.toString() || '')
  const [scopes, setScopes] = useState<AlertScope[]>(favorite.alert?.scopes || [{ source: 'steam', offer_kind: 'official' }])
  const [saving, setSaving] = useState(false)
  const has = (source: AlertScope['source'], offer_kind: AlertScope['offer_kind']) => scopes.some((scope) => scope.source === source && scope.offer_kind === offer_kind)
  const toggle = (source: AlertScope['source'], offer_kind: AlertScope['offer_kind']) => setScopes((current) => {
    if (source === 'steam') return [{ source: 'steam', offer_kind: 'official' }, ...current.filter((scope) => scope.source !== 'steam')]
    return has(source, offer_kind) ? current.filter((scope) => !(scope.source === source && scope.offer_kind === offer_kind)) : [...current, { source, offer_kind }]
  })

  useEffect(() => {
    const close = (event: KeyboardEvent) => event.key === 'Escape' && onClose()
    window.addEventListener('keydown', close)
    return () => window.removeEventListener('keydown', close)
  }, [onClose])

  return <div className="modal-backdrop" role="presentation" onMouseDown={onClose}>
    <section className="panel alert-modal" role="dialog" aria-modal="true" aria-label="Настройка алерта" onMouseDown={(event) => event.stopPropagation()}>
      <div className="modal-head"><div><h3>Следить за ценой</h3><p className="muted">{favorite.game_name}</p></div><button type="button" className="btn ghost sm" onClick={onClose}>×</button></div>
      <label className="field-label">Целевая цена, ₽ <input type="number" min="0" step="1" value={target} onChange={(event) => setTarget(event.target.value)} placeholder="Например, 999" /></label>
      <p className="muted">Уведомим, когда подходящее предложение будет по этой цене или дешевле.</p>
      <div className="scope-group"><strong>Steam</strong><label className="scope-check"><input type="checkbox" checked readOnly /> {KIND_LABEL.official}</label></div>
      {(['plati', 'ggsel'] as const).map((source) => <div className="scope-group" key={source}><strong>{source === 'plati' ? 'Plati.Market' : 'GGsel'}</strong><div className="scope-row">{MARKET_KINDS.map((kind) => <label className="scope-check" key={kind}><input type="checkbox" checked={has(source, kind)} onChange={() => toggle(source, kind)} /> {KIND_LABEL[kind]}</label>)}</div></div>)}
      <div className="actions"><button type="button" className="btn ghost" onClick={onClose}>Отмена</button><button type="button" className="btn primary" disabled={saving} onClick={async () => { setSaving(true); try { await onSave({ target_value: target.trim() === '' ? null : Number(target), scopes }) } finally { setSaving(false) } }}>{saving ? 'Сохраняем…' : 'Сохранить'}</button></div>
    </section>
  </div>
}
