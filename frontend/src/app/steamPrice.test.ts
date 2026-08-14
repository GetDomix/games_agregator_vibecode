import { describe, expect, it } from 'vitest'
import { selectSteamDisplayPrice } from './steamPrice'

describe('selectSteamDisplayPrice', () => {
  it('falls back to a converted official regional price when Steam RU has no price', () => {
    expect(selectSteamDisplayPrice({
      price_rub: null,
      regional_prices: [
        { region: 'US', label: 'United States', currency: 'USD', amount: 59.99, price_rub: 4955.53 },
      ],
    }, 'RUB')).toEqual({ kind: 'rub', value: 4955.53, regionLabel: 'United States' })
  })

  it('uses the selected Steam region even when that storefront returns another currency', () => {
    expect(selectSteamDisplayPrice({
      price_rub: null,
      regional_prices: [
        { region: 'US', label: 'United States', currency: 'USD', amount: 59.99, price_rub: 4955.53 },
        { region: 'TR', label: 'Turkey', currency: 'USD', amount: 44.99, price_rub: 3716.53 },
      ],
    }, 'TRY')).toEqual({ kind: 'rub', value: 3716.53, regionLabel: 'Turkey' })
  })

  it('does not confuse another USD storefront with the selected US region', () => {
    expect(selectSteamDisplayPrice({
      regional_prices: [
        { region: 'TR', label: 'Turkey', currency: 'USD', amount: 44.99, price_rub: 3716.53 },
        { region: 'US', label: 'United States', currency: 'USD', amount: 59.99, price_rub: 4955.53 },
      ],
    }, 'USD')).toEqual({
      kind: 'amount',
      value: 59.99,
      currency: 'USD',
      regionLabel: 'United States',
    })
  })
})
