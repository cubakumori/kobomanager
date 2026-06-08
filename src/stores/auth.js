import { defineStore } from 'pinia'
import api from '../services/api'
import i18n, { setLocale } from '../i18n'
import { useReviewStatusesStore } from './reviewStatuses'

// Traduce un error de Axios a un mensaje legible. Si hay traducción para el código
// de error, se usa; si no, el mensaje del backend; si no, un genérico.
export function apiError(e, fallback) {
  const t = i18n.global.t
  const code = e?.response?.data?.error?.code
  if (code && i18n.global.te(`errors.${code}`)) return t(`errors.${code}`)
  return e?.response?.data?.error?.message ?? fallback ?? t('errors.generic')
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,        // { id, name, email, role }
    loading: false,
    checked: false,    // ya se intentó resolver la sesión al menos una vez
  }),
  getters: {
    isAuthenticated: (s) => !!s.user,
    isAdmin: (s) => s.user?.role === 'admin',
    // Flag global: ¿puede el usuario ver su propio registro de actividad?
    // Llega adjunto al usuario en /auth/me y /auth/login.
    auditSelfView: (s) => !!s.user?.audit_self_view_enabled,
  },
  actions: {
    async login(email, password) {
      const { data } = await api.post('/auth/login', { email, password })
      this.user = data.data
      this.checked = true
      setLocale(this.user?.locale)
      useReviewStatusesStore().load(true)
      return this.user
    },
    async logout() {
      try {
        await api.post('/auth/logout')
      } finally {
        this.user = null
        useReviewStatusesStore().clear()
      }
    },
    async fetchMe() {
      this.loading = true
      try {
        const { data } = await api.get('/auth/me', { skipAuthRedirect: true })
        this.user = data.data
        setLocale(this.user?.locale)
        useReviewStatusesStore().load(true)
      } catch {
        this.user = null
      } finally {
        this.checked = true
        this.loading = false
      }
      return this.user
    },
    clear() {
      this.user = null
    },
  },
})
