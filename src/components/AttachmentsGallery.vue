<script setup>
import { computed } from 'vue'

/**
 * Galería de adjuntos agrupada por tipo (Imágenes / Audio / Vídeo /
 * Documentos·PDF / Otros). Reutilizada por el detalle autenticado y por la vista
 * pública de un enlace compartido. No conoce las URLs: el padre pasa `urlFor`,
 * que construye la URL del proxy correspondiente (autenticado o público con
 * ticket) a partir del adjunto.
 */
const props = defineProps({
  attachments: { type: Array, default: () => [] },
  urlFor: { type: Function, required: true },
})

// Orden fijo de los grupos; cada adjunto cae en su `kind`.
const ORDER = ['image', 'audio', 'video', 'document', 'file']

const groups = computed(() => {
  const buckets = {}
  for (const a of props.attachments) {
    const k = ORDER.includes(a.kind) ? a.kind : 'file'
    ;(buckets[k] ||= []).push(a)
  }
  return ORDER.filter((k) => buckets[k]?.length).map((k) => ({ kind: k, items: buckets[k] }))
})
</script>

<template>
  <div v-if="attachments.length" class="space-y-5">
    <div v-for="g in groups" :key="g.kind">
      <h3 class="mb-2 text-sm font-semibold text-slate-600">
        {{ $t(`attachments.group.${g.kind}`) }} ({{ g.items.length }})
      </h3>
      <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <li v-for="a in g.items" :key="a.uid" class="space-y-2">
          <a v-if="g.kind === 'image'" :href="urlFor(a)" target="_blank" rel="noopener" class="block">
            <img :src="urlFor(a)" :alt="a.name" class="max-h-48 w-full rounded-lg object-contain ring-1 ring-slate-200" />
          </a>
          <audio v-else-if="g.kind === 'audio'" :src="urlFor(a)" controls class="w-full"></audio>
          <video v-else-if="g.kind === 'video'" :src="urlFor(a)" controls class="max-h-64 w-full rounded-lg ring-1 ring-slate-200"></video>
          <a
            v-else
            :href="urlFor(a)"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline"
          >⬇ {{ a.name }}</a>
          <p class="truncate text-xs text-slate-400" :title="a.name">{{ a.name }}</p>
        </li>
      </ul>
    </div>
  </div>
</template>
