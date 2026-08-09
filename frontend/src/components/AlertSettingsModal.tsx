import { useEffect, useMemo, useRef, useState } from 'react'
import { IconClose } from '../icons'
import type { AlertPrefs, AlertScope, FavoriteItem } from '../watchlist'
import { api } from '../api'
import type { User } from '../api'
import { useLocale } from '../locale'

type Props = {
  favorite: Pick<FavoriteItem, 'appid' | 'game_name' | 'header_image' | 'alert'>
  initialPrefs?: AlertPrefs | null
  onClose: () => void
  onSave: (value: { target_value: number | null; scopes: AlertScope[] }) => Promise<void>
  onSavedPrefs: (prefs: AlertPrefs) => void
  onRemoveAlert?: () => Promise<void>
}

const MARKET_KINDS: Exclude<AlertScope['offer_kind'], 'official'>[] = ['key', 'gift', 'account', 'rent']
// New marketplaces are registered once here; global type shortcuts and per-source
// controls automatically include them.
const MARKETPLACES = [
  { source: 'plati', label: 'Plati.Market' },
  { source: 'ggsel', label: 'GGsel' },
] as const satisfies ReadonlyArray<{ source: Extract<AlertScope['source'], 'plati' | 'ggsel'>; label: string }>
const MARKET_SOURCES = MARKETPLACES.map(({ source }) => source)

function makeInitialScopes(favorite: Props['favorite'], prefs?: AlertPrefs | null): AlertScope[] {
  if (favorite.alert?.scopes?.length) return favorite.alert.scopes
  const kinds = prefs?.kinds?.length ? prefs.kinds : ['key']
  return [
    { source: 'steam', offer_kind: 'official' },
    ...MARKET_SOURCES.flatMap((source) => kinds.map((offer_kind) => ({ source, offer_kind } as AlertScope))),
  ]
}

export function AlertSettingsModal({ favorite, initialPrefs, onClose, onSave, onSavedPrefs, onRemoveAlert }: Props) {
  const { currency, currencyReady, fromRub, toRub, tr } = useLocale()
  const initialRub = favorite.alert?.target_value ?? null
  const targetRubRef = useRef<number | null>(initialRub)
  const [target, setTarget] = useState('')
  const [scopes, setScopes] = useState<AlertScope[]>(() => makeInitialScopes(favorite, initialPrefs))
  const [saving, setSaving] = useState(false)
  const [removing, setRemoving] = useState(false)
  const [submitError, setSubmitError] = useState('')
  const busy = saving || removing

  const kindLabel = useMemo<Record<AlertScope['offer_kind'], string>>(() => ({
    official: tr('Официальная цена', 'Official price'),
    key: tr('Ключ', 'Key'),
    gift: tr('Гифт', 'Gift'),
    account: tr('Аккаунт', 'Account'),
    rent: tr('Аренда', 'Rental'),
  }), [tr])

  useEffect(() => {
    if (!currencyReady) {
      setTarget('')
      return
    }
    if (targetRubRef.current == null) return
    const converted = fromRub(targetRubRef.current)
    setTarget((currency === 'RUB' || currency === 'KZT' ? Math.round(converted) : Math.round(converted * 100) / 100).toString())
  }, [currency, currencyReady, fromRub])

  useEffect(() => {
    const close = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !busy) onClose()
    }
    window.addEventListener('keydown', close)
    return () => window.removeEventListener('keydown', close)
  }, [busy, onClose])

  const has = (source: AlertScope['source'], offerKind: AlertScope['offer_kind']) =>
    scopes.some((scope) => scope.source === source && scope.offer_kind === offerKind)

  const toggle = (source: AlertScope['source'], offerKind: AlertScope['offer_kind']) => {
    setSubmitError('')
    setScopes((current) => current.some((scope) => scope.source === source && scope.offer_kind === offerKind)
      ? current.filter((scope) => !(scope.source === source && scope.offer_kind === offerKind))
      : [...current, { source, offer_kind: offerKind }])
  }

  const toggleMarketplace = (source: 'plati' | 'ggsel') => {
    setSubmitError('')
    const allSelected = MARKET_KINDS.every((kind) => has(source, kind))
    setScopes((current) => {
      const withoutSource = current.filter((scope) => scope.source !== source)
      return allSelected
        ? withoutSource
        : [...withoutSource, ...MARKET_KINDS.map((offer_kind) => ({ source, offer_kind } as AlertScope))]
    })
  }

  const toggleKindAcrossMarkets = (offerKind: Exclude<AlertScope['offer_kind'], 'official'>) => {
    setSubmitError('')
    const allSelected = MARKET_SOURCES.every((source) => has(source, offerKind))
    setScopes((current) => {
      const withoutKind = current.filter((scope) => !(MARKET_SOURCES.includes(scope.source as typeof MARKET_SOURCES[number]) && scope.offer_kind === offerKind))
      return allSelected
        ? withoutKind
        : [...withoutKind, ...MARKET_SOURCES.map((source) => ({ source, offer_kind: offerKind } as AlertScope))]
    })
  }

  const parsedTarget = Number(target)
  const targetInvalid = !currencyReady || target.trim() === '' || !Number.isFinite(parsedTarget) || parsedTarget < 0
  const canSave = !busy && !targetInvalid && scopes.length > 0

  const save = async () => {
    if (!canSave) return
    setSaving(true)
    setSubmitError('')
    try {
      const targetRub = Math.round(toRub(parsedTarget) * 100) / 100
      if (!Number.isFinite(targetRub)) throw new Error(tr('Курс валюты ещё загружается.', 'The exchange rate is still loading.'))
      const kinds = MARKET_KINDS.filter((kind) => scopes.some((scope) => scope.offer_kind === kind))
      const prefs: AlertPrefs = { mode: 'advanced', kinds }
      await onSave({ target_value: targetRub, scopes })
      try {
        const updated = await api<User>('/api/auth/me', { method: 'PATCH', body: JSON.stringify({ alert_prefs: prefs }) })
        onSavedPrefs(updated.alert_prefs ?? prefs)
      } catch {
        onSavedPrefs(prefs)
      }
      onClose()
    } catch (error) {
      setSubmitError(error instanceof Error ? error.message : tr('Не удалось сохранить алерт.', 'Could not save the alert.'))
    } finally {
      setSaving(false)
    }
  }

  const remove = async () => {
    if (!onRemoveAlert) return
    setRemoving(true)
    setSubmitError('')
    try {
      await onRemoveAlert()
    } catch (error) {
      setSubmitError(error instanceof Error ? error.message : tr('Не удалось удалить алерт.', 'Could not delete the alert.'))
      setRemoving(false)
    }
  }

  return (
    <div className="modal-backdrop" role="presentation" onMouseDown={() => !busy && onClose()}>
      <section className="panel alert-modal" role="dialog" aria-modal="true" aria-labelledby="alert-title" onMouseDown={(event) => event.stopPropagation()}>
        <div className="modal-head alert-modal-head">
          <div className="alert-game">
            {favorite.header_image ? <img src={favorite.header_image} alt="" /> : <span className="alert-game-placeholder" aria-hidden="true" />}
            <div>
              <p className="eyebrow">{tr('Ценовой радар', 'Price radar')}</p>
              <h3 id="alert-title">{favorite.game_name}</h3>
            </div>
          </div>
          <button type="button" className="modal-close" onClick={onClose} disabled={busy} aria-label={tr('Закрыть', 'Close')}><IconClose size={16} /></button>
        </div>

        <div className="alert-target-card">
          <label className="field-label" htmlFor="alert-target">{tr('Сообщить, когда цена станет не выше', 'Notify me when the price is at most')}</label>
          <div className="alert-price-input">
            <input
              id="alert-target"
              type="number"
              min="0"
              step={currency === 'RUB' || currency === 'KZT' ? '1' : '0.01'}
              value={target}
              disabled={!currencyReady}
              onChange={(event) => {
                setTarget(event.target.value)
                setSubmitError('')
                const value = Number(event.target.value)
                if (event.target.value.trim() !== '' && Number.isFinite(value)) targetRubRef.current = toRub(value)
              }}
              placeholder={currencyReady ? tr('Например, 999', 'For example, 19.99') : tr('Загружаем курс…', 'Loading rate…')}
              autoFocus
            />
            <span>{currency}</span>
          </div>
          <p>{tr('Порог хранится в рублях, поэтому при смене валюты сумма пересчитается автоматически.', 'The threshold is stored in rubles, so changing currency converts it automatically.')}</p>
        </div>

        <fieldset className="alert-sources">
          <legend>{tr('Какие предложения отслеживать', 'Offers to track')}</legend>
          <div className="alert-quick-picks">
            <div>
              <strong>{tr('Быстрый выбор по типу', 'Quick selection by type')}</strong>
              <small>{tr('Одним нажатием для всех маркетплейсов', 'One tap across every marketplace')}</small>
            </div>
            <div className="alert-quick-grid">
              {MARKET_KINDS.map((kind) => {
                const selected = MARKET_SOURCES.filter((source) => has(source, kind)).length
                return (
                  <button
                    type="button"
                    key={kind}
                    className={`alert-quick-kind ${selected === MARKET_SOURCES.length ? 'active' : ''} ${selected > 0 && selected < MARKET_SOURCES.length ? 'mixed' : ''}`}
                    aria-pressed={selected === MARKET_SOURCES.length}
                    onClick={() => toggleKindAcrossMarkets(kind)}
                  >
                    {kindLabel[kind]}
                    <span>{selected}/{MARKET_SOURCES.length}</span>
                  </button>
                )
              })}
            </div>
          </div>
          <label className="alert-source-card steam-source">
            <input type="checkbox" checked={has('steam', 'official')} onChange={() => toggle('steam', 'official')} />
            <span className="alert-source-check" aria-hidden="true" />
            <span><strong>Steam RU</strong><small>{tr('Официальная цена в магазине', 'Official store price')}</small></span>
          </label>

          {MARKETPLACES.map(({ source, label: title }) => {
            const selectedCount = MARKET_KINDS.filter((kind) => has(source, kind)).length
            return (
              <div className="alert-market-card" key={source}>
                <div className="alert-market-head">
                  <div><strong>{title}</strong><small>{tr(`${selectedCount} из ${MARKET_KINDS.length} типов`, `${selectedCount} of ${MARKET_KINDS.length} types`)}</small></div>
                  <button type="button" className="btn link sm" onClick={() => toggleMarketplace(source)}>
                    {selectedCount === MARKET_KINDS.length ? tr('Снять все', 'Clear all') : tr('Выбрать все', 'Select all')}
                  </button>
                </div>
                <div className="alert-kind-grid">
                  {MARKET_KINDS.map((kind) => (
                    <label className="kind-check" key={kind}>
                      <input type="checkbox" checked={has(source, kind)} onChange={() => toggle(source, kind)} />
                      <span>{kindLabel[kind]}</span>
                    </label>
                  ))}
                </div>
              </div>
            )
          })}
        </fieldset>

        {scopes.length === 0 && <p className="alert-validation">{tr('Выберите хотя бы один источник.', 'Select at least one source.')}</p>}
        {target.trim() !== '' && targetInvalid && currencyReady && <p className="alert-validation">{tr('Введите корректную цену от нуля.', 'Enter a valid price of zero or more.')}</p>}
        {submitError && <p className="alert-validation" role="alert">{submitError}</p>}

        <div className="alert-modal-actions">
          <div>{onRemoveAlert && favorite.alert && <button type="button" className="btn link danger" disabled={busy} onClick={remove}>{removing ? tr('Удаляем…', 'Removing…') : tr('Удалить алерт', 'Delete alert')}</button>}</div>
          <div className="actions">
            <button type="button" className="btn ghost" disabled={busy} onClick={onClose}>{tr('Отмена', 'Cancel')}</button>
            <button type="button" className="btn primary" disabled={!canSave} onClick={save}>{saving ? tr('Сохраняем…', 'Saving…') : tr('Сохранить алерт', 'Save alert')}</button>
          </div>
        </div>
      </section>
    </div>
  )
}
