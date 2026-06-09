<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDarkMode } from '../composables/darkMode'

// Botón que cicla claro → oscuro → auto. `variant`: 'light' para barras claras
// (cabecera pública), 'dark' para superficies oscuras (sidebar, drawer).
const props = defineProps({
  variant: { type: String, default: 'light' },
})

const { t } = useI18n()
const { pref, cycle } = useDarkMode()

const label = computed(() => t('common.theme') + ': ' + t('common.theme_' + pref.value))
const btnClass = computed(() =>
  props.variant === 'dark'
    ? 'rounded-lg p-2 text-slate-300 hover:bg-slate-700/60 hover:text-white'
    : 'rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900',
)
</script>

<template>
  <button type="button" :class="btnClass" :title="label" :aria-label="label" @click="cycle">
    <!-- Sol (claro) -->
    <svg v-if="pref === 'light'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
      <circle cx="12" cy="12" r="4" />
      <path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
    </svg>
    <!-- Luna (oscuro) -->
    <svg v-else-if="pref === 'dark'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
      <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" />
    </svg>
    <!-- Auto (sol/luna partido) -->
    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
      <circle cx="12" cy="12" r="9" />
      <path stroke-linecap="round" d="M12 3v18M12 7a5 5 0 0 1 0 10" fill="none" />
      <path d="M12 7a5 5 0 0 0 0 10Z" fill="currentColor" stroke="none" />
    </svg>
  </button>
</template>
