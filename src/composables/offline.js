import { ref } from 'vue'

/**
 * Conectividad — estado global (módulo singleton).
 *
 * `isOnline` sigue los eventos online/offline del navegador; App.vue muestra un
 * aviso «sin conexión» cuando se pierde la red. Con la PWA activa (build), los
 * GET del API ya consultados se sirven desde la caché del service worker
 * (network-first, ver vite.config.js), así que la app sigue siendo consultable.
 */
const isOnline = ref(navigator.onLine)
window.addEventListener('online', () => { isOnline.value = true })
window.addEventListener('offline', () => { isOnline.value = false })

export function useOnline() {
  return { isOnline }
}

/**
 * Borra las cachés de DATOS del service worker (API y adjuntos). Se llama al
 * cerrar sesión para no dejar datos sensibles en el dispositivo; el shell de la
 * app (precache de Workbox) se conserva. Best-effort: sin SW no hace nada.
 */
export async function clearDataCaches() {
  if (!('caches' in window)) return
  try {
    await Promise.all([caches.delete('km-api'), caches.delete('km-att')])
  } catch {
    /* almacenamiento no disponible */
  }
}
