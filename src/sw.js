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
import { NetworkFirst, CacheFirst, NetworkOnly } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'

// Modo 'prompt' (ver vite.config): el SW nuevo NO se activa solo (nada de
// skipWaiting incondicional), queda EN ESPERA hasta que el usuario acepta el aviso
// «versión nueva → recargar». La app pide el relevo con updateServiceWorker(), que
// envía este mensaje; solo entonces tomamos el control y la recarga trae el código nuevo.
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting()
})
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

// Cualquier URL del API con ticket de contraseña (?k=…) va SIEMPRE a la red y
// jamás a caché: la URL entera es la clave de Cache Storage y el ticket quedaría
// escrito en disco.
registerRoute(
  ({ url, request }) =>
    request.method === 'GET' && url.pathname.startsWith('/api/v1/') && url.searchParams.has('k'),
  new NetworkOnly(),
)

// Adjuntos AUTENTICADOS (binarios, pueden pesar): caché aparte y acotada.
// Excluye los del proxy público de enlaces compartidos (ruta /public/share/…):
//   - con CacheFirst sobrevivían 7 días a la revocación/caducidad del enlace en
//     el dispositivo del visitante (y el visitante anónimo nunca dispara la
//     limpieza de cachés del logout);
//   - la URL puede llevar el ticket de contraseña (?k=…), que no debe quedar
//     escrito en Cache Storage.
registerRoute(
  ({ url, request }) =>
    request.method === 'GET' &&
    /^\/api\/v1\/.*\/attachments\//.test(url.pathname) &&
    !url.pathname.startsWith('/api/v1/public/'),
  new CacheFirst({
    cacheName: 'km-att',
    plugins: [
      onlyCache200,
      new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 7 * 24 * 3600 }),
    ],
  }),
)

// Adjuntos PÚBLICOS (enlaces compartidos): siempre red primero y caché corta de
// respaldo SOLO para cortes de red; nunca se cachea una URL con ticket (?k=).
registerRoute(
  ({ url, request }) =>
    request.method === 'GET' &&
    url.pathname.startsWith('/api/v1/public/') &&
    /\/attachments\//.test(url.pathname) &&
    !url.searchParams.has('k'),
  new NetworkFirst({
    cacheName: 'km-att-pub',
    networkTimeoutSeconds: 6,
    plugins: [
      onlyCache200,
      new ExpirationPlugin({ maxEntries: 15, maxAgeSeconds: 3600 }),
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

// ── Web Push (avisos de envíos nuevos; ver api/lib/Notifier + api/lib/WebPush) ──
// El payload llega cifrado extremo a extremo y ya descifrado aquí por el navegador:
// { title, body, url, tag }. Solo recuento + enlace, nunca contenido del envío.
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    /* payload no-JSON: se muestra un aviso genérico */
  }
  event.waitUntil(
    self.registration.showNotification(data.title || 'KoboManager', {
      body: data.body || '',
      tag: data.tag || 'km-new-submissions', // reemplaza el aviso anterior en vez de apilar
      icon: '/pwa-192.png',
      badge: '/pwa-192.png',
      data: { url: data.url || '/forms' },
    }),
  )
})

// Al tocar la notificación: enfocar una pestaña abierta de la app (navegándola al
// destino) o abrir una nueva.
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = event.notification.data?.url || '/forms'
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if ('focus' in client) {
          client.focus()
          if ('navigate' in client) client.navigate(url)
          return
        }
      }
      return self.clients.openWindow(url)
    }),
  )
})
