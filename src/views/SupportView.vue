<script setup>
import { useAuthStore } from '../stores/auth'
import PublicLayout from '../components/PublicLayout.vue'
import SupportContent from '../components/SupportContent.vue'

const auth = useAuthStore()
</script>

<template>
  <!-- Con sesión: dentro del shell autenticado (AppLayout lo envuelve). -->
  <SupportContent v-if="auth.isAuthenticated" class="mx-auto w-full max-w-3xl px-6 py-8" />

  <!-- Sin sesión: página pública con el mismo encabezado que la portada. -->
  <PublicLayout v-else>
    <main class="mx-auto w-full max-w-3xl flex-1 px-6 py-10">
      <SupportContent />
    </main>
  </PublicLayout>
</template>
