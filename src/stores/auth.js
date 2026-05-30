import { defineStore } from 'pinia'
import api from '../services/api'

// Mensajes más claros para ciertos códigos de error (sección 4.5 del backend).
// Si el código no está aquí, se usa el mensaje que envía el backend.
const ERROR_MESSAGES = {
  KOBO_TIMEOUT: 'No se pudo contactar con KoboToolbox. Inténtalo de nuevo en unos minutos.',
  KOBO_UNAUTHORIZED: 'El token de la cuenta Kobo no es válido o ha caducado. Revísalo en «Cuentas Kobo».',
  KOBO_RATE_LIMIT: 'KoboToolbox está limitando las peticiones. Espera un momento y reintenta.',
  KOBO_FORM_NOT_FOUND: 'El formulario ya no existe en KoboToolbox.',
  KOBO_SUBMISSION_NOT_FOUND: 'El envío ya no existe en KoboToolbox.',
  AUTH_RATE_LIMITED: 'Demasiados intentos de inicio de sesión. Espera un minuto e inténtalo de nuevo.',
  AUTH_INSUFFICIENT_PERMISSIONS: 'No tienes permisos para realizar esta acción.',
}

// Traduce un error de Axios a un mensaje legible usando el formato estándar del backend.
export function apiError(e, fallback = 'Ha ocurrido un error') {
  const code = e?.response?.data?.error?.code
  if (code && ERROR_MESSAGES[code]) return ERROR_MESSAGES[code]
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
