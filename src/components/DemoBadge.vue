<script setup>
import { useDemoMode } from '../composables/appConfig'

/**
 * Pill «DEMO» junto a la marca cuando la instancia corre con DEMO_MODE.
 * Tokens primary (sigue al tema). `variant="dark"` para superficies oscuras
 * fijas (sidebar, drawer), como en ThemeToggle.
 */
defineProps({
  variant: { type: String, default: 'light' }, // 'light' | 'dark'
})

const { demoMode } = useDemoMode()
</script>

<template>
  <span
    v-if="demoMode"
    class="inline-flex select-none items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1"
    :class="variant === 'dark'
      ? 'bg-primary-500/20 text-primary-300 ring-primary-400/40'
      : 'bg-primary-50 text-primary-700 ring-primary-200 dark:bg-primary-900/40 dark:text-primary-300 dark:ring-primary-800'"
    :title="$t('common.demoBadgeTitle')"
  >
    {{ $t('common.demoBadge') }}
  </span>
</template>
