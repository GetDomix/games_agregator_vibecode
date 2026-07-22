const TOKEN_KEY = 'gpa_token'
const USER_KEY = 'gpa_user'

export type User = {
  id: number
  email: string
  display_name: string
  plan?: 'free' | 'pro' | 'unlimited' | string
  plan_label?: string
  plan_expires_at?: string | null
  created_at?: string
  last_login_at?: string | null
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function getStoredUser(): User | null {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null')
  } catch {
    return null
  }
}

export function setSession(token: string | null, user: User | null) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
  if (user) localStorage.setItem(USER_KEY, JSON.stringify(user))
  else localStorage.removeItem(USER_KEY)
}

export async function api<T = unknown>(path: string, options: RequestInit = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers as Record<string, string>),
  }
  if (options.body && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json'
  }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`

  let res: Response
  try {
    res = await fetch(path, { ...options, headers })
  } catch {
    const host = typeof window !== 'undefined' ? window.location.host : ''
    const hint =
      host.includes('sslip.io') || host.includes('trycloudflare.com')
        ? 'Нет связи с API. Обновите страницу или попробуйте позже.'
        : 'Нет связи с сервером. Откройте сайт по HTTPS: https://gpa.185.100.157.180.sslip.io'
    throw new Error(hint)
  }
  if (res.status === 204) return null as T
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const detail = (data as { detail?: unknown; message?: string }).detail
    const msg =
      typeof detail === 'string'
        ? detail
        : Array.isArray(detail)
          ? detail.map((d: { message?: string }) => d.message || JSON.stringify(d)).join('; ')
          : (data as { message?: string }).message || `Ошибка ${res.status}`
    throw new Error(msg)
  }
  return data as T
}

export const authHeaders = (): Record<string, string> => {
  const t = getToken()
  return t ? { Authorization: `Bearer ${t}` } : {}
}
