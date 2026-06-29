/**
 * Service worker de KoboManager (PWA) — modo injectManifest de vite-plugin-pwa.
 *
 * Estrategia:
 *   - SHELL precacheado (build completo) → la app abre sin red.
 *   - Navegaciones del SPA → index.html del precache (excepto /api: un CSV o
 *     adjunto abierto en pestaña es una navegación al API y debe ir a la red).
 *   - GET del API → NetworkFirst con timeout de 4 s; los ADJUNTOS (binarios)
 *     van en caché aparte y acotada (CacheFirst).
 *   - Sin conexión / timeout → se sirve lo último visto (caché). Pero un 5xx del
 *     servidor SÍ se devuelve a la app (no se enmascara como fallo de red), para que
 *     el error real sea visible en vez de un «no-response» opaco. Solo se cachean
 *     respuestas 200 (nunca errores ni redirecciones).
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

// Solo se guardan respuestas 200 (no se cachean errores ni redirecciones). Los 5xx NO
// se tratan como fallo de red: se devuelven a la app para que el error real sea visible
// (un deploy sin migrar daba «Unknown column …» → 500; antes el SW lo ocultaba como
// no-response/ERR_FAILED). Sin red/timeout, NetworkFirst ya cae a la caché por su cuenta.
const onlyCache200 = {
  cacheWillUpdate: async ({ response }) => (response.status === 200 ? response : null),
}

// Adjuntos (binarios, pueden pesar): caché aparte y acotada.
registerRoute(
  ({ url, request }) =>
    request.method === 'GET' && /^\/api\/v1\/.*\/attachments\//.test(url.pathname),
  new CacheFirst({
    cacheName: 'km-att',
    plugins: [
      onlyCache200,
      new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 7 * 24 * 3600 }),
    ],
  }),
)

// Resto del API (solo GET): red primero; sin red o timeout → lo último visto (un 5xx
// se devuelve tal cual a la app, no se enmascara).
registerRoute(
  ({ url, request }) => request.method === 'GET' && url.pathname.startsWith('/api/v1/'),
  new NetworkFirst({
    cacheName: 'km-api',
    networkTimeoutSeconds: 4,
    plugins: [
      onlyCache200,
      new ExpirationPlugin({ maxEntries: 300, maxAgeSeconds: 7 * 24 * 3600 }),
    ],
  }),
)
