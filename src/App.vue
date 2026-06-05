<script setup>
import { RouterView } from 'vue-router'
import AppLayout from './views/AppLayout.vue'
</script>

<template>
  <!-- Las rutas con meta.shell se envuelven en el layout autenticado (sidebar);
       las públicas (landing, login) se renderizan sin él. -->
  <RouterView v-slot="{ Component, route }">
    <AppLayout v-if="route.meta.shell">
      <component :is="Component" />
    </AppLayout>
    <component :is="Component" v-else />
  </RouterView>
</template>
