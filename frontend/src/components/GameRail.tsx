import { motion, useReducedMotion } from 'framer-motion'
import { useEffect, useMemo, useState } from 'react'

export type GameRailItem = {
  id: string | number
  name: string
  image?: string | null
  meta?: string | null
  badge?: string | null
  onClick: () => void
}

export function GameRail({ items, previousLabel, nextLabel }: { items: GameRailItem[]; previousLabel: string; nextLabel: string }) {
  const [index, setIndex] = useState(0)
  const [paused, setPaused] = useState(false)
  const reduceMotion = useReducedMotion()
  const visible = useMemo(() => Array.from({ length: Math.min(4, items.length) }, (_, offset) => items[(index + offset) % items.length]), [index, items])

  useEffect(() => {
    if (paused || items.length < 2) return
    const timer = window.setInterval(() => setIndex((current) => (current + 1) % items.length), 4800)
    return () => window.clearInterval(timer)
  }, [items.length, paused])

  useEffect(() => setIndex((current) => items.length ? current % items.length : 0), [items.length])
  if (items.length === 0) return null

  const move = (direction: 1 | -1) => setIndex((current) => (current + direction + items.length) % items.length)

  return (
    <div className="game-rail" onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)} onFocusCapture={() => setPaused(true)} onBlurCapture={() => setPaused(false)}>
      <button type="button" className="game-rail-arrow prev" onClick={() => move(-1)} aria-label={previousLabel}>‹</button>
      <div className="game-rail-viewport">
        <motion.div
          key={index}
          className="game-rail-grid"
          initial={reduceMotion ? false : { opacity: 0.45, x: 22 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.36, ease: [0.22, 1, 0.36, 1] }}
        >
          {visible.map((item, offset) => (
            <button key={`${item.id}-${offset}`} type="button" className="game-rail-card" onClick={item.onClick}>
              <span className="game-rail-media">{item.image ? <img src={item.image} alt="" loading="lazy" /> : <span aria-hidden="true" />}{item.badge && <b>{item.badge}</b>}</span>
              <span className="game-rail-copy"><strong>{item.name}</strong>{item.meta && <small>{item.meta}</small>}</span>
            </button>
          ))}
        </motion.div>
      </div>
      <button type="button" className="game-rail-arrow next" onClick={() => move(1)} aria-label={nextLabel}>›</button>
      <div className="game-rail-progress" aria-hidden="true"><span style={{ width: `${((index + 1) / items.length) * 100}%` }} /></div>
    </div>
  )
}
