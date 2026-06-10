<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { useDialogA11y } from '../composables/dialogA11y'
import { useAuthStore } from '../stores/auth'

const open = ref(false)
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

// Cerrar sesión desde la barra superior móvil (en escritorio vive en el sidebar).
async function onLogout() {
  await auth.logout()
  router.push('/')
}
// Al navegar, cerrar el sidebar móvil.
watch(() => route.fullPath, () => { open.value = false })

// Accesibilidad del drawer móvil (Escape, focus trap y foco) — solo cuando está abierto.
const drawer = ref(null)
useDialogA11y(drawer, () => { open.value = false }, open)
</script>

<template>
  <div class="flex min-h-screen bg-slate-100 text-slate-800">
    <!-- Backdrop (solo móvil, con el sidebar abierto) -->
    <Transition
      enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-200" leave-to-class="opacity-0"
    >
      <div v-if="open" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="open = false"></div>
    </Transition>

    <!-- Sidebar: fijo (sticky) en pantallas grandes para que acompañe el scroll y no
         deje hueco con contenidos largos; off-canvas (desde la izquierda) en pequeñas. -->
    <div
      ref="drawer"
      class="fixed inset-y-0 left-0 z-40 transition-transform duration-200 lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:translate-x-0 lg:self-start"
      :class="open ? 'translate-x-0' : '-translate-x-full'"
      :role="open ? 'dialog' : null"
      :aria-modal="open ? 'true' : null"
    >
      <AppSidebar @navigate="open = false" />
    </div>

    <!-- Columna de contenido -->
    <div class="flex min-w-0 flex-1 flex-col">
      <!-- Barra superior (solo móvil) — marca a la izquierda y hamburguesa neutra a la
           derecha (control de navegación; el azul se reserva para las acciones). -->
      <header class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
        <RouterLink to="/" class="text-lg font-semibold tracking-tight text-slate-900 transition-colors hover:text-primary-700">KoboManager</RouterLink>
        <div class="flex items-center gap-2">
          <!-- Cerrar sesión: a la izquierda de la hamburguesa (mismo hueco que el
               selector de tema en la portada) -->
          <button
            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900"
            :title="$t('nav.logout')"
            :aria-label="$t('nav.logout')"
            @click="onLogout"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H3" />
            </svg>
          </button>
          <button
            class="km-hamburger"
            aria-label="Menu"
            @click="open = true"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
              <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
        </div>
      </header>

      <main class="flex-1 overflow-y-auto">
        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
          <slot />
        </div>
      </main>
    </div>

    <ConfirmDialog />
  </div>
</template>
