import { ref, computed, watchEffect } from 'vue'

/**
 * Modo oscuro — estado global (módulo singleton).
 *
 * Preferencia de tres estados: 'light' | 'dark' | 'auto' (sigue al sistema),
 * persistida en localStorage por dispositivo (clave `km.theme`). Aplica/quita
 * la clase `dark` en <html>; los colores los resuelve src/style.css (override
 * de los neutros bajo `.dark`). index.html lleva un script inline equivalente
 * para aplicar la clase ANTES de montar la app (sin destello).
 */
const STORAGE_KEY = 'km.theme'
const MODES = ['light', 'dark', 'auto']

const stored = localStorage.getItem(STORAGE_KEY)
const pref = ref(MODES.includes(stored) ? stored : 'auto')

const media = window.matchMedia('(prefers-color-scheme: dark)')
const systemDark = ref(media.matches)
media.addEventListener('change', (e) => { systemDark.value = e.matches })

const isDark = computed(() => pref.value === 'dark' || (pref.value === 'auto' && systemDark.value))

watchEffect(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
})

function setPref(mode) {
  pref.value = MODES.includes(mode) ? mode : 'auto'
  localStorage.setItem(STORAGE_KEY, pref.value)
}

/** Ciclo del botón: claro → oscuro → auto → claro. */
function cycle() {
  setPref(MODES[(MODES.indexOf(pref.value) + 1) % MODES.length])
}

export function useDarkMode() {
  return { pref, isDark, setPref, cycle }
}
