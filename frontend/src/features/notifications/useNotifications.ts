import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../../shared/api/client'
import type { NotificationFeed, SiteNotification } from './types'

const POLL_INTERVAL_MS = 12_000
const HIDDEN_POLL_INTERVAL_MS = 60_000

type AudioWindow = Window & typeof globalThis & {
  webkitAudioContext?: typeof AudioContext
}

export function useNotifications(enabled: boolean) {
  const [items, setItems] = useState<SiteNotification[]>([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [latestId, setLatestId] = useState(0)
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [loadingEarlier, setLoadingEarlier] = useState(false)
  const [hasEarlier, setHasEarlier] = useState(false)
  const [liveNotification, setLiveNotification] = useState<SiteNotification | null>(null)
  const latestIdRef = useRef(0)
  const openRef = useRef(false)
  const initializedRef = useRef(false)
  const audioRef = useRef<AudioContext | null>(null)
  const returnFocusRef = useRef<HTMLElement | null>(null)

  latestIdRef.current = latestId
  openRef.current = open

  const playSignal = useCallback(() => {
    const context = audioRef.current
    if (!context || context.state !== 'running') return
    const start = context.currentTime
    const gain = context.createGain()
    gain.gain.setValueAtTime(0.0001, start)
    gain.gain.exponentialRampToValueAtTime(0.11, start + 0.018)
    gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.32)
    gain.connect(context.destination)
    ;[660, 880].forEach((frequency, index) => {
      const oscillator = context.createOscillator()
      oscillator.type = 'sine'
      oscillator.frequency.value = frequency
      oscillator.connect(gain)
      oscillator.start(start + index * 0.09)
      oscillator.stop(start + 0.22 + index * 0.09)
    })
  }, [])

  useEffect(() => {
    if (!enabled) return
    const primeAudio = () => {
      if (audioRef.current) return
      const AudioCtor = window.AudioContext || (window as AudioWindow).webkitAudioContext
      if (!AudioCtor) return
      const context = new AudioCtor()
      audioRef.current = context
      context.resume().catch(() => {})
    }
    window.addEventListener('pointerdown', primeAudio, { once: true })
    window.addEventListener('keydown', primeAudio, { once: true })
    return () => {
      window.removeEventListener('pointerdown', primeAudio)
      window.removeEventListener('keydown', primeAudio)
    }
  }, [enabled])

  const markReadThrough = useCallback(async (throughId: number) => {
    if (!throughId) return
    setUnreadCount(0)
    setItems((current) => current.map((item) => item.id <= throughId ? { ...item, read: true } : item))
    await api('/api/me/notifications/read-through', {
      method: 'POST',
      body: JSON.stringify({ through_id: throughId }),
    })
  }, [])

  useEffect(() => {
    if (!enabled) {
      setItems([])
      setUnreadCount(0)
      setLatestId(0)
      setOpen(false)
      setLoadingEarlier(false)
      setHasEarlier(false)
      setLiveNotification(null)
      initializedRef.current = false
      return
    }
    let cancelled = false
    setLoading(true)
    api<NotificationFeed>('/api/me/notifications?limit=40')
      .then((feed) => {
        if (cancelled) return
        setItems(feed.items ?? [])
        setUnreadCount(feed.unread_count ?? 0)
        setLatestId(feed.latest_id ?? 0)
        setHasEarlier(Boolean(feed.has_more))
        initializedRef.current = true
        if (openRef.current && feed.latest_id) markReadThrough(feed.latest_id).catch(() => {})
      })
      .catch(() => {})
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [enabled, markReadThrough])

  useEffect(() => {
    if (!enabled) return
    let cancelled = false
    let timer = 0
    let inFlight = false
    const schedule = (delay: number) => {
      window.clearTimeout(timer)
      timer = window.setTimeout(poll, delay)
    }
    const poll = async () => {
      if (cancelled || inFlight) return
      if (document.visibilityState !== 'visible') {
        schedule(HIDDEN_POLL_INTERVAL_MS)
        return
      }
      inFlight = true
      try {
        const feed = await api<NotificationFeed>(`/api/me/notifications?limit=20&after_id=${latestIdRef.current}`)
        if (cancelled) return
        if (feed.items?.length) {
          const incoming = [...feed.items].sort((a, b) => b.id - a.id)
          setItems((current) => {
            const ids = new Set(incoming.map((item) => item.id))
            const capacity = Math.max(40, current.length)
            return [...incoming, ...current.filter((item) => !ids.has(item.id))].slice(0, capacity)
          })
          if (initializedRef.current && document.visibilityState === 'visible') {
            setLiveNotification(incoming[0])
            playSignal()
          }
        }
        setUnreadCount(feed.unread_count ?? 0)
        setLatestId(feed.latest_id ?? latestIdRef.current)
        if (openRef.current && feed.latest_id) {
          markReadThrough(feed.latest_id).catch(() => {})
        }
      } catch {
        // A missed poll is harmless: the persistent feed catches up next time.
      } finally {
        inFlight = false
        if (!cancelled) schedule(document.visibilityState === 'visible' ? POLL_INTERVAL_MS : HIDDEN_POLL_INTERVAL_MS)
      }
    }
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') schedule(0)
    }
    document.addEventListener('visibilitychange', onVisibilityChange)
    schedule(POLL_INTERVAL_MS)
    return () => {
      cancelled = true
      window.clearTimeout(timer)
      document.removeEventListener('visibilitychange', onVisibilityChange)
    }
  }, [enabled, markReadThrough, playSignal])

  useEffect(() => {
    if (!liveNotification) return
    const timer = window.setTimeout(() => setLiveNotification(null), 6500)
    return () => window.clearTimeout(timer)
  }, [liveNotification])

  const loadEarlier = useCallback(async () => {
    const oldestId = items.at(-1)?.id
    if (!oldestId || !hasEarlier || loadingEarlier) return
    setLoadingEarlier(true)
    try {
      const feed = await api<NotificationFeed>(`/api/me/notifications?limit=40&before_id=${oldestId}`)
      setItems((current) => {
        const known = new Set(current.map((item) => item.id))
        return [...current, ...(feed.items ?? []).filter((item) => !known.has(item.id))]
      })
      setHasEarlier(Boolean(feed.has_more))
    } catch {
      // The current page remains usable; the user can retry the explicit action.
    } finally {
      setLoadingEarlier(false)
    }
  }, [hasEarlier, items, loadingEarlier])

  const toggle = useCallback(() => {
    if (!openRef.current) {
      returnFocusRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
      setOpen(true)
      if (latestIdRef.current) markReadThrough(latestIdRef.current).catch(() => {})
    } else {
      setOpen(false)
      returnFocusRef.current?.focus()
    }
  }, [markReadThrough])

  const close = useCallback(() => {
    setOpen(false)
    window.requestAnimationFrame(() => returnFocusRef.current?.focus())
  }, [])

  return {
    items,
    unreadCount,
    latestId,
    open,
    loading,
    loadingEarlier,
    hasEarlier,
    liveNotification,
    toggle,
    close,
    dismissLive: () => setLiveNotification(null),
    loadEarlier,
  }
}
