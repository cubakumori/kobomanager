<script setup>
import { computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

const initials = computed(() =>
  (auth.user?.name ?? '?')
    .split(' ')
    .map((w) => w[0])
    .slice(0, 2)
    .join('')
    .toUpperCase(),
)

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}

const linkBase =
  'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors'
const linkInactive = 'text-slate-300 hover:bg-slate-700/60 hover:text-white'
const linkActive = 'bg-blue-600 text-white'
</script>

<template>
  <aside class="flex h-screen w-60 flex-col bg-slate-900 text-white">
    <div class="px-5 py-5 text-lg font-semibold tracking-tight">KoboManager</div>

    <nav class="flex-1 space-y-1 px-3">
      <RouterLink
        :to="{ name: 'dashboard' }"
        :class="[linkBase, $route.name === 'dashboard' ? linkActive : linkInactive]"
      >
        Dashboard
      </RouterLink>
      <RouterLink
        :to="{ name: 'forms' }"
        :class="[linkBase, ['forms', 'submissions', 'submission-detail'].includes($route.name) ? linkActive : linkInactive]"
      >
        Mis formularios
      </RouterLink>

      <template v-if="auth.isAdmin">
        <p class="px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wider text-slate-500">
          Administración
        </p>
        <RouterLink
          :to="{ name: 'admin-users' }"
          :class="[linkBase, $route.name === 'admin-users' ? linkActive : linkInactive]"
        >
          Usuarios
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-accounts' }"
          :class="[linkBase, $route.name === 'admin-accounts' ? linkActive : linkInactive]"
        >
          Cuentas Kobo
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-forms' }"
          :class="[linkBase, $route.name === 'admin-forms' ? linkActive : linkInactive]"
        >
          Formularios
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-permissions' }"
          :class="[linkBase, $route.name === 'admin-permissions' ? linkActive : linkInactive]"
        >
          Permisos
        </RouterLink>
      </template>
    </nav>

    <div class="border-t border-slate-700/60 p-3">
      <RouterLink
        :to="{ name: 'profile' }"
        :title="'Ir a mi perfil'"
        class="mb-2 flex items-center gap-3 rounded-lg px-1 py-2 transition-colors hover:bg-slate-700/60"
        :class="{ 'bg-slate-700/60': $route.name === 'profile' }"
      >
        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold">
          {{ initials }}
        </div>
        <div class="min-w-0">
          <p class="truncate text-sm font-medium">{{ auth.user?.name }}</p>
          <p class="truncate text-xs text-slate-400">{{ auth.user?.role }}</p>
        </div>
      </RouterLink>
      <button
        class="w-full rounded-lg px-3 py-2 text-sm font-medium text-slate-300 hover:bg-slate-700/60 hover:text-white"
        @click="onLogout"
      >
        Cerrar sesión
      </button>
    </div>
  </aside>
</template>
