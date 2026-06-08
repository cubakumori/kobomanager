<script setup>
import { ref, computed, useId } from 'vue'
import { useDialogA11y } from '../composables/dialogA11y'

const props = defineProps({
  title: { type: String, default: '' },
  size: { type: String, default: 'md' }, // sm | md | lg | xl
})
const emit = defineEmits(['close'])

const panel = ref(null)
const titleId = useId()

const maxWidth = computed(
  () => ({ sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-2xl' })[props.size] || 'max-w-md',
)

// Cerrar con Escape, focus trap y gestión del foco al abrir/cerrar.
useDialogA11y(panel, () => emit('close'))
</script>

<template>
  <div
    class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4"
    @click.self="$emit('close')"
  >
    <div
      ref="panel"
      :class="['flex max-h-[calc(100dvh-2rem)] w-full flex-col rounded-2xl bg-white shadow-xl', maxWidth]"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="title ? titleId : undefined"
      tabindex="-1"
    >
      <div class="flex shrink-0 items-center justify-between px-6 pb-4 pt-6">
        <h3 :id="titleId" class="text-lg font-semibold text-slate-900">{{ title }}</h3>
        <button
          type="button"
          class="text-xl leading-none text-slate-400 hover:text-slate-600"
          :aria-label="$t('common.cancel')"
          @click="$emit('close')"
        >
          &times;
        </button>
      </div>
      <!-- Cuerpo desplazable: el panel nunca rebasa la pantalla; si el contenido es
           más alto que el viewport, este contenedor hace scroll (footer incluido). -->
      <div class="min-h-0 flex-1 overflow-y-auto px-6 pb-6">
        <slot />
      </div>
    </div>
  </div>
</template>
