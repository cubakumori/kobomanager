import { ref, computed, watchEffect } from 'vue'
import { publicApi } from '../services/api'

/**
 * Modo oscuro — estado global (módulo singleton).
 *
 * Preferencia de cuatro estados: 'light' | 'dark' | 'auto' (sigue al sistema) |
 * null (sin elección → manda el «Tema por defecto» del sitio, ajuste de admin).
 * La elección del usuario se persiste en localStorage por dispositivo (clave
 * `km.theme`) y SIEMPRE gana sobre el default. Aplica/quita la clase `dark` en
 * <html>; los colores los resuelve src/style.css (override de neutros bajo `.dark`).
 *
 * El default del sitio y la visibilidad del selector llegan de `GET /config`
 * (fetch al arrancar) y se CACHEAN en localStorage (`km.theme.default`) para que
 * el script inline de index.html pueda aplicar el tema correcto sin destello
 * también en visitas posteriores.
 */
const STORAGE_KEY = 'km.theme'
const DEFAULT_CACHE_KEY = 'km.theme.default'
const MODES = ['light', 'dark', 'auto']

const stored = localStorage.getItem(STORAGE_KEY)
const pref = ref(MODES.includes(stored) ? stored : null) // null = sin elección propia

const cachedDefault = localStorage.getItem(DEFAULT_CACHE_KEY)
const serverDefault = ref(MODES.includes(cachedDefault) ? cachedDefault : 'auto')
const showToggle = ref(localStorage.getItem(DEFAULT_CACHE_KEY + '.toggle') !== '0')

const media = window.matchMedia('(prefers-color-scheme: dark)')
const systemDark = ref(media.matches)
media.addEventListener('change', (e) => { systemDark.value = e.matches })

/** Modo efectivo: la elección del usuario o, si no la hay, el default del sitio. */
const effective = computed(() => pref.value ?? serverDefault.value)
const isDark = computed(() => effective.value === 'dark' || (effective.value === 'auto' && systemDark.value))

watchEffect(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
})

// Default del sitio + visibilidad del selector (público, best-effort).
publicApi
  .get('/config')
  .then(({ data }) => {
    const d = data.data
    if (MODES.includes(d.default_theme)) {
      serverDefault.value = d.default_theme
      localStorage.setItem(DEFAULT_CACHE_KEY, d.default_theme)
    }
    showToggle.value = d.show_theme_toggle !== false
    localStorage.setItem(DEFAULT_CACHE_KEY + '.toggle', showToggle.value ? '1' : '0')
  })
  .catch(() => { /* sin red/API: se queda el default cacheado o 'auto' */ })

/** Fija la preferencia del usuario; null/'' la borra (vuelve al default del sitio). */
function setPref(mode) {
  if (MODES.includes(mode)) {
    pref.value = mode
    localStorage.setItem(STORAGE_KEY, mode)
  } else {
    pref.value = null
    localStorage.removeItem(STORAGE_KEY)
  }
}

/** Ciclo del botón: claro → oscuro → auto → claro (parte del modo efectivo). */
function cycle() {
  setPref(MODES[(MODES.indexOf(effective.value) + 1) % MODES.length])
}

export function useDarkMode() {
  return { pref, effective, isDark, showToggle, setPref, cycle }
}
