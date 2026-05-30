import { defineStore } from 'pinia'
import api from '../services/api'

// Store de autenticación. La lógica de login/logout se completa en la Fase 1;
// aquí queda el esqueleto y el método me() que consulta la sesión actual.
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
