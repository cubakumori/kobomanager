import { defineStore } from 'pinia'
import api from '../services/api'
import i18n, { setLocale } from '../i18n'
import { clearDataCaches } from '../composables/offline'
import { useSyncOnLogin } from '../composables/appConfig'

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
      // skipAuthRedirect: un login FALLIDO devuelve 401 y el interceptor global
      // no debe sacar al usuario del formulario (modal de la portada o /login);
      // el propio formulario muestra el error.
      const { data } = await api.post('/auth/login', { email, password }, { skipAuthRedirect: true })
      // Con 2FA activo el backend NO abre sesión todavía: devuelve un reto para
      // el segundo paso (loginTotp). El formulario muestra el campo del código.
      if (data.data?.totp_required) {
        return { totp_required: true, challenge: data.data.challenge }
      }
      return this._completeLogin(data.data)
    },
    // Segundo paso del login con 2FA: reto + código TOTP (o de recuperación).
    async loginTotp(challenge, code) {
      const { data } = await api.post('/auth/login/totp', { challenge, code }, { skipAuthRedirect: true })
      return this._completeLogin(data.data)
    },
    _completeLogin(userData) {
      this.user = userData
      this.checked = true
      setLocale(this.user?.locale)
      // Ajuste global «sincronizar al iniciar sesión»: dispara EN SEGUNDO PLANO la
      // puesta al día de los formularios del usuario (>10 min sin sincronizar), sin
      // bloquear el login ni molestar si falla (el cron o el próximo login recogen).
      const { syncOnLogin } = useSyncOnLogin()
      if (syncOnLogin.value) {
        api.post('/forms/sync-stale').catch(() => {})
      }
      return this.user
    },
    async logout() {
      try {
        // Sin red, el POST falla: da igual — la limpieza local de abajo es lo que
        // cierra la sesión a efectos de UI (sin el catch, el error se propagaba y
        // el router.push del llamador nunca corría → shell autenticado roto).
        await api.post('/auth/logout')
      } catch {
        /* best-effort: la cookie caduca sola en el servidor */
      } finally {
        this.user = null
        // PWA: al cerrar sesión se borran las cachés de datos del service
        // worker (API/adjuntos) para no dejar datos sensibles en el dispositivo.
        clearDataCaches()
        // Preferencias por-formulario con datos potencialmente personales (término
        // de búsqueda persistido): no deben heredarse entre usuarios del mismo
        // navegador. Las de columnas/tema (km.cols.*, km.theme…) son inocuas y se
        // conservan a propósito.
        try {
          for (const k of Object.keys(localStorage)) {
            if (k.startsWith('km.view.') || k.startsWith('km.filter.')) localStorage.removeItem(k)
          }
        } catch { /* almacenamiento no disponible */ }
      }
    },
    async fetchMe() {
      this.loading = true
      try {
        const { data } = await api.get('/auth/me', { skipAuthRedirect: true })
        this.user = data.data
        setLocale(this.user?.locale)
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
