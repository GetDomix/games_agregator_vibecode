import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LocaleProvider } from '../../shared/i18n/LocaleProvider'
import { AlertSettingsModal } from './AlertSettingsModal'

const favorite = { appid: 1, game_name: 'Hades', alert: { condition_type: 'target_price' as const, target_value: null, status: 'active' as const, scopes: [{ source: 'steam' as const, offer_kind: 'official' as const }] }, suggested_target: { value_rub: 900, reference_price_rub: 1000, reduction_percent: 10, source: 'steam', offer_kind: 'official', basis: 'current_price_minus_10_percent' }, observed_lows: [{ source: 'steam' as const, offer_kind: 'official' as const, price_rub: 840 }, { source: 'plati' as const, offer_kind: 'key' as const, price_rub: 650 }] }

function prepareLocale(currency: 'RUB' | 'USD' = 'RUB', rates: Record<string, number> = { RUB: 1 }) {
  localStorage.setItem('igroscan_locale_v1', 'ru')
  localStorage.setItem('igroscan_currency_v1', currency)
  localStorage.setItem('igroscan_currency_rates_v1', JSON.stringify(rates))
}

function view(save = vi.fn().mockResolvedValue(undefined)) {
  prepareLocale()
  return { save, ...render(<LocaleProvider><AlertSettingsModal favorite={favorite} onClose={vi.fn()} onSave={save} onSavedPrefs={vi.fn()} /></LocaleProvider>) }
}
describe('AlertSettingsModal', () => {
  afterEach(cleanup)
  it('does not prefill a suggestion and applies it only explicitly', async () => {
    const user = userEvent.setup(); const { save } = view()
    const input = screen.getByLabelText('Цена не выше') as HTMLInputElement
    expect(input.value).toBe('')
    expect(screen.queryByText('Введите корректное значение.')).not.toBeInTheDocument()
    expect(screen.getByText(/Steam · официальная цена/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Подставить' })); expect(input.value).toBe('900')
    await user.click(screen.getByRole('button', { name: 'Сохранить алерт' }))
    expect(save).toHaveBeenCalledWith(expect.objectContaining({ condition_type: 'target_price', target_value: 900 }))
  })
  it('does not allow a zero target price that the backend would reject', async () => {
    const user = userEvent.setup(); view()
    const input = screen.getByLabelText('Цена не выше') as HTMLInputElement
    expect(input.min).toBe('1')
    await user.type(input, '0')
    expect(screen.getByText('Введите корректное значение.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Сохранить алерт' })).toBeDisabled()
  })
  it('ignores a stale non-RUB preference while MVP region controls are hidden', async () => {
    const user = userEvent.setup()
    const save = vi.fn().mockResolvedValue(undefined)
    prepareLocale('USD', { RUB: 1, USD: 100 })
    render(<LocaleProvider><AlertSettingsModal favorite={favorite} onClose={vi.fn()} onSave={save} onSavedPrefs={vi.fn()} /></LocaleProvider>)

    const input = screen.getByLabelText('Цена не выше') as HTMLInputElement
    expect(input.value).toBe('')
    expect(screen.getByText(/10% ниже текущей сохранённой цены: 900 RUB/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Подставить' }))
    expect(input.value).toBe('900')
    await user.click(screen.getByRole('button', { name: 'Сохранить алерт' }))
    expect(save).toHaveBeenCalledWith(expect.objectContaining({ condition_type: 'target_price', target_value: 900 }))
  })
  it('sends percent and new-low payloads and keeps advanced settings collapsed', async () => {
    const user = userEvent.setup(); const { save } = view()
    expect(document.querySelector('details')?.open).toBe(false)
    await user.click(screen.getByText(/Дополнительные настройки/))
    expect(screen.getByText('Plati.Market')).toBeInTheDocument()
    expect(screen.getAllByText('Ключ')).toHaveLength(3)
    await user.click(screen.getByLabelText('Скидка Steam')); await user.type(screen.getByLabelText('Скидка не меньше, %'), '30'); await user.click(screen.getByRole('button', { name: 'Сохранить алерт' }))
    expect(save).toHaveBeenLastCalledWith(expect.objectContaining({ condition_type: 'discount_percent', target_value: 30 }))
    await user.click(screen.getByLabelText('Новый минимум')); await user.click(screen.getByRole('button', { name: 'Сохранить алерт' }))
    expect(save).toHaveBeenLastCalledWith(expect.objectContaining({ condition_type: 'new_low', target_value: null }))
    expect(screen.getByText('840 RUB')).toBeInTheDocument()
  })
  it('restores bulk type and per-market selection without expanding the main form', async () => {
    const user = userEvent.setup(); view()
    await user.click(screen.getByText(/Дополнительные настройки/))
    const quickKey = screen.getByRole('button', { name: /Ключ.*0\/2/ })
    await user.click(quickKey)
    expect(screen.getAllByLabelText('Ключ')).toHaveLength(2)
    expect(screen.getAllByLabelText('Ключ').every((control) => (control as HTMLInputElement).checked)).toBe(true)
    const platiGroup = screen.getByText('Plati.Market').closest('.alert-scope-group') as HTMLElement
    await user.click(platiGroup.querySelector('button') as HTMLButtonElement)
    expect(platiGroup.querySelectorAll('input:checked')).toHaveLength(4)
  })
  it('keeps price and percent drafts separate while switching conditions', async () => {
    const user = userEvent.setup(); const { save } = view()
    await user.type(screen.getByLabelText('Цена не выше'), '500')
    await user.click(screen.getByLabelText('Скидка Steam'))
    const discount = screen.getByLabelText('Скидка не меньше, %') as HTMLInputElement
    expect(discount.value).toBe('')
    expect(screen.getByRole('button', { name: 'Сохранить алерт' })).toBeDisabled()
    await user.type(discount, '30')
    await user.click(screen.getByRole('button', { name: 'Сохранить алерт' }))
    expect(save).toHaveBeenLastCalledWith(expect.objectContaining({ condition_type: 'discount_percent', target_value: 30 }))
    await user.click(screen.getByLabelText('Своя цена'))
    expect((screen.getByLabelText('Цена не выше') as HTMLInputElement).value).toBe('500')
  })
  it('does not turn an initial discount percent into a ruble price', async () => {
    const user = userEvent.setup(); prepareLocale()
    render(<LocaleProvider><AlertSettingsModal favorite={{ ...favorite, alert: { ...favorite.alert, condition_type: 'discount_percent', target_value: 30 } }} onClose={vi.fn()} onSave={vi.fn()} onSavedPrefs={vi.fn()} /></LocaleProvider>)
    expect((screen.getByLabelText('Скидка не меньше, %') as HTMLInputElement).value).toBe('30')
    await user.click(screen.getByLabelText('Своя цена'))
    expect((screen.getByLabelText('Цена не выше') as HTMLInputElement).value).toBe('')
    expect(screen.getByRole('button', { name: 'Сохранить алерт' })).toBeDisabled()
  })
  it('ignores Escape while save is pending and returns focus only after close', async () => {
    const user = userEvent.setup(); let resolve!: () => void
    const save = vi.fn(() => new Promise<void>((done) => { resolve = done }))
    const trigger = document.createElement('button'); document.body.append(trigger); trigger.focus()
    const close = vi.fn(); prepareLocale()
    const rendered = render(<LocaleProvider><AlertSettingsModal favorite={favorite} onClose={close} onSave={save} onSavedPrefs={vi.fn()} /></LocaleProvider>)
    await user.click(screen.getByRole('button', { name: 'Подставить' })); await user.click(screen.getByRole('button', { name: 'Сохранить алерт' })); await screen.findByRole('button', { name: 'Сохраняем…' }); await user.keyboard('{Escape}')
    expect(close).not.toHaveBeenCalled(); resolve(); await waitFor(() => expect(close).toHaveBeenCalledTimes(1))
    expect(document.activeElement).not.toBe(trigger)
    rendered.unmount()
    expect(document.activeElement).toBe(trigger)
    trigger.remove()
  })
})
