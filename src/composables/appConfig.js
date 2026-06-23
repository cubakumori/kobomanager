import { ref } from 'vue'
import { publicApi } from '../services/api'

/**
 * Ajustes públicos de la interfaz (módulo singleton) — hoy, el congelado de
 * columnas en tablas (`table_freeze`: 'first'|'none', ajuste global del admin).
 *
 * Se lee de `GET /config` al arrancar y se cachea en localStorage para que las
 * tablas pinten bien desde el primer render (sin salto al llegar la respuesta).
 * El tema oscuro tiene su propio módulo (darkMode.js) por su lógica especial
 * de preferencia local; este se queda con el resto de ajustes de UI.
 */
const CACHE_KEY = 'km.cfg.tableFreeze'
const VALID = ['first', 'none']

const cached = localStorage.getItem(CACHE_KEY)
const tableFreeze = ref(VALID.includes(cached) ? cached : 'first')

// Modo demo (instancia pública de demostración): banner global + acciones
// sensibles deshabilitadas. Sin caché local: que un banner de demo nunca se
// quede pegado en una instancia normal (y viceversa); un parpadeo es aceptable.
const demoMode = ref(false)
const demoResetMinutes = ref(60)
const demoLoginAdmin = ref('')
const demoLoginViewer = ref('')

// Enlaces externos de la parte pública (repo, donaciones). Vacío = se ocultan.
// Sin caché: solo se usan en páginas públicas, donde el parpadeo es irrelevante.
const links = ref({ repo: '', paypal: '', kofi: '' })

// Visibilidad de la parte pública de escaparate (ajustes globales del admin).
const supportPageEnabled = ref(true)
const landingCtaEnabled = ref(true)

// Promesa de «config lista»: la usa el guard del router para decidir rutas
// públicas (p. ej. /apoyar) sin depender del orden de carga.
const configReady = publicApi
  .get('/config')
  .then(({ data }) => {
    const v = data.data.table_freeze
    if (VALID.includes(v)) {
      tableFreeze.value = v
      localStorage.setItem(CACHE_KEY, v)
    }
    demoMode.value = !!data.data.demo_mode
    demoResetMinutes.value = Number(data.data.demo_reset_minutes) || 60
    demoLoginAdmin.value = String(data.data.demo_login_admin || '')
    demoLoginViewer.value = String(data.data.demo_login_viewer || '')
    const l = data.data.links || {}
    links.value = {
      repo: String(l.repo || ''),
      paypal: String(l.paypal || ''),
      kofi: String(l.kofi || ''),
    }
    if (data.data.support_page_enabled != null) supportPageEnabled.value = !!data.data.support_page_enabled
    if (data.data.landing_cta_enabled != null) landingCtaEnabled.value = !!data.data.landing_cta_enabled
  })
  .catch(() => { /* sin red: vale el valor cacheado o el default */ })

/** ¿Se congela la primera columna de las tablas? (reactivo) */
export function useTableFreeze() {
  const freezeFirst = () => tableFreeze.value === 'first'
  return { tableFreeze, freezeFirst }
}

/** Modo demo (reactivo): flag, minutos del ciclo de reset y credenciales por rol. */
export function useDemoMode() {
  return { demoMode, demoResetMinutes, demoLoginAdmin, demoLoginViewer }
}

/** Enlaces externos públicos (reactivo): repo + donaciones. Vacío = ocultar. */
export function usePublicLinks() {
  const hasDonate = () => !!(links.value.paypal || links.value.kofi)
  return { links, hasDonate }
}

/** Visibilidad de la parte pública de escaparate (página Apoyar + CTA de portada). */
export function usePublicSurface() {
  return { supportPageEnabled, landingCtaEnabled }
}

/** Promesa que resuelve cuando /config se ha cargado (para el guard del router). */
export { configReady }
