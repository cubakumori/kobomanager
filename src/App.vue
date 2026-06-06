<script setup>
import { RouterView } from 'vue-router'
import AppLayout from './views/AppLayout.vue'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()

// Una ruta usa el shell autenticado si lo declara (meta.shell) o si es una página
// que vive en ambos contextos (meta.shellWhenAuthed) y hay sesión iniciada — p. ej.
// la Guía: pública con encabezado propio, pero dentro del panel cuando hay sesión.
const useShell = (route) =>
  route.meta.shell || (route.meta.shellWhenAuthed && auth.isAuthenticated)
</script>

<template>
  <RouterView v-slot="{ Component, route }">
    <AppLayout v-if="useShell(route)">
      <component :is="Component" />
    </AppLayout>
    <component :is="Component" v-else />
  </RouterView>
</template>
