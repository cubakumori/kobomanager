<script setup>
import { onMounted, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import PublicLayout from '../components/PublicLayout.vue'
import GuideContent from '../components/GuideContent.vue'

const auth = useAuthStore()
const route = useRoute()

// Deep-link con hash (p. ej. /guide#risk desde la landing): se desplaza al ancla.
// Se usa window.scrollTo sobre la posición absoluta del elemento (no scrollIntoView,
// que en la navegación SPA acaba moviendo un contenedor interno en vez de la ventana)
// con un offset que deja hueco bajo la barra superior. Salto instantáneo (el scroll
// suave no se anima en pestañas en segundo plano). Se reintenta unas cuantas veces
// porque en navegación SPA el layout puede asentarse tras el montaje.
onMounted(async () => {
  if (!route.hash) return
  await nextTick()
  const HEADER_OFFSET = 96
  const scroll = () => {
    const el = document.querySelector(route.hash)
    if (!el) return
    window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - HEADER_OFFSET)
  }
  scroll()
  for (const delay of [150, 350, 600]) setTimeout(scroll, delay)
})
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
