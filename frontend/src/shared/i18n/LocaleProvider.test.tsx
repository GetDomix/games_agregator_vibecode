import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LocaleProvider, useLocale } from './LocaleProvider'

function LocaleProbe() {
  const { locale, currency, formatPrice, tr } = useLocale()
  return (
    <div>
      <span>{locale}</span>
      <span>{currency}</span>
      <span>{tr('Русский интерфейс', 'English interface')}</span>
      <span>{formatPrice(4955.53)}</span>
    </div>
  )
}

describe('LocaleProvider MVP defaults', () => {
  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem('igroscan_locale_v1', 'en')
    localStorage.setItem('igroscan_currency_v1', 'USD')
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ rates: { RUB: 1, USD: 80 } }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })))
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('forces Russian and rubles while the language and currency controls are hidden', async () => {
    render(<LocaleProvider><LocaleProbe /></LocaleProvider>)

    expect(screen.getByText('ru')).toBeInTheDocument()
    expect(screen.getByText('RUB')).toBeInTheDocument()
    expect(screen.getByText('Русский интерфейс')).toBeInTheDocument()
    expect(screen.getByText(/4\s956\s₽/)).toBeInTheDocument()

    await waitFor(() => {
      expect(localStorage.getItem('igroscan_locale_v1')).toBe('ru')
      expect(localStorage.getItem('igroscan_currency_v1')).toBe('RUB')
    })
    expect(document.documentElement.lang).toBe('ru')
  })
})
