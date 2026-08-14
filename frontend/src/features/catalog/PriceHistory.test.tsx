import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LocaleProvider } from '../../shared/i18n/LocaleProvider'
import { PriceHistory } from './PriceHistory'

const overview = {
  period_days: 90,
  available_periods: [30, 90, 365],
  current: { price_rub: 1249, source: 'steam', offer_kind: 'official', observed_at: '2026-08-13T12:40:00+03:00' },
  statistics: { minimum_price_rub: 1199, median_price_rub: 1790 },
  coverage: { observations: 34, checks: 34, observed_days: 63, started_at: '2026-06-12T12:00:00+03:00', sufficient: true },
  verdict: 'low',
}

describe('PriceHistory', () => {
  beforeEach(() => {
    localStorage.setItem('igroscan_locale_v1', 'ru')
    localStorage.setItem('igroscan_currency_v1', 'RUB')
    localStorage.setItem('igroscan_currency_rates_v1', JSON.stringify({ RUB: 1 }))
  })

  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('shows the public overview and explains that changes require sign in', async () => {
    const onRequireAuth = vi.fn()
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url.includes('/api/games/')) return Response.json(overview)
      return Response.json({ rates: { RUB: 1 } })
    }))

    render(<LocaleProvider><PriceHistory appid={1091500} authenticated={false} onRequireAuth={onRequireAuth} /></LocaleProvider>)

    expect(await screen.findByText(/1\s249/)).toBeInTheDocument()
    expect(screen.getByText('Цена низкая')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('tab', { name: /Изменения/ }))
    expect(onRequireAuth).toHaveBeenCalledOnce()
    expect(screen.getByRole('tab', { name: 'Обзор' })).toHaveAttribute('aria-selected', 'true')
  })

  it('loads the protected change log for an authenticated user', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url.includes('/api/me/games/')) return Response.json({
        ...overview,
        changes: [{
          source: 'steam', offer_kind: 'official', previous_price_rub: 1799, price_rub: 1249,
          change_percent: -31, observed_at: '2026-08-13T12:40:00+03:00',
        }],
      })
      if (url.includes('/api/games/')) return Response.json(overview)
      return Response.json({ rates: { RUB: 1 } })
    })
    vi.stubGlobal('fetch', fetchMock)

    render(<LocaleProvider><PriceHistory appid={1091500} authenticated onRequireAuth={vi.fn()} /></LocaleProvider>)
    await screen.findByText('Цена низкая')
    await userEvent.click(screen.getByRole('tab', { name: 'Изменения' }))

    expect(await screen.findByText('−31%')).toBeInTheDocument()
    expect(screen.getByText('официально')).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/api/me/games/1091500/price-history?days=90',
      expect.anything(),
    ))
  })

  it('keeps the estimate quiet until the observation period is long enough', async () => {
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      if (String(input).includes('/api/games/')) return Response.json({
        ...overview,
        coverage: { ...overview.coverage, checks: 6, observed_days: 1, sufficient: false },
        verdict: 'insufficient',
      })
      return Response.json({ rates: { RUB: 1 } })
    }))

    render(<LocaleProvider><PriceHistory appid={292030} authenticated={false} onRequireAuth={vi.fn()} /></LocaleProvider>)

    expect(await screen.findByText('собираем данные')).toBeInTheDocument()
    expect(screen.getByText('1 дн. · 6 проверок')).toBeInTheDocument()
    expect(screen.queryByText('Цена низкая')).not.toBeInTheDocument()
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument()
    expect(screen.queryByText('Покупать или подождать?')).not.toBeInTheDocument()
  })
})
