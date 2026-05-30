import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

// Las vistas de viewer (forms/submissions/stats)
// se añaden en fases posteriores.
const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { public: true },
  },
  {
    // Layout autenticado (sidebar + contenido) para todo lo que requiere sesión.
    path: '/',
    component: () => import('../views/AppLayout.vue'),
    children: [
      { path: '', redirect: '/dashboard' },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('../views/DashboardView.vue'),
      },
      {
        path: 'forms',
        name: 'forms',
        component: () => import('../views/MyFormsView.vue'),
      },
      {
        path: 'forms/:id/submissions',
        name: 'submissions',
        component: () => import('../views/SubmissionsView.vue'),
      },
      {
        path: 'forms/:id/submissions/:subId',
        name: 'submission-detail',
        component: () => import('../views/SubmissionDetailView.vue'),
      },
      {
        path: 'forms/:id/stats',
        name: 'stats',
        component: () => import('../views/StatsView.vue'),
      },
      {
        path: 'profile',
        name: 'profile',
        component: () => import('../views/ProfileView.vue'),
      },
      {
        path: 'about-kobo',
        name: 'about-kobo',
        component: () => import('../views/AboutKoboView.vue'),
      },
      {
        path: 'admin/users',
        name: 'admin-users',
        component: () => import('../views/admin/UsersView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/accounts',
        name: 'admin-accounts',
        component: () => import('../views/admin/AccountsView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/forms',
        name: 'admin-forms',
        component: () => import('../views/admin/FormsView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/permissions',
        name: 'admin-permissions',
        component: () => import('../views/admin/PermissionsView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/settings',
        name: 'admin-settings',
        component: () => import('../views/admin/SettingsView.vue'),
        meta: { requiresAdmin: true },
      },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
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
