<script setup>
// Placeholder de carga (skeleton) reutilizable. Tres formas:
//   table → cabecera + filas (listas/tablas)
//   lines → líneas de texto (detalle)
//   cards → rejilla de bloques (tarjetas/gráficos)
// Usa bg-slate-200, que se invierte bajo `.dark` (ver style.css).
//
// APARICIÓN RETRASADA (~180 ms): invisible al montar; si la respuesta llega
// antes, el esqueleto nunca llega a verse — evita el «flashazo» en cargas
// rápidas (tablas vacías, servidor cercano) sin tocar las vistas que lo usan.
// El pulso vive en la MISMA declaración (km-skeleton) en vez de usar la
// utility `animate-pulse`: dos clases que fijan `animation` se pisarían.
defineProps({
  variant: { type: String, default: 'table' }, // 'table' | 'lines' | 'cards'
  rows: { type: Number, default: 5 },
  lines: { type: Number, default: 4 },
  count: { type: Number, default: 4 },
})
</script>

<template>
  <div v-if="variant === 'table'" class="km-skeleton p-4" role="status" :aria-label="$t('common.loading')">
    <div class="mb-4 h-4 w-1/3 rounded bg-slate-200"></div>
    <div class="space-y-3">
      <div v-for="i in rows" :key="i" class="flex items-center gap-4">
        <div class="h-4 flex-1 rounded bg-slate-200"></div>
        <div class="hidden h-4 w-1/4 rounded bg-slate-200 sm:block"></div>
        <div class="h-4 w-16 rounded bg-slate-200"></div>
      </div>
    </div>
  </div>

  <div v-else-if="variant === 'lines'" class="km-skeleton space-y-3" role="status" :aria-label="$t('common.loading')">
    <div
      v-for="i in lines"
      :key="i"
      class="h-4 rounded bg-slate-200"
      :class="i % 3 === 0 ? 'w-2/3' : 'w-full'"
    ></div>
  </div>

  <div v-else class="km-skeleton grid gap-4 sm:grid-cols-2" role="status" :aria-label="$t('common.loading')">
    <div v-for="i in count" :key="i" class="h-40 rounded-xl bg-slate-200"></div>
  </div>
</template>

<style scoped>
/* Dos animaciones sobre opacity: mientras ambas están activas gana la ÚLTIMA
   de la lista (la aparición). Primeros ~180 ms: opacity 0 (delay + fill
   backwards); luego fundido de 150 ms; al terminar, el pulso queda solo. */
.km-skeleton {
  animation:
    km-skeleton-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite,
    km-skeleton-appear 0.15s ease-out 0.18s backwards;
}
@keyframes km-skeleton-pulse {
  50% { opacity: 0.5; }
}
@keyframes km-skeleton-appear {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
