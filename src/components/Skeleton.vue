<script setup>
// Placeholder de carga (skeleton) reutilizable. Tres formas:
//   table → cabecera + filas (listas/tablas)
//   lines → líneas de texto (detalle)
//   cards → rejilla de bloques (tarjetas/gráficos)
// Usa bg-slate-200, que se invierte bajo `.dark` (ver style.css).
defineProps({
  variant: { type: String, default: 'table' }, // 'table' | 'lines' | 'cards'
  rows: { type: Number, default: 5 },
  lines: { type: Number, default: 4 },
  count: { type: Number, default: 4 },
})
</script>

<template>
  <div v-if="variant === 'table'" class="animate-pulse p-4" role="status" :aria-label="$t('common.loading')">
    <div class="mb-4 h-4 w-1/3 rounded bg-slate-200"></div>
    <div class="space-y-3">
      <div v-for="i in rows" :key="i" class="flex items-center gap-4">
        <div class="h-4 flex-1 rounded bg-slate-200"></div>
        <div class="hidden h-4 w-1/4 rounded bg-slate-200 sm:block"></div>
        <div class="h-4 w-16 rounded bg-slate-200"></div>
      </div>
    </div>
  </div>

  <div v-else-if="variant === 'lines'" class="animate-pulse space-y-3" role="status" :aria-label="$t('common.loading')">
    <div
      v-for="i in lines"
      :key="i"
      class="h-4 rounded bg-slate-200"
      :class="i % 3 === 0 ? 'w-2/3' : 'w-full'"
    ></div>
  </div>

  <div v-else class="grid animate-pulse gap-4 sm:grid-cols-2" role="status" :aria-label="$t('common.loading')">
    <div v-for="i in count" :key="i" class="h-40 rounded-xl bg-slate-200"></div>
  </div>
</template>
