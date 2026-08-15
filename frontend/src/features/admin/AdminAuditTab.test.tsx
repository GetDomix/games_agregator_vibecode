import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, it, vi } from 'vitest'
import { AdminAuditTab } from './AdminAuditTab'

function response(page: number) {
  return new Response(JSON.stringify({
    data: [{
      id: page,
      request_id: null,
      actor: null,
      action: 'game.refresh_requested',
      target_type: 'game',
      target_id: String(page),
      context: { sources: ['steam'] },
      created_at: null,
    }],
    current_page: page,
    last_page: 2,
    per_page: 25,
    total: 26,
  }), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

it('renders nullable audit fields and moves to the next page', async () => {
  vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => Promise.resolve(
    String(input).includes('?page=2&') ? response(2) : response(1),
  )))
  const user = userEvent.setup()
  render(<AdminAuditTab onError={() => undefined} onNotice={() => undefined} />)

  expect(await screen.findByText(/Системное действие · game #1/)).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Дальше' }))

  expect(await screen.findByText(/Системное действие · game #2/)).toBeInTheDocument()
  expect(screen.getByText('2 / 2')).toBeInTheDocument()
})
