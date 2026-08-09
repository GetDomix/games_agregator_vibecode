/* oxlint-disable react/only-export-components */
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'

export type Locale = 'ru' | 'en'
export type Currency = 'RUB' | 'USD' | 'EUR' | 'KZT' | 'TRY'

const LOCALE_STORAGE_KEY = 'igroscan_locale_v1'
const CURRENCY_STORAGE_KEY = 'igroscan_currency_v1'
const RATES_STORAGE_KEY = 'igroscan_currency_rates_v1'
export const CURRENCIES: Currency[] = ['RUB', 'USD', 'EUR', 'KZT', 'TRY']
const CIS_LANGUAGE_CODES = new Set(['ru', 'uk', 'be', 'kk', 'hy', 'az', 'ka', 'ky', 'tg', 'tk', 'uz'])
const CIS_TIMEZONES = new Set([
  'Europe/Kaliningrad', 'Europe/Kirov', 'Europe/Minsk', 'Europe/Moscow', 'Europe/Samara',
  'Europe/Saratov', 'Europe/Simferopol', 'Europe/Ulyanovsk', 'Europe/Volgograd',
  'Asia/Almaty', 'Asia/Astana', 'Asia/Aqtau', 'Asia/Aqtobe', 'Asia/Ashgabat', 'Asia/Atyrau', 'Asia/Baku',
  'Asia/Bishkek', 'Asia/Dushanbe', 'Asia/Oral', 'Asia/Qostanay', 'Asia/Qyzylorda',
  'Asia/Samarkand', 'Asia/Tashkent', 'Asia/Tbilisi', 'Asia/Yerevan',
  // Russian time zones outside Europe. Region/language remains the primary signal;
  // these cover browsers that expose only a generic language such as "en".
  'Asia/Anadyr', 'Asia/Barnaul', 'Asia/Chita', 'Asia/Irkutsk', 'Asia/Kamchatka',
  'Asia/Khandyga', 'Asia/Krasnoyarsk', 'Asia/Magadan', 'Asia/Novokuznetsk',
  'Asia/Novosibirsk', 'Asia/Omsk', 'Asia/Sakhalin', 'Asia/Srednekolymsk',
  'Asia/Tomsk', 'Asia/Ust-Nera', 'Asia/Vladivostok', 'Asia/Yakutsk',
  'Asia/Yekaterinburg',
])

function isLocale(value: string | null): value is Locale {
  return value === 'ru' || value === 'en'
}

function isCurrency(value: string | null): value is Currency {
  return CURRENCIES.includes(value as Currency)
}

function detectInitialLocale(): Locale {
  if (typeof window === 'undefined') return 'ru'

  const saved = window.localStorage.getItem(LOCALE_STORAGE_KEY)
  if (isLocale(saved)) return saved

  const languages = navigator.languages?.length ? navigator.languages : [navigator.language]
  const hasCisLanguage = languages.some((language) => CIS_LANGUAGE_CODES.has(language.toLowerCase().split('-')[0]))
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone

  return hasCisLanguage || CIS_TIMEZONES.has(timezone) ? 'ru' : 'en'
}

function detectInitialCurrency(): Currency {
  if (typeof window === 'undefined') return 'RUB'

  const saved = window.localStorage.getItem(CURRENCY_STORAGE_KEY)
  if (isCurrency(saved)) return saved

  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone
  if (['Asia/Almaty', 'Asia/Astana', 'Asia/Aqtau', 'Asia/Aqtobe', 'Asia/Atyrau', 'Asia/Oral', 'Asia/Qostanay', 'Asia/Qyzylorda'].includes(timezone)) return 'KZT'
  if (timezone === 'Europe/Istanbul') return 'TRY'

  const languages = navigator.languages?.length ? navigator.languages : [navigator.language]
  const regions = languages.map((language) => language.split('-')[1]?.toUpperCase()).filter(Boolean)
  if (regions.includes('US')) return 'USD'
  if (regions.includes('KZ')) return 'KZT'
  if (regions.includes('TR')) return 'TRY'
  if (regions.some((region) => ['AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK'].includes(region))) return 'EUR'

  return detectInitialLocale() === 'ru' ? 'RUB' : 'USD'
}

function loadCachedRates(): Record<Currency, number> {
  const fallback = { RUB: 1 } as Record<Currency, number>
  if (typeof window === 'undefined') return fallback
  try {
    const cached = JSON.parse(window.localStorage.getItem(RATES_STORAGE_KEY) || '{}') as Partial<Record<Currency, number>>
    return { ...fallback, ...cached }
  } catch {
    return fallback
  }
}

type LocaleContextValue = {
  locale: Locale
  setLocale: (locale: Locale) => void
  currency: Currency
  setCurrency: (currency: Currency) => void
  availableCurrencies: Currency[]
  currencyReady: boolean
  formatPrice: (rubValue?: number | null) => string
  formatAmount: (value?: number | null, amountCurrency?: Currency) => string
  fromRub: (rubValue: number) => number
  toRub: (value: number) => number
  tr: (ru: string, en: string) => string
}

const LocaleContext = createContext<LocaleContextValue | null>(null)

export function LocaleProvider({ children }: { children: ReactNode }) {
  const [locale, setLocale] = useState<Locale>(detectInitialLocale)
  const [currency, setCurrency] = useState<Currency>(detectInitialCurrency)
  const [rates, setRates] = useState<Record<Currency, number>>(loadCachedRates)
  const ratesRequest = useRef<Promise<void> | null>(null)

  const loadRates = useCallback(() => {
    if (ratesRequest.current) return ratesRequest.current
    const request = fetch('/api/currencies', { headers: { Accept: 'application/json' } })
      .then((response) => response.ok ? response.json() as Promise<{ rates?: Partial<Record<Currency, number>> }> : Promise.reject())
      .then((data) => {
        if (!data.rates) return
        const next = { RUB: 1, ...data.rates } as Record<Currency, number>
        setRates(next)
        window.localStorage.setItem(RATES_STORAGE_KEY, JSON.stringify(next))
      })
      .catch(() => {})
      .finally(() => { ratesRequest.current = null })
    ratesRequest.current = request
    return request
  }, [])

  useEffect(() => {
    const localeWasChosen = window.localStorage.getItem(LOCALE_STORAGE_KEY) !== null
    const currencyWasChosen = window.localStorage.getItem(CURRENCY_STORAGE_KEY) !== null
    if (localeWasChosen && currencyWasChosen) return
    let active = true
    fetch('/api/region', { headers: { Accept: 'application/json' } })
      .then((response) => response.ok ? response.json() as Promise<{ locale?: string; currency?: string | null }> : Promise.reject())
      .then((region) => {
        if (!active) return
        if (!localeWasChosen && isLocale(region.locale ?? null)) setLocale(region.locale as Locale)
        if (!currencyWasChosen && isCurrency(region.currency ?? null)) setCurrency(region.currency as Currency)
      })
      .catch(() => {})
    return () => { active = false }
  }, [])

  useEffect(() => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)
    document.documentElement.lang = locale
    document.title = locale === 'ru' ? 'Игроскан — агрегатор цен на игры' : 'Igroscan — game price comparison'
    const description = document.querySelector<HTMLMetaElement>('meta[name="description"]')
    if (description) {
      description.content = locale === 'ru'
        ? 'Игроскан сравнивает цены Steam RU, Plati.Market и GGsel. История, избранное и ценовые уведомления.'
        : 'Igroscan compares Steam RU, Plati.Market, and GGsel prices with history, watchlists, and price alerts.'
    }
  }, [locale])

  useEffect(() => {
    window.localStorage.setItem(CURRENCY_STORAGE_KEY, currency)
  }, [currency])

  useEffect(() => {
    void loadRates()
  }, [loadRates])

  // При кратком сбое API не оставляем выбранную валюту навсегда с тире.
  // Повторный запрос нужен только когда курса именно выбранной валюты ещё нет.
  useEffect(() => {
    if (rates[currency]) return
    void loadRates()
    const timer = window.setInterval(() => { void loadRates() }, 8000)
    const onFocus = () => { void loadRates() }
    window.addEventListener('focus', onFocus)
    return () => {
      window.clearInterval(timer)
      window.removeEventListener('focus', onFocus)
    }
  }, [currency, loadRates, rates])

  const value = useMemo<LocaleContextValue>(() => ({
    locale,
    setLocale,
    currency,
    setCurrency,
    availableCurrencies: CURRENCIES.filter((item) => Boolean(rates[item])),
    currencyReady: Boolean(rates[currency]),
    formatPrice: (rubValue) => {
      if (rubValue == null || Number.isNaN(Number(rubValue))) return '—'
      const rate = rates[currency]
      if (!rate) return '…'
      return new Intl.NumberFormat(locale === 'ru' ? 'ru-RU' : 'en-US', {
        style: 'currency',
        currency,
        maximumFractionDigits: currency === 'RUB' || currency === 'KZT' ? 0 : 2,
      }).format(Number(rubValue) / rate)
    },
    formatAmount: (amount, amountCurrency = currency) => {
      if (amount == null || Number.isNaN(Number(amount))) return '—'
      return new Intl.NumberFormat(locale === 'ru' ? 'ru-RU' : 'en-US', {
        style: 'currency',
        currency: amountCurrency,
        maximumFractionDigits: amountCurrency === 'RUB' || amountCurrency === 'KZT' ? 0 : 2,
      }).format(Number(amount))
    },
    fromRub: (rubValue) => rates[currency] ? rubValue / rates[currency] : Number.NaN,
    toRub: (value) => rates[currency] ? value * rates[currency] : Number.NaN,
    tr: (ru, en) => locale === 'ru' ? ru : en,
  }), [currency, locale, rates])

  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
}

export function useLocale() {
  const context = useContext(LocaleContext)
  if (!context) throw new Error('useLocale must be used inside LocaleProvider')
  return context
}
