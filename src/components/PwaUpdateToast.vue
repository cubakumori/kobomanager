<script setup>
// Aviso «hay una versión nueva» de la PWA. En modo 'prompt' (vite.config), cuando el
// service worker detecta un build nuevo queda EN ESPERA y `needRefresh` se pone a true;
// mostramos este toast. «Recargar» llama a updateServiceWorker(), que envía SKIP_WAITING
// al SW (ver src/sw.js), toma el control y recarga con el código nuevo. «Ahora no» solo
// oculta el aviso: el SW sigue en espera y volverá a ofrecerse al recargar por su cuenta.
import { ref } from 'vue'
import { useRegisterSW } from 'virtual:pwa-register/vue'

const { needRefresh, updateServiceWorker } = useRegisterSW()

const reloading = ref(false)
async function reload() {
  reloading.value = true
  await updateServiceWorker() // envía SKIP_WAITING y recarga la página
}
function dismiss() {
  needRefresh.value = false
}
</script>

<template>
  <Transition name="km-toast">
    <div
      v-if="needRefresh"
      class="fixed inset-x-0 bottom-4 z-[70] mx-auto flex max-w-md items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-lg ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
      style="width: calc(100% - 2rem)"
      role="status"
    >
      <span class="min-w-0 flex-1 text-sm text-slate-700 dark:text-slate-200">{{ $t('common.pwaUpdateBody') }}</span>
      <button
        type="button"
        class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-700"
        @click="dismiss"
      >
        {{ $t('common.pwaUpdateDismiss') }}
      </button>
      <button
        type="button"
        :disabled="reloading"
        class="shrink-0 rounded-lg bg-primary-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
        @click="reload"
      >
        {{ $t('common.pwaUpdateReload') }}
      </button>
    </div>
  </Transition>
</template>

<style scoped>
.km-toast-enter-active,
.km-toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.km-toast-enter-from,
.km-toast-leave-to {
  opacity: 0;
  transform: translateY(0.5rem);
}
</style>
