import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

// Rutas planas. Las públicas llevan meta.public; las del área autenticada llevan
// meta.shell (App.vue las envuelve en el layout con sidebar).
const shell = (extra = {}) => ({ shell: true, ...extra })

const routes = [
  { path: '/', name: 'landing', component: () => import('../views/LandingView.vue'), meta: { public: true } },
  { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true } },
  { path: '/forgot-password', name: 'forgot-password', component: () => import('../views/ForgotPasswordView.vue'), meta: { public: true } },
  { path: '/reset-password', name: 'reset-password', component: () => import('../views/ResetPasswordView.vue'), meta: { public: true } },
  { path: '/guide', name: 'guide', component: () => import('../views/GuideView.vue'), meta: { public: true, shellWhenAuthed: true } },
  { path: '/apoyar', name: 'support', component: () => import('../views/SupportView.vue'), meta: { public: true, shellWhenAuthed: true } },
  { path: '/s/:token', name: 'share', component: () => import('../views/PublicShareView.vue'), meta: { public: true } },

  { path: '/dashboard', name: 'dashboard', component: () => import('../views/DashboardView.vue'), meta: shell() },
  { path: '/forms', name: 'forms', component: () => import('../views/MyFormsView.vue'), meta: shell() },
  { path: '/forms/:id/submissions', name: 'submissions', component: () => import('../views/SubmissionsView.vue'), meta: shell() },
  { path: '/forms/:id/submissions/:subId', name: 'submission-detail', component: () => import('../views/SubmissionDetailView.vue'), meta: shell() },
  { path: '/forms/:id/stats', name: 'stats', component: () => import('../views/StatsView.vue'), meta: shell() },
  { path: '/forms/:id/map', name: 'form-map', component: () => import('../views/FormMapView.vue'), meta: shell() },
  { path: '/profile', name: 'profile', component: () => import('../views/ProfileView.vue'), meta: shell() },
  { path: '/notifications', name: 'notifications', component: () => import('../views/NotificationsView.vue'), meta: shell() },
  { path: '/activity', name: 'my-activity', component: () => import('../views/MyActivityView.vue'), meta: shell() },
  { path: '/about-kobo', name: 'about-kobo', component: () => import('../views/AboutKoboView.vue'), meta: shell() },

  { path: '/admin/users', name: 'admin-users', component: () => import('../views/admin/UsersView.vue'), meta: shell({ requiresAdmin: true }) },
  { path: '/admin/accounts', name: 'admin-accounts', component: () => import('../views/admin/AccountsView.vue'), meta: shell({ requiresAdmin: true }) },
  { path: '/admin/forms', name: 'admin-forms', component: () => import('../views/admin/FormsView.vue'), meta: shell({ requiresAdmin: true }) },
  { path: '/admin/permissions', name: 'admin-permissions', component: () => import('../views/admin/PermissionsView.vue'), meta: shell({ requiresAdmin: true }) },
  { path: '/admin/shares', name: 'admin-shares', component: () => import('../views/admin/SharesView.vue'), meta: shell({ requiresAdmin: true }) },
  { path: '/admin/audit', name: 'admin-audit', component: () => import('../views/admin/AuditView.vue'), meta: shell({ requiresAdmin: true }) },
  { path: '/admin/messages', name: 'admin-messages', component: () => import('../views/admin/MessagesView.vue'), meta: shell({ requiresAdmin: true }) },
  { path: '/admin/settings', name: 'admin-settings', component: () => import('../views/admin/SettingsView.vue'), meta: shell({ requiresAdmin: true }) },

  { path: '/:pathMatch(.*)*', redirect: '/' },
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

  // Públicas (landing, login): accesibles siempre. Si ya hay sesión, el login
  // redirige al panel (la landing se deja ver, con CTA "Ir al panel").
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
