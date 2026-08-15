import type { Currency } from '../shared/i18n/LocaleProvider'

type RegionalSteamPrice = {
  region: string
  label: string
  currency: Currency
  amount: number
  price_rub?: number | null
}

type SteamPriceData = {
  price_rub?: number | null
  regional_prices?: RegionalSteamPrice[]
}

export type SteamDisplayPrice =
  | { kind: 'amount'; value: number; currency: Currency; regionLabel?: string }
  | { kind: 'rub'; value: number; regionLabel?: string }
  | null

const REGION_BY_CURRENCY: Record<Currency, string> = {
  RUB: 'RU',
  USD: 'US',
  EUR: 'DE',
  KZT: 'KZ',
  TRY: 'TR',
}

export function selectSteamDisplayPrice(steam: SteamPriceData, currency: Currency): SteamDisplayPrice {
  const preferredRegion = steam.regional_prices?.find(
    (price) => price.region.toUpperCase() === REGION_BY_CURRENCY[currency] && Number.isFinite(Number(price.amount)),
  )
  if (preferredRegion?.currency === currency) {
    return {
      kind: 'amount',
      value: Number(preferredRegion.amount),
      currency: preferredRegion.currency,
      regionLabel: preferredRegion.label,
    }
  }
  if (preferredRegion?.price_rub != null && Number.isFinite(Number(preferredRegion.price_rub))) {
    return { kind: 'rub', value: Number(preferredRegion.price_rub), regionLabel: preferredRegion.label }
  }

  const exactRegionalPrice = steam.regional_prices?.find(
    (price) => price.currency === currency && Number.isFinite(Number(price.amount)),
  )
  if (exactRegionalPrice) {
    return {
      kind: 'amount',
      value: Number(exactRegionalPrice.amount),
      currency: exactRegionalPrice.currency,
      regionLabel: exactRegionalPrice.label,
    }
  }

  if (steam.price_rub != null && Number.isFinite(Number(steam.price_rub))) {
    return { kind: 'rub', value: Number(steam.price_rub) }
  }

  const convertedRegionalPrice = steam.regional_prices?.find(
    (price) => price.price_rub != null && Number.isFinite(Number(price.price_rub)),
  )
  return convertedRegionalPrice
    ? { kind: 'rub', value: Number(convertedRegionalPrice.price_rub), regionLabel: convertedRegionalPrice.label }
    : null
}
