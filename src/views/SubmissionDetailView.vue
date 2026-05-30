<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import ReviewBadge from '../components/ReviewBadge.vue'

const route = useRoute()
const sub = ref(null)
const loading = ref(true)
const error = ref('')

// Campos del envío separados en "datos" y "metadatos de Kobo" (los que empiezan por _).
const fields = computed(() => {
  const d = sub.value?.data ?? {}
  const data = [], meta = []
  for (const [k, v] of Object.entries(d)) {
    ;(k.startsWith('_') ? meta : data).push([k, v])
  }
  return { data, meta }
})

function fmt(v) {
  return v !== null && typeof v === 'object' ? JSON.stringify(v) : String(v ?? '')
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/submissions/${route.params.subId}`)
    sub.value = data.data
  } catch (e) {
    error.value = apiError(e, 'No se pudo cargar el envío')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <RouterLink
        :to="{ name: 'submissions', params: { id: route.params.id } }"
        class="text-sm text-blue-600 hover:underline"
      >
        ← Volver a los envíos
      </RouterLink>
      <div class="mt-1 flex items-center gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Detalle del envío</h1>
        <ReviewBadge v-if="sub" :status="sub.review_status" />
      </div>
      <p v-if="sub" class="mt-1 text-sm text-slate-500">
        {{ sub.form.name }} · enviado {{ sub.submitted_at }}
      </p>
    </header>

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>
    <div v-else-if="loading" class="text-sm text-slate-500">Cargando…</div>

    <template v-else-if="sub">
      <!-- Datos -->
      <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">Datos</h2>
        <dl class="divide-y divide-slate-100">
          <div v-for="[k, v] in fields.data" :key="k" class="grid grid-cols-3 gap-4 px-5 py-3">
            <dt class="text-sm font-medium text-slate-500">{{ k }}</dt>
            <dd class="col-span-2 text-sm text-slate-800">{{ fmt(v) }}</dd>
          </div>
          <div v-if="!fields.data.length" class="px-5 py-3 text-sm text-slate-400">Sin campos.</div>
        </dl>
      </section>

      <!-- Metadatos -->
      <details class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <summary class="cursor-pointer px-5 py-3 font-semibold text-slate-900">
          Metadatos de Kobo ({{ fields.meta.length }})
        </summary>
        <dl class="divide-y divide-slate-100 border-t border-slate-100">
          <div v-for="[k, v] in fields.meta" :key="k" class="grid grid-cols-3 gap-4 px-5 py-2">
            <dt class="text-xs font-medium text-slate-400">{{ k }}</dt>
            <dd class="col-span-2 break-all text-xs text-slate-600">{{ fmt(v) }}</dd>
          </div>
        </dl>
      </details>

      <!-- Historial de revisiones -->
      <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">
          Historial de revisión
        </h2>
        <ul v-if="sub.reviews.length" class="divide-y divide-slate-100">
          <li v-for="r in sub.reviews" :key="r.id" class="flex items-start gap-3 px-5 py-3">
            <ReviewBadge :status="r.status" />
            <div class="text-sm">
              <p class="text-slate-700">{{ r.comment || '—' }}</p>
              <p class="text-xs text-slate-400">{{ r.user_name }} · {{ r.created_at }}</p>
            </div>
          </li>
        </ul>
        <p v-else class="px-5 py-3 text-sm text-slate-400">Aún no hay revisiones.</p>
      </section>
    </template>
  </div>
</template>
