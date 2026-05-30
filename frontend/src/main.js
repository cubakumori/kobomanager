import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './services/api'
import { useAuthStore } from './stores/auth'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// Ante un 401 global: limpiar sesión y mandar al login.
setUnauthorizedHandler(() => {
  const auth = useAuthStore(pinia)
  auth.clear()
  if (router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login' })
  }
})

app.mount('#app')
