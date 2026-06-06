<script setup>
import { useAuthStore } from '../stores/auth'
import PublicLayout from '../components/PublicLayout.vue'
import GuideContent from '../components/GuideContent.vue'

const auth = useAuthStore()
</script>

<template>
  <!-- Con sesión: se renderiza dentro del shell autenticado (AppLayout lo envuelve),
       junto al resto del contenido administrativo. -->
  <GuideContent v-if="auth.isAuthenticated" />

  <!-- Sin sesión: página pública con el mismo encabezado que la portada. -->
  <PublicLayout v-else>
    <main class="mx-auto w-full max-w-3xl flex-1 px-6 py-8">
      <GuideContent />
    </main>
  </PublicLayout>
</template>
