import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { GameRail } from './GameRail'

describe('GameRail', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.stubGlobal('matchMedia', vi.fn((query: string) => ({
      matches: query.includes('prefers-reduced-motion'),
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })))
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('does not auto-rotate when reduced motion is requested', () => {
    render(<GameRail
      previousLabel="Previous"
      nextLabel="Next"
      items={['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'].map((name) => ({ id: name, name, onClick: vi.fn() }))}
    />)

    expect(screen.queryByRole('button', { name: 'Echo' })).not.toBeInTheDocument()
    act(() => vi.advanceTimersByTime(5000))
    expect(screen.queryByRole('button', { name: 'Echo' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(screen.getByRole('button', { name: 'Echo' })).toBeInTheDocument()
  })
})
