<script setup>
import { ref } from 'vue'
import { useDemoMode } from '../composables/appConfig'
import DemoModal from './DemoModal.vue'

/**
 * Badge «DEMO» junto a la marca cuando la instancia corre con DEMO_MODE.
 * Es un BOTÓN independiente del enlace de la marca: al pulsarlo abre el
 * modal informativo de la demo (limitaciones, reset y datos de acceso).
 * Tokens primary (sigue al tema). `variant="dark"` para superficies oscuras
 * fijas (sidebar, drawer), como en ThemeToggle.
 */
defineProps({
  variant: { type: String, default: 'light' }, // 'light' | 'dark'
})

const { demoMode } = useDemoMode()
const showInfo = ref(false)
</script>

<template>
  <button
    v-if="demoMode"
    type="button"
    class="inline-flex select-none items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 transition-colors"
    :class="variant === 'dark'
      ? 'bg-primary-500/20 text-primary-300 ring-primary-400/40 hover:bg-primary-500/35'
      : 'bg-primary-50 text-primary-700 ring-primary-200 hover:bg-primary-100 dark:bg-primary-900/40 dark:text-primary-300 dark:ring-primary-800 dark:hover:bg-primary-900/70'"
    :title="$t('common.demoBadgeTitle')"
    :aria-label="$t('common.demoBadgeTitle')"
    @click.stop.prevent="showInfo = true"
  >
    {{ $t('common.demoBadge') }}
  </button>
  <DemoModal v-if="showInfo" @close="showInfo = false" />
</template>
