import { defineStore } from 'pinia'
import api from '../services/api'

// Traduce un error de Axios a un mensaje legible usando el formato estándar del backend.
export function apiError(e, fallback = 'Ha ocurrido un error') {
  return e?.response?.data?.error?.message ?? fallback
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
  },
  actions: {
    async login(email, password) {
      const { data } = await api.post('/auth/login', { email, password })
      this.user = data.data
      this.checked = true
      return this.user
    },
    async logout() {
      try {
        await api.post('/auth/logout')
      } finally {
        this.user = null
      }
    },
    async fetchMe() {
      this.loading = true
      try {
        const { data } = await api.get('/auth/me')
        this.user = data.data
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
