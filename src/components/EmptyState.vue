<script setup>
/**
 * Estado vacío orientativo: en vez de una línea gris tipo «—», un bloque con
 * título, explicación y (opcional) una acción. Pensado para las primeras
 * visitas — una instancia recién instalada o un viewer sin permisos deben
 * saber QUÉ hacer a continuación, no solo que «no hay datos».
 */
defineProps({
  title: { type: String, required: true },
  body: { type: String, default: '' },
})
</script>

<template>
  <div class="px-6 py-10 text-center">
    <p class="text-sm font-semibold text-slate-700">{{ title }}</p>
    <p v-if="body" class="mx-auto mt-1 max-w-md text-sm text-slate-400">{{ body }}</p>
    <div v-if="$slots.action" class="mt-4 flex flex-wrap items-center justify-center gap-3">
      <slot name="action" />
    </div>
  </div>
</template>
