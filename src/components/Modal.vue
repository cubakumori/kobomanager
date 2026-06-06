<script setup>
import { ref, useId } from 'vue'
import { useDialogA11y } from '../composables/dialogA11y'

defineProps({ title: { type: String, default: '' } })
const emit = defineEmits(['close'])

const panel = ref(null)
const titleId = useId()

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
      class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="title ? titleId : undefined"
      tabindex="-1"
    >
      <div class="mb-4 flex items-center justify-between">
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
      <slot />
    </div>
  </div>
</template>
