import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

// Rutas según la sección 5 del plan. En la Fase 0 sólo existen las vistas
// base (Login/Dashboard); el resto se añade en fases posteriores.
const routes = [
  { path: '/', redirect: '/dashboard' },
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { public: true },
  },
  {
    path: '/dashboard',
    name: 'dashboard',
    component: () => import('../views/DashboardView.vue'),
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Guard global: resuelve la sesión una vez y aplica reglas public/admin.
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.checked) {
    await auth.fetchMe()
  }

  if (to.meta.public) {
    // Si ya está autenticado, no tiene sentido ver el login.
    if (to.name === 'login' && auth.isAuthenticated) return { name: 'dashboard' }
    return true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.requiresAdmin && !auth.isAdmin) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
