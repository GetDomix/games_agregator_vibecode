import { useEffect, useRef, useState } from 'react'
import { IconClose } from '../../shared/ui/icons'
import type { AlertPrefs, AlertScope, ConditionType, FavoriteItem } from './types'
import { api } from '../../shared/api/client'
import type { User } from '../../shared/api/client'
import { useLocale } from '../../shared/i18n/LocaleProvider'

type Props = {
  favorite: Pick<FavoriteItem, 'appid' | 'game_name' | 'header_image' | 'alert' | 'suggested_target' | 'observed_lows'>
  initialPrefs?: AlertPrefs | null
  onClose: () => void
  onSave: (value: { condition_type: ConditionType; target_value: number | null; scopes: AlertScope[] }) => Promise<void>
  onSavedPrefs: (prefs: AlertPrefs) => void
  onRemoveAlert?: () => Promise<void>
  onRearmAlert?: () => Promise<void>
}

const DEFAULT_SCOPES: AlertScope[] = [{ source: 'steam', offer_kind: 'official' }]
const MARKETPLACES = ['plati', 'ggsel'] as const
const MARKET_KINDS: Exclude<AlertScope['offer_kind'], 'official'>[] = ['key', 'gift', 'account', 'rent']
const MARKET_SOURCES = [...MARKETPLACES]

function initialScopes(favorite: Props['favorite'], prefs?: AlertPrefs | null): AlertScope[] {
  if (favorite.alert?.scopes?.length) return favorite.alert.scopes
  const kinds = prefs?.kinds?.length ? prefs.kinds : ['key']
  return [
    ...DEFAULT_SCOPES,
    ...MARKET_SOURCES.flatMap((source) => kinds.map((offer_kind) => ({ source, offer_kind } as AlertScope))),
  ]
}

export function AlertSettingsModal({ favorite, initialPrefs, onClose, onSave, onSavedPrefs, onRemoveAlert, onRearmAlert }: Props) {
  const { currency, currencyReady, fromRub, toRub, tr } = useLocale()
  const initialCondition = (favorite.alert?.condition_type as ConditionType | undefined) ?? 'target_price'
  const [condition, setCondition] = useState<ConditionType>(initialCondition)
  // Price and percent are separate drafts: a 30% discount must never become 30 RUB.
  const [priceTarget, setPriceTarget] = useState(() => initialCondition === 'target_price' && favorite.alert?.target_value != null ? String(favorite.alert.target_value) : '')
  const [discountTarget, setDiscountTarget] = useState(() => initialCondition === 'discount_percent' && favorite.alert?.target_value != null ? String(favorite.alert.target_value) : '')
  const [scopes, setScopes] = useState<AlertScope[]>(() => initialScopes(favorite, initialPrefs))
  const [saving, setSaving] = useState(false)
  const [removing, setRemoving] = useState(false)
  const [rearming, setRearming] = useState(false)
  const [submitError, setSubmitError] = useState('')
  const [valueTouched, setValueTouched] = useState(false)
  const dialogRef = useRef<HTMLElement>(null)
  const returnFocus = useRef<HTMLElement | null>(document.activeElement instanceof HTMLElement ? document.activeElement : null)
  const busy = saving || removing || rearming
  const busyRef = useRef(busy)
  const onCloseRef = useRef(onClose)
  busyRef.current = busy
  onCloseRef.current = onClose

  useEffect(() => {
    const dialog = dialogRef.current
    const origin = returnFocus.current
    dialog?.querySelector<HTMLElement>('input[name="alert-condition"]:checked, #alert-target, #alert-discount')?.focus()
    const keydown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !busyRef.current) onCloseRef.current()
      if (event.key !== 'Tab' || !dialog) return
      const controls = Array.from(dialog.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), summary, select:not([disabled]), [href]')).filter((node) => node.offsetParent !== null)
      if (!controls.length) return
      const index = controls.indexOf(document.activeElement as HTMLElement)
      if (event.shiftKey && index <= 0) { event.preventDefault(); controls.at(-1)?.focus() }
      if (!event.shiftKey && index === controls.length - 1) { event.preventDefault(); controls[0].focus() }
    }
    window.addEventListener('keydown', keydown)
    return () => { window.removeEventListener('keydown', keydown); origin?.focus() }
  // Deliberately mount-scoped: toggling saving/removing must not return focus.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (condition !== 'target_price' || !currencyReady || favorite.alert?.target_value == null) return
    if (initialCondition === 'target_price') setPriceTarget(String(currency === 'RUB' || currency === 'KZT' ? Math.round(fromRub(favorite.alert.target_value)) : Math.round(fromRub(favorite.alert.target_value) * 100) / 100))
  }, [condition, currency, currencyReady, favorite.alert?.target_value, fromRub, initialCondition])

  const effectiveScopes = condition === 'discount_percent' ? DEFAULT_SCOPES : scopes
  const activeTarget = condition === 'target_price' ? priceTarget : discountTarget
  const parsed = Number(activeTarget)
  const needsValue = condition !== 'new_low'
  const valueInvalid = needsValue && (!activeTarget.trim() || !Number.isFinite(parsed) || parsed <= 0 || (condition === 'discount_percent' && (!Number.isInteger(parsed) || parsed > 100)))
  const canSave = !busy && !valueInvalid && effectiveScopes.length > 0
  const has = (source: AlertScope['source'], offer_kind: AlertScope['offer_kind']) => scopes.some((scope) => scope.source === source && scope.offer_kind === offer_kind)
  const toggle = (source: AlertScope['source'], offer_kind: AlertScope['offer_kind']) => setScopes((current) => has(source, offer_kind) ? current.filter((scope) => !(scope.source === source && scope.offer_kind === offer_kind)) : [...current, { source, offer_kind }])
  const toggleMarketplace = (source: typeof MARKETPLACES[number]) => setScopes((current) => {
    const allSelected = MARKET_KINDS.every((kind) => current.some((scope) => scope.source === source && scope.offer_kind === kind))
    const withoutSource = current.filter((scope) => scope.source !== source)
    return allSelected ? withoutSource : [...withoutSource, ...MARKET_KINDS.map((offer_kind) => ({ source, offer_kind } as AlertScope))]
  })
  const toggleKindAcrossMarkets = (offerKind: typeof MARKET_KINDS[number]) => setScopes((current) => {
    const allSelected = MARKET_SOURCES.every((source) => current.some((scope) => scope.source === source && scope.offer_kind === offerKind))
    const withoutKind = current.filter((scope) => !(MARKET_SOURCES.includes(scope.source as typeof MARKET_SOURCES[number]) && scope.offer_kind === offerKind))
    return allSelected ? withoutKind : [...withoutKind, ...MARKET_SOURCES.map((source) => ({ source, offer_kind: offerKind } as AlertScope))]
  })
  const sourceLabel = (source: string) => source === 'steam' ? 'Steam' : source === 'plati' ? 'Plati.Market' : source === 'ggsel' ? 'GGsel' : source
  const offerKindLabel = (source: string, kind: string) => source === 'steam'
    ? tr('официальная цена', 'official price')
    : ({ key: tr('Ключ', 'Key'), gift: tr('Гифт', 'Gift'), account: tr('Аккаунт', 'Account'), rent: tr('Аренда', 'Rental') } as Record<string, string>)[kind] ?? kind
  const selectedSources = [...new Set(effectiveScopes.map((scope) => sourceLabel(scope.source)))].join(', ')
  const selectedObservedLows = effectiveScopes.flatMap((scope) => {
    const low = favorite.observed_lows?.find((item) => item.source === scope.source && item.offer_kind === scope.offer_kind)
    return low ? [low] : []
  }).sort((a, b) => a.price_rub - b.price_rub)

  const save = async () => {
    if (!canSave) return
    setSaving(true); setSubmitError('')
    try {
      const value = condition === 'new_low' ? null : condition === 'discount_percent' ? parsed : Math.round(toRub(parsed) * 100) / 100
      await onSave({ condition_type: condition, target_value: value, scopes: effectiveScopes })
      const kinds = MARKET_KINDS.filter((kind) => scopes.some((scope) => scope.offer_kind === kind))
      const prefs: AlertPrefs = { mode: 'advanced', kinds }
      try { onSavedPrefs((await api<User>('/api/auth/me', { method: 'PATCH', body: JSON.stringify({ alert_prefs: prefs }) })).alert_prefs ?? prefs) } catch { onSavedPrefs(prefs) }
      onClose()
    } catch (error) { setSubmitError(error instanceof Error ? error.message : tr('Не удалось сохранить алерт.', 'Could not save the alert.')) } finally { setSaving(false) }
  }
  const useSuggestion = () => {
    const value = favorite.suggested_target?.value_rub
    if (value != null) { setCondition('target_price'); setPriceTarget(String(currency === 'RUB' || currency === 'KZT' ? Math.round(fromRub(value)) : Math.round(fromRub(value) * 100) / 100)); setSubmitError('') }
  }
  const remove = async () => {
    if (!onRemoveAlert) return
    setRemoving(true); setSubmitError('')
    try { await onRemoveAlert() } catch (error) { setSubmitError(error instanceof Error ? error.message : tr('Не удалось удалить алерт.', 'Could not delete the alert.')); setRemoving(false) }
  }
  const rearm = async () => {
    if (!onRearmAlert) return
    setRearming(true); setSubmitError('')
    try { await onRearmAlert(); onClose() } catch (error) { setSubmitError(error instanceof Error ? error.message : tr('Не удалось включить сигнал снова.', 'Could not reactivate the alert.')); setRearming(false) }
  }

  return <div className="modal-backdrop" role="presentation" onMouseDown={() => !busy && onClose()}>
    <section ref={dialogRef} className="panel alert-modal" role="dialog" aria-modal="true" aria-labelledby="alert-title" onMouseDown={(event) => event.stopPropagation()}>
      <div className="modal-head alert-modal-head"><div className="alert-game">{favorite.header_image ? <img src={favorite.header_image} alt="" /> : null}<div><p className="eyebrow">{tr('Ценовой радар', 'Price radar')}</p><h3 id="alert-title">{favorite.game_name}</h3></div></div><button type="button" className="modal-close" onClick={onClose} disabled={busy} aria-label={tr('Закрыть', 'Close')}><IconClose size={16} /></button></div>
      <fieldset className="alert-conditions"><legend>{tr('Условие', 'Condition')}</legend>
        {([['target_price', tr('Своя цена', 'Custom price')], ['discount_percent', tr('Скидка Steam', 'Steam discount')], ['new_low', tr('Новый минимум', 'New observed low')]] as const).map(([value, label]) => <label key={value}><input type="radio" name="alert-condition" checked={condition === value} onChange={() => { setCondition(value); setValueTouched(false); setSubmitError('') }} /><span className="alert-condition-mark" aria-hidden="true" /><span>{label}</span></label>)}
      </fieldset>
      {condition === 'target_price' && <div className="alert-target-card"><label className="field-label" htmlFor="alert-target">{tr('Цена не выше', 'Price at most')}</label><div className="alert-price-input"><input id="alert-target" type="number" min={currency === 'RUB' || currency === 'KZT' ? '1' : '0.01'} step={currency === 'RUB' || currency === 'KZT' ? '1' : '0.01'} value={priceTarget} disabled={!currencyReady} onChange={(event) => { setPriceTarget(event.target.value); setValueTouched(true) }} onBlur={() => setValueTouched(true)} /><span>{currency}</span></div>{favorite.suggested_target && <p>{tr('10% ниже текущей сохранённой цены', '10% below current stored price')}: {currency === 'RUB' || currency === 'KZT' ? Math.round(fromRub(favorite.suggested_target.value_rub)) : Math.round(fromRub(favorite.suggested_target.value_rub) * 100) / 100} {currency} · {sourceLabel(favorite.suggested_target.source)} · {offerKindLabel(favorite.suggested_target.source, favorite.suggested_target.offer_kind)} <button className="btn link sm" type="button" onClick={useSuggestion}>{tr('Подставить', 'Use')}</button></p>}</div>}
      {condition === 'discount_percent' && <div className="alert-target-card"><label className="field-label" htmlFor="alert-discount">{tr('Скидка не меньше, %', 'Discount at least, %')}</label><div className="alert-price-input"><input id="alert-discount" type="number" min="1" max="100" step="1" value={discountTarget} onChange={(event) => { setDiscountTarget(event.target.value); setValueTouched(true) }} /><span>%</span></div><p>{tr('Только официальная цена Steam.', 'Official Steam price only.')}</p></div>}
      {condition === 'new_low' && (
        <div className="alert-low-baseline">
          <div>
            <strong>{tr('Минимумы наблюдений', 'Observed lows')}</strong>
            <small>{tr('Порог не вводится: сигнал сработает только ниже сохранённого минимума своего типа.', 'No threshold input: the alert fires only below the stored low for that offer type.')}</small>
          </div>
          {selectedObservedLows.length ? (
            <div className="alert-low-list">
              {selectedObservedLows.slice(0, 3).map((low) => {
                const display = fromRub(low.price_rub)
                const formatted = currency === 'RUB' || currency === 'KZT'
                  ? Math.round(display)
                  : Math.round(display * 100) / 100

                return (
                  <div key={`${low.source}:${low.offer_kind}`}>
                    <span>{sourceLabel(low.source)} · {offerKindLabel(low.source, low.offer_kind)}</span>
                    <b>
                      {low.price_rub > 0
                        ? `${formatted} ${currency}`
                        : tr('Бесплатно · минимум не применяется', 'Free · low does not apply')}
                    </b>
                  </div>
                )
              })}
              {selectedObservedLows.length > 3 && (
                <small>{tr(`Ещё ${selectedObservedLows.length - 3} минимумов в выбранных источниках`, `${selectedObservedLows.length - 3} more lows in selected sources`)}</small>
              )}
            </div>
          ) : (
            <p>{tr('Первый сохранённый замер станет точкой отсчёта.', 'The first stored observation will become the baseline.')}</p>
          )}
        </div>
      )}
      {condition !== 'discount_percent' && <details className="alert-sources"><summary><span>{tr('Дополнительные настройки', 'Additional settings')}</span><small>{effectiveScopes.length} · {selectedSources}</small></summary><p>{tr('Площадки и типы предложений', 'Marketplaces and offer types')}</p><div className="alert-quick-picks"><div><strong>{tr('Сразу на обеих площадках', 'Across both marketplaces')}</strong><small>{tr('Быстрый выбор по типу', 'Quick selection by type')}</small></div><div className="alert-quick-grid">{MARKET_KINDS.map((kind) => { const selected = MARKET_SOURCES.filter((source) => has(source, kind)).length; return <button type="button" key={kind} className={`alert-quick-kind ${selected === MARKET_SOURCES.length ? 'active' : ''} ${selected > 0 && selected < MARKET_SOURCES.length ? 'mixed' : ''}`} aria-pressed={selected === MARKET_SOURCES.length} onClick={() => toggleKindAcrossMarkets(kind)}>{offerKindLabel('plati', kind)}<span>{selected}/{MARKET_SOURCES.length}</span></button> })}</div></div><div className="alert-scope-group"><div className="alert-scope-group-head"><strong>Steam</strong></div><div className="alert-scope-options"><label><input type="checkbox" checked={has('steam', 'official')} onChange={() => toggle('steam', 'official')} /> {offerKindLabel('steam', 'official')}</label></div></div>{MARKETPLACES.map((source) => { const selected = MARKET_KINDS.filter((kind) => has(source, kind)).length; return <div className="alert-scope-group" key={source}><div className="alert-scope-group-head"><strong>{sourceLabel(source)}</strong><button type="button" className="btn link sm" onClick={() => toggleMarketplace(source)}>{selected === MARKET_KINDS.length ? tr('Снять все', 'Clear all') : tr('Выбрать все', 'Select all')}</button></div><div className="alert-scope-options alert-scope-options--kinds">{MARKET_KINDS.map((kind) => <label key={`${source}:${kind}`}><input type="checkbox" checked={has(source, kind)} onChange={() => toggle(source, kind)} /> {offerKindLabel(source, kind)}</label>)}</div></div> })}</details>}
      {valueInvalid && (valueTouched || activeTarget.trim() !== '') && <p className="alert-validation">{tr('Введите корректное значение.', 'Enter a valid value.')}</p>}{effectiveScopes.length === 0 && <p className="alert-validation">{tr('Выберите хотя бы один источник.', 'Select at least one source.')}</p>}{submitError && <p className="alert-validation" role="alert">{submitError}</p>}
      <div className="alert-modal-actions"><div>{onRemoveAlert && favorite.alert && <button type="button" className="btn link danger" disabled={busy} onClick={remove}>{removing ? tr('Удаляем…', 'Removing…') : tr('Удалить алерт', 'Delete alert')}</button>}</div><div className="actions">{favorite.alert?.status === 'triggered' && onRearmAlert && <button type="button" className="btn ghost" disabled={busy} onClick={rearm}>{rearming ? tr('Включаем…', 'Reactivating…') : tr('Включить снова', 'Reactivate')}</button>}<button type="button" className="btn ghost" disabled={busy} onClick={onClose}>{tr('Отмена', 'Cancel')}</button><button type="button" className="btn primary" disabled={!canSave} onClick={save}>{saving ? tr('Сохраняем…', 'Saving…') : tr('Сохранить алерт', 'Save alert')}</button></div></div>
    </section>
  </div>
}
