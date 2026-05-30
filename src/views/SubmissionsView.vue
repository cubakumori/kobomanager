<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '../services/api'
import { apiError } from '../stores/auth'
import ReviewBadge from '../components/ReviewBadge.vue'

const route = useRoute()
const formId = computed(() => Number(route.params.id))

const formName = ref('')
const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = 25
const search = ref('')
const loading = ref(true)
const error = ref('')

// Columnas dinámicas: campos del primer envío que no son metadatos de Kobo (los que empiezan por _).
const columns = computed(() => {
  const first = items.value[0]?.data
  if (!first) return []
  return Object.keys(first).filter((k) => !k.startsWith('_')).slice(0, 4)
})

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/forms/${formId.value}/submissions`, {
      params: { page: page.value, per_page: perPage, search: search.value || undefined },
    })
    formName.value = data.data.form.name
    items.value = data.data.items
    total.value = data.data.total
  } catch (e) {
    error.value = apiError(e, 'No se pudieron cargar los envíos')
  } finally {
    loading.value = false
  }
}

let searchTimer
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

function go(p) {
  if (p < 1 || p > totalPages.value) return
  page.value = p
  load()
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <RouterLink :to="{ name: 'forms' }" class="text-sm text-blue-600 hover:underline">
        ← Mis formularios
      </RouterLink>
      <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{{ formName || 'Envíos' }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ total }} envío(s) en total.</p>
    </header>

    <input
      v-model="search"
      type="search"
      placeholder="Buscar…"
      class="w-full max-w-xs rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
    />

    <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
      {{ error }}
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div v-if="loading" class="p-4 text-sm text-slate-500">Cargando…</div>
      <table v-else class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <th class="px-4 py-3">Fecha</th>
            <th v-for="c in columns" :key="c" class="px-4 py-3">{{ c }}</th>
            <th class="px-4 py-3">Revisión</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="s in items" :key="s.submission_uid" class="hover:bg-slate-50">
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ s.submitted_at }}</td>
            <td v-for="c in columns" :key="c" class="px-4 py-3 text-slate-700">{{ s.data[c] }}</td>
            <td class="px-4 py-3"><ReviewBadge :status="s.review_status" /></td>
            <td class="px-4 py-3 text-right">
              <RouterLink
                :to="{ name: 'submission-detail', params: { id: formId, subId: s.submission_uid } }"
                class="text-sm font-medium text-blue-600 hover:underline"
              >
                Ver
              </RouterLink>
            </td>
          </tr>
          <tr v-if="!items.length">
            <td :colspan="columns.length + 3" class="px-4 py-6 text-center text-slate-400">
              No hay envíos.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <div v-if="totalPages > 1" class="flex items-center justify-between text-sm">
      <button
        class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-50"
        :disabled="page <= 1"
        @click="go(page - 1)"
      >
        Anterior
      </button>
      <span class="text-slate-500">Página {{ page }} de {{ totalPages }}</span>
      <button
        class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-50"
        :disabled="page >= totalPages"
        @click="go(page + 1)"
      >
        Siguiente
      </button>
    </div>
  </div>
</template>
