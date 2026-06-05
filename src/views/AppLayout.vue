<script setup>
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'

const open = ref(false)
const route = useRoute()
// Al navegar, cerrar el sidebar móvil.
watch(() => route.fullPath, () => { open.value = false })
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
      class="fixed inset-y-0 left-0 z-40 transition-transform duration-200 lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:translate-x-0 lg:self-start"
      :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
      <AppSidebar @navigate="open = false" />
    </div>

    <!-- Columna de contenido -->
    <div class="flex min-w-0 flex-1 flex-col">
      <!-- Barra superior (solo móvil) — mismo estilo que la portada: marca a la
           izquierda y hamburguesa azul a la derecha (el sidebar se abre desde la izquierda). -->
      <header class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
        <span class="text-lg font-semibold tracking-tight text-slate-900">KoboManager</span>
        <button
          class="rounded-lg bg-blue-600 p-2 text-white shadow-sm hover:bg-blue-700"
          aria-label="Menu"
          @click="open = true"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
            <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
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
