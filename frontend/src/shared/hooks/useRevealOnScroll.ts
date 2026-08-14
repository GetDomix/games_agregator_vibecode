import { useEffect } from 'react'

const revealed = new Set<string>()

export function useRevealOnScroll(active: boolean, refreshKey: string) {
  useEffect(() => {
    if (!active) return
    const root = document.querySelector('main')
    if (!root) return
    const elements = Array.from(root.querySelectorAll<HTMLElement>('[data-reveal]'))
    document.documentElement.classList.add('reveal-ready')
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    const show = (element: HTMLElement) => {
      const key = element.dataset.reveal || ''
      element.classList.add('is-revealed')
      if (key) revealed.add(key)
    }
    if (reduced || !('IntersectionObserver' in window)) {
      elements.forEach(show)
      return
    }
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return
        show(entry.target as HTMLElement)
        observer.unobserve(entry.target)
      })
    }, { rootMargin: '0px 0px -14% 0px', threshold: 0.12 })
    elements.forEach((element) => {
      const key = element.dataset.reveal || ''
      if (key && revealed.has(key)) show(element)
      else observer.observe(element)
    })
    return () => observer.disconnect()
  }, [active, refreshKey])
}
