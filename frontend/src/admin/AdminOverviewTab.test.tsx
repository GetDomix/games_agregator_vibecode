import { useState } from 'react'
import { act, cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, it, vi } from 'vitest'
import { AdminOverviewTab } from './AdminOverviewTab'

function OverviewHarness() {
  const [visible, setVisible] = useState(true)
  const [error, setError] = useState('')
  return (
    <>
      <button type="button" onClick={() => setVisible(false)}>Закрыть вкладку</button>
      {error && <div role="alert">{error}</div>}
      {visible && <AdminOverviewTab onError={setError} onNotice={() => undefined} />}
    </>
  )
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

it('ignores a late request failure after its tab unmounts', async () => {
  let rejectRequest: ((reason: Error) => void) | undefined
  vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((_resolve, reject) => { rejectRequest = reject })))
  const user = userEvent.setup()
  render(<OverviewHarness />)

  await user.click(screen.getByRole('button', { name: 'Закрыть вкладку' }))
  await act(async () => {
    rejectRequest?.(new Error('late network failure'))
    await Promise.resolve()
  })

  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})
