<script setup>
import { RouterView } from 'vue-router'
import AppLayout from './views/AppLayout.vue'
import { useAuthStore } from './stores/auth'
import { useOnline } from './composables/offline'

const auth = useAuthStore()

// Aviso global de conectividad: con la PWA, lo ya consultado sigue legible
// desde la caché del service worker, pero conviene saber que no hay red.
const { isOnline } = useOnline()

// Una ruta usa el shell autenticado si lo declara (meta.shell) o si es una página
// que vive en ambos contextos (meta.shellWhenAuthed) y hay sesión iniciada — p. ej.
// la Guía: pública con encabezado propio, pero dentro del panel cuando hay sesión.
const useShell = (route) =>
  route.meta.shell || (route.meta.shellWhenAuthed && auth.isAuthenticated)
</script>

<template>
  <div
    v-if="!isOnline"
    class="sticky top-0 z-[60] bg-amber-500 px-4 py-1.5 text-center text-xs font-semibold text-white dark:bg-amber-600"
    role="status"
  >
    {{ $t('common.offline') }}
  </div>
  <RouterView v-slot="{ Component, route }">
    <AppLayout v-if="useShell(route)">
      <component :is="Component" />
    </AppLayout>
    <component :is="Component" v-else />
  </RouterView>
</template>
