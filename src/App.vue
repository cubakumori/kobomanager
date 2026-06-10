<script setup>
import { RouterView } from 'vue-router'
import AppLayout from './views/AppLayout.vue'
import { useAuthStore } from './stores/auth'
import { useOnline } from './composables/offline'
import { useDemoMode } from './composables/appConfig'

const auth = useAuthStore()

// Aviso global de conectividad: con la PWA, lo ya consultado sigue legible
// desde la caché del service worker, pero conviene saber que no hay red.
const { isOnline } = useOnline()

// Instancia de demostración: banner global con el ciclo de reset y, si la
// config lo da, las credenciales de entrada.
const { demoMode, demoResetMinutes, demoLoginHint } = useDemoMode()

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
  <div
    v-if="demoMode"
    class="sticky top-0 z-[59] bg-sky-600 px-4 py-1.5 text-center text-xs font-semibold text-white dark:bg-sky-700"
    role="status"
  >
    {{ $t('common.demoBanner', { minutes: demoResetMinutes }) }}
    <span v-if="demoLoginHint">{{ $t('common.demoBannerLogin', { hint: demoLoginHint }) }}</span>
  </div>
  <RouterView v-slot="{ Component, route }">
    <AppLayout v-if="useShell(route)">
      <component :is="Component" />
    </AppLayout>
    <component :is="Component" v-else />
  </RouterView>
</template>
