import { lazy, Suspense, useEffect, useRef, useState } from 'react'

const RadarScene = lazy(() => import('./RadarScene'))

function capableDevice() {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false
  if (!window.matchMedia('(min-width: 960px) and (pointer: fine)').matches) return false
  if ((navigator.hardwareConcurrency || 4) < 4) return false
  const memory = (navigator as Navigator & { deviceMemory?: number }).deviceMemory
  if (memory != null && memory < 4) return false
  try {
    const canvas = document.createElement('canvas')
    const context = canvas.getContext('webgl2', { powerPreference: 'high-performance' }) || canvas.getContext('webgl')
    context?.getExtension('WEBGL_lose_context')?.loseContext()
    return Boolean(context)
  } catch {
    return false
  }
}

export function MarketScanAccent() {
  const [enabled, setEnabled] = useState(false)
  const host = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!capableDevice()) return
    const node = host.current
    if (!node) return
    let idle: number | undefined
    const observer = new IntersectionObserver(([entry]) => {
      if (!entry.isIntersecting) return
      observer.disconnect()
      idle = window.requestIdleCallback?.(() => setEnabled(true), { timeout: 900 })
        ?? window.setTimeout(() => setEnabled(true), 250)
    }, { rootMargin: '180px' })
    observer.observe(node)
    return () => {
      observer.disconnect()
      if (idle == null) return
      if (window.cancelIdleCallback) window.cancelIdleCallback(idle)
      else window.clearTimeout(idle)
    }
  }, [])

  return (
    <div ref={host} className="market-scan-accent" aria-hidden="true">
      {enabled ? (
        <Suspense fallback={<ScannerFallback />}><RadarScene /></Suspense>
      ) : <ScannerFallback />}
    </div>
  )
}

function ScannerFallback() {
  const widths = [292, 251, 219, 184, 142]
  return (
    <svg className="market-scan-fallback" viewBox="0 0 430 120" fill="none">
      <path className="market-sort-axis" d="M58 15v91" />
      <g className="market-sort-bars">
        {widths.map((width, index) => (
          <g key={width} className={index === widths.length - 1 ? 'is-best' : undefined}>
            <rect x="68" y={20 + index * 18} width={width} height="9" rx="4" />
            <circle cx={68 + width - 7} cy={24.5 + index * 18} r={index === widths.length - 1 ? 3 : 2} />
          </g>
        ))}
      </g>
      <circle className="market-sort-best-ring" cx="203" cy="96.5" r="6" />
    </svg>
  )
}
