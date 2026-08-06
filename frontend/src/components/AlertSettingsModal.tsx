import { useEffect, useState } from 'react'
import { IconClose } from '../icons'
import type { AlertPrefs, AlertScope, FavoriteItem } from '../watchlist'
import { api } from '../api'
import type { User } from '../api'

type Props = {
  favorite: Pick<FavoriteItem, 'appid' | 'game_name' | 'header_image' | 'alert'>
  initialPrefs?: AlertPrefs | null
  onClose: () => void
  onSave: (value: { target_value: number | null; scopes: AlertScope[] }) => Promise<void>
  onSavedPrefs: (prefs: AlertPrefs) => void
  onRemoveAlert?: () => Promise<void>
}
const MARKET_KINDS: Exclude<AlertScope['offer_kind'], 'official'>[] = ['key', 'gift', 'account', 'rent']
const KIND_LABEL: Record<AlertScope['offer_kind'], string> = { official: 'Официальная версия', key: 'Ключ', gift: 'Гифт', account: 'Аккаунт', rent: 'Аренда' }

export function AlertSettingsModal({ favorite, initialPrefs, onClose, onSave, onSavedPrefs, onRemoveAlert }: Props) {
  const [target, setTarget] = useState(favorite.alert?.target_value?.toString() || '')
  const [mode, setMode] = useState<AlertPrefs['mode']>(initialPrefs?.mode ?? 'simple')
  const [kinds, setKinds] = useState<AlertPrefs['kinds']>(initialPrefs?.kinds ?? [])
  const [scopes, setScopes] = useState<AlertScope[]>(favorite.alert?.scopes ?? [])
  const [saving, setSaving] = useState(false)
  const [removing, setRemoving] = useState(false)
  const has = (source: AlertScope['source'], offer_kind: AlertScope['offer_kind']) => scopes.some((scope) => scope.source === source && scope.offer_kind === offer_kind)
  const toggle = (source: AlertScope['source'], offer_kind: AlertScope['offer_kind']) => setScopes((current) => {
    const exists = current.some((scope) => scope.source === source && scope.offer_kind === offer_kind)
    return exists ? current.filter((scope) => !(scope.source === source && scope.offer_kind === offer_kind)) : [...current, { source, offer_kind }]
  })
  const parsedTarget = Number(target)
  const targetInvalid = target.trim() === '' || Number.isNaN(parsedTarget) || parsedTarget < 0
  const selectionEmpty = mode === 'simple' ? kinds.length === 0 : scopes.length === 0
  const canSave = !saving && !targetInvalid && !selectionEmpty
  const save = async (targetValue: number) => {
    setSaving(true)
    try {
      const prefs: AlertPrefs = { mode, kinds }
      try {
        const updated = await api<User>('/api/auth/me', { method: 'PATCH', body: JSON.stringify({ alert_prefs: prefs }) })
        onSavedPrefs(updated && updated.alert_prefs ? updated.alert_prefs : prefs)
      } catch {
        onSavedPrefs(prefs)
      }
      const scopesToSave = mode === 'simple'
        ? [{ source: 'steam', offer_kind: 'official' } as AlertScope, ...(['plati', 'ggsel'] as const).flatMap((source) => kinds.map((kind) => ({ source, offer_kind: kind } as AlertScope)))]
        : scopes
      await onSave({ target_value: targetValue, scopes: scopesToSave })
    } finally {
      setSaving(false)
    }
  }

  useEffect(() => {
    const close = (event: KeyboardEvent) => event.key === 'Escape' && onClose()
    window.addEventListener('keydown', close)
    return () => window.removeEventListener('keydown', close)
  }, [onClose])

  return <div className="modal-backdrop" role="presentation" onMouseDown={onClose}>
    <section className="panel alert-modal" role="dialog" aria-modal="true" aria-label="Настройка алерта" onMouseDown={(event) => event.stopPropagation()}>
      <div className="modal-head"><div><h3>Следить за ценой</h3><p className="muted">{favorite.game_name}</p></div><button type="button" className="modal-close" onClick={onClose} aria-label="Закрыть"><IconClose size={16} /></button></div>
      <label className="field-label">Целевая цена, ₽ <input type="number" min="0" step="1" value={target} onChange={(event) => setTarget(event.target.value)} placeholder="Например, 999" /></label>
      <p className="muted">Уведомим, когда подходящее предложение будет по этой цене или дешевле.</p>
      {target.trim() === '' && <p className="alert-hint">Укажи целевую цену — без неё алерт не имеет смысла.</p>}
      <div className="alert-mode-switch" role="tablist" aria-label="Режим настройки">
        <button type="button" className={mode === 'simple' ? 'active' : ''} onClick={() => setMode('simple')}>Простой</button>
        <button type="button" className={mode === 'advanced' ? 'active' : ''} onClick={() => setMode('advanced')}>Подробный</button>
      </div>
      <p className="alert-hint">{mode === 'simple' ? 'Один набор типов товаров для всех площадок сразу.' : 'Точечный выбор: какая площадка и какой тип товара.'}</p>
      {mode === 'simple' ? <>
        <div className="scope-group"><strong>Типы товаров</strong><div className="scope-row">{MARKET_KINDS.map((kind) => <label className="kind-check" key={kind}><input type="checkbox" checked={kinds.includes(kind)} onChange={() => setKinds((current) => current.includes(kind) ? current.filter((item) => item !== kind) : [...current, kind])} /> {KIND_LABEL[kind]}</label>)}</div></div>
        <p className="muted">Выбранные типы применятся ко всем площадкам: Steam (официально), Plati и GGsel.</p>
        {kinds.length === 0 && <p className="alert-hint">Отметь хотя бы один тип товара.</p>}
      </> : <>
        <div className="scope-group"><strong>Steam</strong><label className="scope-check"><input type="checkbox" checked={has('steam', 'official')} onChange={() => toggle('steam', 'official')} /> {KIND_LABEL.official}</label></div>
        {(['plati', 'ggsel'] as const).map((source) => <div className="scope-group" key={source}><strong>{source === 'plati' ? 'Plati.Market' : 'GGsel'}</strong><div className="scope-row">{MARKET_KINDS.map((kind) => <label className="scope-check" key={kind}><input type="checkbox" checked={has(source, kind)} onChange={() => toggle(source, kind)} /> {KIND_LABEL[kind]}</label>)}</div></div>)}
        {scopes.length === 0 && <p className="alert-hint">Отметь хотя бы одну площадку и тип товара.</p>}
      </>}
      <div className="actions">{onRemoveAlert && favorite.alert && <button type="button" className="btn ghost" disabled={removing} onClick={async () => { setRemoving(true); try { await onRemoveAlert() } finally { setRemoving(false) } }}>{removing ? 'Удаляем…' : 'Удалить алерт'}</button>}<button type="button" className="btn ghost" onClick={onClose}>Отмена</button><button type="button" className="btn primary" disabled={!canSave} onClick={() => save(parsedTarget)}>{saving ? 'Сохраняем…' : 'Сохранить'}</button></div>
    </section>
  </div>
}
