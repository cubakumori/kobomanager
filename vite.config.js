import { readFileSync } from 'node:fs'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

// Versión del build (de package.json) → expuesta como `__APP_VERSION__` para
// mostrarla en el footer. Refleja exactamente la versión empaquetada/desplegada.
const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf-8'))

// https://vite.dev/config/
export default defineConfig({
  define: {
    __APP_VERSION__: JSON.stringify(pkg.version),
  },
  plugins: [
    vue(),
    tailwindcss(),
    // PWA: app instalable + tolerante a mala conectividad.
    //   - El SHELL (HTML/JS/CSS/iconos) se precachea → la app abre al instante
    //     incluso sin red.
    //   - Los GET del API se cachean en runtime (network-first con timeout):
    //     lo ya consultado puede RELEERSE sin conexión o con el servidor caído.
    //     Las escrituras siguen requiriendo red. La caché de datos se limpia al
    //     cerrar sesión (src/composables/offline.js).
    //   - Las estrategias viven en un SW propio (src/sw.js, modo injectManifest):
    //     offline/timeout → caché; un 5xx se devuelve a la app (no se enmascara).
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.js',
      registerType: 'autoUpdate',
      manifest: {
        name: 'KoboManager',
        short_name: 'KoboManager',
        description: 'Management layer for KoboToolbox data',
        theme_color: '#0f172a',
        background_color: '#ffffff',
        display: 'standalone',
        icons: [
          { src: '/pwa-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/pwa-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/pwa-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      // El SW solo se genera en build (en dev molestaría cacheando código vivo).
      devOptions: { enabled: false },
    }),
  ],
  server: {
    proxy: {
      // En desarrollo, /api se reenvía al backend PHP.
      '/api': {
        target: 'http://127.0.0.1:8787',
        changeOrigin: true,
      },
    },
  },
})
