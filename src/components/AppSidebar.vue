<script setup>
import { computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import DemoBadge from './DemoBadge.vue'

const emit = defineEmits(['navigate'])
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
  router.push('/')
}

const linkBase =
  'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors'
const linkInactive = 'text-slate-300 hover:bg-slate-700/60 hover:text-white'
const linkActive = 'bg-primary-600 text-white'
</script>

<template>
  <!-- km-pin-neutrals: el sidebar es oscuro por diseño también en modo claro -->
  <aside class="km-pin-neutrals flex h-screen w-60 flex-col bg-slate-900 text-white">
    <div class="flex items-center justify-between px-5 py-5">
      <div class="inline-flex items-center gap-2">
        <RouterLink
          to="/"
          class="text-lg font-semibold tracking-tight transition-colors hover:text-primary-300"
          @click="emit('navigate')"
        >KoboManager</RouterLink>
        <DemoBadge variant="dark" />
      </div>
      <div class="flex items-center gap-1">
        <button
          class="hidden rounded-lg p-1.5 text-slate-300 hover:bg-slate-700/60 hover:text-white lg:block"
          :title="$t('nav.logout')"
          :aria-label="$t('nav.logout')"
          @click="onLogout"
        >
          <!-- Salir (flecha saliendo del marco) -->
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H3" />
          </svg>
        </button>
        <button
          class="rounded-lg p-1 text-slate-300 hover:bg-slate-700/60 hover:text-white lg:hidden"
          aria-label="Cerrar menú"
          @click="emit('navigate')"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      </div>
    </div>

    <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto px-3">
      <RouterLink
        :to="{ name: 'dashboard' }"
        :class="[linkBase, $route.name === 'dashboard' ? linkActive : linkInactive]"
      >
        {{ $t('nav.dashboard') }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'forms' }"
        :class="[linkBase, ['forms', 'submissions', 'submission-detail', 'stats', 'form-map'].includes($route.name) ? linkActive : linkInactive]"
      >
        {{ $t('nav.myForms') }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'notifications' }"
        :class="[linkBase, $route.name === 'notifications' ? linkActive : linkInactive]"
      >
        {{ $t('nav.notifications') }}
      </RouterLink>
      <RouterLink
        v-if="auth.auditSelfView"
        :to="{ name: 'my-activity' }"
        :class="[linkBase, $route.name === 'my-activity' ? linkActive : linkInactive]"
      >
        {{ $t('nav.myActivity') }}
      </RouterLink>

      <template v-if="auth.isAdmin">
        <p class="px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wider text-slate-500">
          {{ $t('nav.admin') }}
        </p>
        <RouterLink
          :to="{ name: 'admin-users' }"
          :class="[linkBase, $route.name === 'admin-users' ? linkActive : linkInactive]"
        >
          {{ $t('nav.users') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-accounts' }"
          :class="[linkBase, $route.name === 'admin-accounts' ? linkActive : linkInactive]"
        >
          {{ $t('nav.accounts') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-forms' }"
          :class="[linkBase, $route.name === 'admin-forms' ? linkActive : linkInactive]"
        >
          {{ $t('nav.forms') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-permissions' }"
          :class="[linkBase, $route.name === 'admin-permissions' ? linkActive : linkInactive]"
        >
          {{ $t('nav.permissions') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-shares' }"
          :class="[linkBase, $route.name === 'admin-shares' ? linkActive : linkInactive]"
        >
          {{ $t('nav.shares') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'admin-settings' }"
          :class="[linkBase, $route.name === 'admin-settings' ? linkActive : linkInactive]"
        >
          {{ $t('nav.settings') }}
        </RouterLink>
      </template>
    </nav>

    <div class="shrink-0 border-t border-slate-700/60 p-3">
      <RouterLink
        :to="{ name: 'profile' }"
        :title="$t('nav.goToProfile')"
        class="flex items-center gap-3 rounded-lg px-1 py-2 transition-colors hover:bg-slate-700/60"
        :class="{ 'bg-slate-700/60': $route.name === 'profile' }"
      >
        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-600 text-sm font-semibold">
          {{ initials }}
        </div>
        <div class="min-w-0">
          <p class="truncate text-sm font-medium">{{ auth.user?.name }}</p>
          <p class="truncate text-xs text-slate-400">{{ auth.user?.role }}</p>
        </div>
      </RouterLink>
    </div>
  </aside>
</template>
