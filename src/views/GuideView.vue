<script setup>
import { ref, watch, onMounted, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { loadGuideMessages } from '../i18n'
import PublicLayout from '../components/PublicLayout.vue'
import GuideContent from '../components/GuideContent.vue'
import Skeleton from '../components/Skeleton.vue'

const auth = useAuthStore()
const route = useRoute()
const { locale } = useI18n()

// El catálogo de la Guía (el más pesado) va FUERA del bundle inicial: se carga
// aquí bajo demanda —también al cambiar de idioma con la Guía abierta— y no se
// renderiza nada hasta tenerlo (sin claves crudas). Ver i18n/index.js.
const guideReady = ref(false)
async function ensureGuide(l) {
  guideReady.value = (await loadGuideMessages(l)) || guideReady.value
}
watch(locale, (l) => ensureGuide(l))

// Deep-link con hash (p. ej. /guide#risk desde la landing): se desplaza al ancla.
// Se usa window.scrollTo sobre la posición absoluta del elemento (no scrollIntoView,
// que en la navegación SPA acaba moviendo un contenedor interno en vez de la ventana)
// con un offset que deja hueco bajo la barra superior. Salto instantáneo (el scroll
// suave no se anima en pestañas en segundo plano). Se reintenta unas cuantas veces
// porque en navegación SPA el layout puede asentarse tras el montaje. Espera al
// catálogo diferido: sin contenido no hay ancla a la que saltar.
onMounted(async () => {
  await ensureGuide(locale.value)
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
  <template v-if="auth.isAuthenticated">
    <GuideContent v-if="guideReady" />
    <Skeleton v-else variant="cards" :count="3" />
  </template>

  <!-- Sin sesión: página pública con el mismo encabezado que la portada. -->
  <PublicLayout v-else>
    <main class="mx-auto w-full max-w-3xl flex-1 px-6 py-8">
      <GuideContent v-if="guideReady" />
      <Skeleton v-else variant="cards" :count="3" />
    </main>
  </PublicLayout>
</template>
