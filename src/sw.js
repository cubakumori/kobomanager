/**
 * Service worker de KoboManager (PWA) — modo injectManifest de vite-plugin-pwa.
 *
 * Estrategia:
 *   - SHELL precacheado (build completo) → la app abre sin red.
 *   - Navegaciones del SPA → index.html del precache (excepto /api: un CSV o
 *     adjunto abierto en pestaña es una navegación al API y debe ir a la red).
 *   - GET del API → NetworkFirst con timeout de 4 s; los ADJUNTOS (binarios)
 *     van en caché aparte y acotada (CacheFirst).
 *   - Un 5xx del servidor se trata COMO FALLO de red (plugin propio): tanto sin
 *     conexión como con el servidor caído se sirve lo último visto. Solo se
 *     cachean respuestas 200.
 *
 * La caché de datos (km-api/km-att) se borra al cerrar sesión desde la app
 * (src/composables/offline.js); el precache del shell se conserva.
 */
import { clientsClaim } from 'workbox-core'
import { precacheAndRoute, cleanupOutdatedCaches, createHandlerBoundToURL } from 'workbox-precaching'
import { registerRoute, NavigationRoute } from 'workbox-routing'
import { NetworkFirst, CacheFirst } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'

self.skipWaiting()
clientsClaim()

precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()

// Rutas del SPA → shell precacheado.
registerRoute(
  new NavigationRoute(createHandlerBoundToURL('/index.html'), {
    denylist: [/^\/api\//],
  }),
)

// Errores de servidor (5xx) = fallo de red → la estrategia cae a la caché.
// Solo se guardan respuestas 200 (no se cachean errores ni redirecciones).
const serverErrorsAsFailures = {
  fetchDidSucceed: async ({ response }) => {
    if (response.status >= 500) {
      throw new Error(`server error ${response.status}`)
    }
    return response
  },
  cacheWillUpdate: async ({ response }) => (response.status === 200 ? response : null),
}

// Adjuntos (binarios, pueden pesar): caché aparte y acotada.
registerRoute(
  ({ url, request }) =>
    request.method === 'GET' && /^\/api\/v1\/.*\/attachments\//.test(url.pathname),
  new CacheFirst({
    cacheName: 'km-att',
    plugins: [
      serverErrorsAsFailures,
      new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 7 * 24 * 3600 }),
    ],
  }),
)

// Resto del API (solo GET): red primero; sin red, timeout o 5xx → lo último visto.
registerRoute(
  ({ url, request }) => request.method === 'GET' && url.pathname.startsWith('/api/v1/'),
  new NetworkFirst({
    cacheName: 'km-api',
    networkTimeoutSeconds: 4,
    plugins: [
      serverErrorsAsFailures,
      new ExpirationPlugin({ maxEntries: 300, maxAgeSeconds: 7 * 24 * 3600 }),
    ],
  }),
)
