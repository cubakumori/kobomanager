import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { setUnauthorizedHandler, setTotpEnrollHandler } from './services/api'
import { useAuthStore } from './stores/auth'
import './composables/darkMode' // aplica la clase `dark` (preferencia/sistema) desde el arranque

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(i18n)
app.use(router)

// Ante un 401 global: limpiar sesión y mandar al login conservando el destino
// (misma convención `?redirect=` que el guard del router: tras reloguear, el
// usuario vuelve a la página en la que le caducó la sesión).
setUnauthorizedHandler(() => {
  const auth = useAuthStore(pinia)
  auth.clear()
  const current = router.currentRoute.value
  if (current.name !== 'login') {
    router.push({ name: 'login', query: { redirect: current.fullPath } })
  }
})

// Política de 2FA obligatorio: la API responde TOTP_ENROLL_REQUIRED a quien está
// obligado y aún no lo activó → a su perfil, directo a la tarjeta del 2FA.
setTotpEnrollHandler(() => {
  if (router.currentRoute.value.name !== 'profile') {
    router.push({ name: 'profile', query: { totp: 'required' } })
  }
})

app.mount('#app')
