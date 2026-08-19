import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LocaleProvider } from '../shared/i18n/LocaleProvider'
import { MarketCard } from './App'

describe('MarketCard', () => {
  beforeEach(() => {
    localStorage.setItem('igroscan_locale_v1', 'ru')
    localStorage.setItem('igroscan_currency_v1', 'RUB')
    localStorage.setItem('igroscan_currency_rates_v1', JSON.stringify({ RUB: 1 }))
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ rates: { RUB: 1 } }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })))
  })

  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('shows the last successful offers when a source refresh failed', () => {
    render(
      <LocaleProvider>
        <MarketCard
          market={{
            marketplace: 'plati',
            label: 'Plati.Market',
            total_offers: 1,
            scanned_offers: 1,
            error: 'Источник временно недоступен',
            by_kind: [{
              kind: 'key',
              label: 'Ключ',
              count: 1,
              min_price: 499,
              avg_price: 499,
              cheapest: { title: 'Game key', url: 'https://example.test/key', price_rub: 499 },
              popular: { title: 'Game key', url: 'https://example.test/key', price_rub: 499, sales: 10 },
            }],
          }}
          onTrack={vi.fn()}
        />
      </LocaleProvider>,
    )

    expect(screen.getByRole('status')).toHaveTextContent('Показана последняя успешно сохранённая цена')
    expect(screen.getByRole('table')).toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Минимум' })).not.toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Дешёвый лот' })).toBeInTheDocument()
    expect(screen.getByText('10 продаж')).toBeInTheDocument()
    expect(screen.getAllByText(/499/).length).toBeGreaterThan(0)
  })
})
